<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this importer from the command line only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';

$base = getenv('LEGACY_DATA_DIR') ?: dirname(__DIR__) . '/data';
$camerasFile = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'cameras.json';
$stateFile = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'state.json';
if (!is_file($camerasFile) || !is_file($stateFile)) {
    fwrite(STDERR, "Expected cameras.json and state.json in: $base\n");
    exit(1);
}

$camerasData = json_decode((string)file_get_contents($camerasFile), true, 512, JSON_THROW_ON_ERROR);
$stateData = json_decode((string)file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
$fields = ['id','channel','place','category','organization','chCategory','camType','model','ip','lon','lat','swVer','fw','integrator','remark','issues','status','activity','siContactName','siContactMobile','siContactEmail'];
if (!is_array($camerasData['rows'] ?? null)) {
    fwrite(STDERR, "Invalid cameras.json format.\n");
    exit(1);
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((int)$pdo->query('SELECT COUNT(*) FROM cameras')->fetchColumn() !== 0 || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    fwrite(STDERR, "Import stopped: cameras or users already exist in this database.\n");
    exit(1);
}

$json = static fn($value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
$users = $stateData['users'] ?? [];
$credentials = [];
$pdo->beginTransaction();
try {
    $columns = '`' . implode('`,`', $fields) . '`';
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $cameraInsert = $pdo->prepare("INSERT INTO cameras ($columns) VALUES ($placeholders)");
    foreach ($camerasData['rows'] as $sourceRow) {
        $row = [];
        foreach ($fields as $index => $field) {
            $value = $sourceRow[$index] ?? '';
            if (in_array($field, ['issues', 'activity'], true)) $value = $json(is_array($value) ? $value : []);
            else $value = (string)$value;
            $row[] = $value;
        }
        $cameraInsert->execute($row);
    }

    $userInsert = $pdo->prepare('INSERT INTO users (id, username, name, role, password_hash) VALUES (?, ?, ?, ?, ?)');
    $knownUsers = [];
    foreach ($users as $user) {
        if (!in_array($user['role'] ?? '', ['admin', 'si'], true)) continue;
        $id = (string)($user['id'] ?? bin2hex(random_bytes(6)));
        $username = trim((string)($user['username'] ?? ''));
        $name = trim((string)($user['name'] ?? $username));
        if ($username === '' || $name === '') continue;
        $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
        $userInsert->execute([$id, $username, $name, $user['role'], password_hash($temporaryPassword, PASSWORD_DEFAULT)]);
        $knownUsers[$id] = true;
        $credentials[] = [$user['role'], $username, $temporaryPassword];
    }
    if (!array_filter($users, static fn($user) => ($user['role'] ?? '') === 'admin')) {
        throw new RuntimeException('The source state has no admin account; refusing to import without one.');
    }

    $assignmentInsert = $pdo->prepare('INSERT INTO assignments (site_key, user_id) VALUES (?, ?)');
    foreach (($stateData['assignments'] ?? []) as $site => $userId) {
        if (isset($knownUsers[$userId])) {
            $key = function_exists('mb_strtolower') ? mb_strtolower((string)$site, 'UTF-8') : strtolower((string)$site);
            $assignmentInsert->execute([$key, $userId]);
        }
    }

    $notificationInsert = $pdo->prepare('INSERT INTO notifications (id, user_id, camera_id, channel, site, issues, note, by_name, created_at, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (($stateData['notifications'] ?? []) as $item) {
        if (!isset($knownUsers[$item['userId'] ?? ''])) continue;
        $notificationInsert->execute([
            (string)$item['id'], (string)$item['userId'], (string)($item['cameraId'] ?? ''),
            (string)($item['channel'] ?? ''), (string)($item['site'] ?? ''), $json($item['issues'] ?? []),
            (string)($item['note'] ?? ''), (string)($item['by'] ?? ''), (string)($item['at'] ?? gmdate('Y-m-d\\TH:i:s\\Z')),
            !empty($item['read']) ? 1 : 0,
        ]);
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Import failed: " . $error->getMessage() . "\n");
    exit(1);
}

$pdo->query('UPDATE app_meta SET revision = revision + 1 WHERE id = 1');
echo 'Imported ' . count($camerasData['rows']) . " cameras.\n";
echo "Temporary passwords (save these securely; they are not written to files):\n";
foreach ($credentials as [$role, $username, $password]) {
    echo $role . "\t" . $username . "\t" . $password . "\n";
}
