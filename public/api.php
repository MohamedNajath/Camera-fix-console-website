<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/tools/RegisterWorkbookUpdater.php';

const APP_FIELDS = ['id','channel','place','category','organization','chCategory','camType','model','ip','lon','lat','swVer','fw','integrator','remark','issues','status','activity','siContactName','siContactMobile','siContactEmail'];
const APP_ISSUES = ['Require Fine Tuning','Require Appropriate Backlight Option','Require Zoom Out','Require Appropriate Min Size','Require Zoom In','Require Tilt Down','Require Tilt Up','Pin Hole Cam','Require Appropriate Detection Area','Require Tilt Left','Require Tilt Right','Offline','Temporarily camera removed','Straight the Camera','FIX CAMERA ALLIGNMENT','REMOVE TARGET BOX OVERLAY','LAST CAPTURE ON 06-09,CHECK CAMERA','CAPTURES MISSING','CHECK CAMERA HEIGHT IS AS INSTRUCTED','fix camera alignment','CAPTURES ARE NOT RECOGNISABLE FIX THE ISSUE','REMOVE OBSTRUCTION','FIX TARGET AREA','OBSTRUCTION INFRONT OF CAMERA','FIX THE TARGET AREA','CHECK THE CAMERA HEIGHT IS AS INSTRUCTED','fix camera alignment,captures missing','CHECK CAMERA ALIGNMENT','NO CAPTURES TILL NOW','LAST CAPTURE IS ON 02-09','CAPTURES ARE NOT CLEAR,CLEAN THE LENS','CLEAN THE LENS','SOME REFLECTIONS SEEING IN CAMERA,CLEAR IT'];

final class ApiError extends RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

require_once dirname(__DIR__) . '/tools/SourceWorkbookImporter.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1000000) throw new ApiError(413, 'Request too large');
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new ApiError(400, 'Invalid JSON body');
    return $data;
}

function uploaded_workbook_path(): string
{
    $file = $_FILES['workbook'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ApiError(400, 'Select an Excel workbook to import.');
    if ((int)($file['size'] ?? 0) > 20000000) throw new ApiError(413, 'Workbook uploads are limited to 20 MB.');
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') throw new ApiError(400, 'Choose an .xlsx workbook.');
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new ApiError(400, 'Workbook upload did not complete.');
    return (string)$file['tmp_name'];
}

function clip_value(mixed $value, int $limit): string
{
    $text = trim((string)($value ?? ''));
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

function lower_key(string $value): string
{
    $value = $value !== '' ? $value : '(Unnamed site)';
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function encode_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
}

function decode_json(mixed $value, array $fallback = []): array
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function public_user(array $user): array
{
    return ['id' => $user['id'], 'username' => $user['username'], 'name' => $user['name'], 'role' => $user['role']];
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, username, name, role, password_hash, session_version FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user && (int)($user['session_version'] ?? 0) !== (int)($_SESSION['session_version'] ?? -1)) {
        unset($_SESSION['user_id'], $_SESSION['session_version']);
        $user = null;
    }
    if (!$user) unset($_SESSION['user_id']);
    return $user ?: null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) throw new ApiError(401, 'Please sign in');
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') throw new ApiError(403, 'Admin only');
    return $user;
}

function require_admin_or_reviewer(): array
{
    $user = require_user();
    if (!in_array($user['role'], ['admin', 'reviewer'], true)) throw new ApiError(403, 'Admin or fix reviewer only');
    return $user;
}

function user_can_access(array $user, array $camera): bool
{
    if (in_array($user['role'], ['admin', 'reviewer'], true)) return true;
    $stmt = db()->prepare('SELECT 1 FROM assignments WHERE site_key = ? AND user_id = ?');
    $stmt->execute([lower_key((string)$camera['place']), $user['id']]);
    return (bool)$stmt->fetchColumn();
}

function camera_json_row(array $camera): array
{
    $camera['issues'] = decode_json($camera['issues'] ?? '[]');
    $camera['activity'] = decode_json($camera['activity'] ?? '[]');
    $out = [];
    foreach (APP_FIELDS as $field) $out[] = $camera[$field] ?? '';
    return $out;
}

function camera_values(array $row): array
{
    $out = [];
    foreach (APP_FIELDS as $i => $field) {
        $out[$field] = in_array($field, ['issues', 'activity'], true) ? encode_json($row[$i] ?? []) : (string)($row[$i] ?? '');
    }
    return $out;
}

function camera_by_id(string $id, bool $lock = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM cameras WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $camera = $stmt->fetch();
    return $camera ?: null;
}

function revision_change(): array
{
    $pdo = db();
    $previous = (int)$pdo->query('SELECT revision FROM app_meta WHERE id = 1')->fetchColumn();
    $pdo->exec('UPDATE app_meta SET revision = revision + 1 WHERE id = 1');
    return ['rev' => $previous + 1, 'prevRev' => $previous];
}

function unread_count(array $user): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$user['id']]);
    return (int)$stmt->fetchColumn();
}

function is_pending(array $camera): bool
{
    $issues = decode_json($camera['issues'] ?? '[]');
    $activity = decode_json($camera['activity'] ?? '[]');
    $last = $activity ? end($activity) : null;
    return ($camera['status'] ?? '') !== 'OK' && count($issues) > 0 && is_array($last) && ($last['a'] ?? '') === 'SI_FIXED';
}

function clean_issues(mixed $issues): array
{
    if (!is_array($issues)) return [];
    return array_values(array_unique(array_filter($issues, fn($issue) => is_string($issue) && in_array($issue, APP_ISSUES, true))));
}

function mask_ip_in_text(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') return '';
    $masked = preg_replace('/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/', '-XXX.XXX.$3.$4', $text);
    return $masked === null ? $text : $masked;
}

function build_camera(array $body, ?array $existing): array
{
    $place = clip_value($body['place'] ?? '', 200);
    $channel = mask_ip_in_text(clip_value($body['channel'] ?? '', 300));
    if ($place === '' || $channel === '') throw new ApiError(400, 'Site name and channel name are required');
    $row = $existing ? camera_json_row($existing) : array_fill(0, count(APP_FIELDS), '');
    $idx = array_flip(APP_FIELDS);
    if (!$existing) {
        $row[$idx['id']] = 'NEW-' . (int)(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3));
        $row[$idx['activity']] = [];
    }
    $issues = clean_issues($body['issues'] ?? []);
    $row[$idx['place']] = $place;
    $row[$idx['category']] = clip_value($body['category'] ?? '', 200);
    $row[$idx['channel']] = $channel;
    $row[$idx['ip']] = mask_ip_in_text($row[$idx['ip']]);
    $row[$idx['organization']] = $row[$idx['category']] !== '' ? $place . '/' . $row[$idx['category']] . '/POI' : $place . '/POI';
    $row[$idx['chCategory']] = clip_value($body['chCategory'] ?? '', 40);
    $row[$idx['camType']] = clip_value($body['camType'] ?? '', 100);
    $row[$idx['model']] = clip_value($body['model'] ?? '', 100);
    $row[$idx['ip']] = clip_value($body['ip'] ?? '', 64);
    $row[$idx['fw']] = clip_value($body['fw'] ?? '', 100);
    $row[$idx['swVer']] = clip_value($body['swVer'] ?? '', 100);
    $row[$idx['integrator']] = clip_value($body['integrator'] ?? '', 100);
    $row[$idx['remark']] = clip_value($body['remark'] ?? '', 500);
    $row[$idx['issues']] = $issues;
    $row[$idx['status']] = !empty($body['ok']) ? 'OK' : (count($issues) ? 'Needs Fix' : 'No Data');
    return $row;
}

function save_camera(array $row, bool $insert): void
{
    $values = camera_values($row);
    $columns = array_keys($values);
    if ($insert) {
        $sql = 'INSERT INTO cameras (`' . implode('`,`', $columns) . '`) VALUES (:' . implode(',:', $columns) . ')';
    } else {
        $sets = [];
        foreach ($columns as $column) $sets[] = '`' . $column . '` = :' . $column;
        $sql = 'UPDATE cameras SET ' . implode(',', $sets) . ' WHERE id = :where_id';
        $values['where_id'] = $values['id'];
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
}

function safe_return_camera(array $row): array
{
    return ['row' => $row];
}

function api_dispatch(): void
{
    $path = (string)($_GET['path'] ?? '');
    if (!str_starts_with($path, '/api/')) throw new ApiError(404, 'Not found');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $body = in_array($method, ['GET', 'HEAD'], true) ? [] : (str_starts_with($contentType, 'application/json') ? body_json() : []);
    if (!in_array($method, ['GET', 'HEAD'], true) && isset($_SERVER['HTTP_ORIGIN'])) {
        $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        $requestHost = explode(':', $_SERVER['HTTP_HOST'] ?? '', 2)[0];
        if (!$originHost || strcasecmp($originHost, $requestHost) !== 0) throw new ApiError(403, 'Bad origin');
    }
    $pdo = db();

    if ($method === 'POST' && $path === '/api/login') {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '?';
        $ipHash = hash('sha256', $remoteIp);
        $attemptStmt = $pdo->prepare('SELECT attempt_count, window_started, blocked_until FROM login_attempts WHERE ip_hash = ?');
        $attemptStmt->execute([$ipHash]);
        $attempt = $attemptStmt->fetch();
        $now = time();
        if ($attempt && (int)$attempt['blocked_until'] > $now) throw new ApiError(429, 'Too many attempts. Wait a minute and try again.');
        $username = strtolower(clip_value($body['username'] ?? '', 64));
        $password = substr((string)($body['password'] ?? ''), 0, 200);
        $stmt = $pdo->prepare('SELECT id, username, name, role, password_hash, session_version FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $valid = $user && password_verify($password, $user['password_hash']);
        if (!$valid) {
            $count = $attempt && $now - (int)$attempt['window_started'] < 60 ? (int)$attempt['attempt_count'] + 1 : 1;
            $start = $attempt && $now - (int)$attempt['window_started'] < 60 ? (int)$attempt['window_started'] : $now;
            $blocked = $count >= 5 ? $now + 60 : 0;
            $save = $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempt_count, window_started, blocked_until) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE attempt_count = VALUES(attempt_count), window_started = VALUES(window_started), blocked_until = VALUES(blocked_until)');
            $save->execute([$ipHash, $count, $start, $blocked]);
            throw new ApiError(401, 'Wrong username or password');
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['session_version'] = (int)$user['session_version'];
        json_response(['user' => public_user($user)]);
    }

    if ($method === 'POST' && $path === '/api/logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        session_destroy();
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/me') {
        $user = require_user();
        json_response(['user' => public_user($user)]);
    }

    if ($method === 'POST' && $path === '/api/me/password') {
        $user = require_user();
        if (!password_verify((string)($body['current'] ?? ''), $user['password_hash'])) throw new ApiError(400, 'Current password is wrong');
        $next = (string)($body['next'] ?? '');
        if (strlen($next) < 8 || strlen($next) > 128) throw new ApiError(400, 'New password must be 8–128 characters');
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/data') {
        $user = require_user();
        if (in_array($user['role'], ['admin', 'reviewer'], true)) {
            $stmt = $pdo->query('SELECT * FROM cameras ORDER BY sort_id');
        } else {
            $stmt = $pdo->prepare("SELECT c.* FROM cameras c JOIN assignments a ON a.site_key = LOWER(COALESCE(NULLIF(c.place, ''), '(Unnamed site)')) AND a.user_id = ? ORDER BY c.sort_id");
            $stmt->execute([$user['id']]);
        }
        $rows = [];
        while ($camera = $stmt->fetch()) $rows[] = camera_json_row($camera);
        $out = [
            'fields' => APP_FIELDS,
            'rows' => $rows,
            'rev' => (int)$pdo->query('SELECT revision FROM app_meta WHERE id = 1')->fetchColumn(),
            'me' => public_user($user),
        ];
        if (in_array($user['role'], ['admin', 'reviewer'], true)) {
            $out['assignments'] = [];
            foreach ($pdo->query('SELECT site_key, user_id FROM assignments')->fetchAll() as $assignment) $out['assignments'][$assignment['site_key']] = $assignment['user_id'];
            $userQuery = $user['role'] === 'admin'
                ? "SELECT id, username, name, role FROM users WHERE role IN ('si', 'reviewer') ORDER BY role, name"
                : "SELECT id, username, name, role FROM users WHERE role = 'si' ORDER BY name";
            $out['users'] = array_map('public_user', $pdo->query($userQuery)->fetchAll());
        }
        json_response($out);
    }

    if ($method === 'POST' && $path === '/api/workbook/import-preview') {
        require_admin_or_reviewer();
        $prepared = SourceWorkbookImporter::prepare($pdo, uploaded_workbook_path());
        json_response(SourceWorkbookImporter::previewPrepared($prepared));
    }

    if ($method === 'POST' && $path === '/api/workbook/import') {
        require_admin_or_reviewer();
        $prepared = SourceWorkbookImporter::prepare($pdo, uploaded_workbook_path());
        if (!$prepared['rows']) json_response(SourceWorkbookImporter::previewPrepared($prepared) + ['added' => 0]);
        $workbookStage = RegisterWorkbookUpdater::stageAppend($prepared['rows']);
        $workbookInstall = null;
        $pdo->beginTransaction();
        try {
            $result = SourceWorkbookImporter::insertPrepared($pdo, $prepared);
            $change = revision_change();
            $workbookInstall = RegisterWorkbookUpdater::install($workbookStage);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($workbookInstall !== null) RegisterWorkbookUpdater::restore($workbookInstall);
            else RegisterWorkbookUpdater::discard($workbookStage);
            throw $error;
        }
        RegisterWorkbookUpdater::finalize($workbookInstall);
        json_response(array_merge($result, $change));
    }

    if ($method === 'GET' && $path === '/api/rev') {
        $user = require_user();
        json_response(['rev' => (int)$pdo->query('SELECT revision FROM app_meta WHERE id = 1')->fetchColumn(), 'unread' => unread_count($user)]);
    }

    if ($method === 'GET' && $path === '/api/notifications') {
        $user = require_user();
        $items = [];
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $item) {
            $items[] = ['id' => $item['id'], 'userId' => $item['user_id'], 'cameraId' => $item['camera_id'], 'channel' => $item['channel'], 'site' => $item['site'], 'issues' => decode_json($item['issues']), 'note' => $item['note'], 'by' => $item['by_name'], 'at' => $item['created_at'], 'read' => (bool)$item['is_read']];
        }
        json_response(['items' => $items, 'unread' => unread_count($user)]);
    }

    if ($method === 'POST' && $path === '/api/notifications/dismiss') {
        $user = require_user();
        if (!empty($body['all'])) {
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
            $stmt->execute([$user['id']]);
        } elseif (is_array($body['ids'] ?? null) && count($body['ids'])) {
            $ids = array_slice(array_map('strval', $body['ids']), 0, 200);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND id IN ($marks)");
            $stmt->execute(array_merge([$user['id']], $ids));
        }
        json_response(['unread' => unread_count($user)]);
    }

    if ($method === 'POST' && $path === '/api/users') {
        $admin = require_admin();
        $username = clip_value($body['username'] ?? '', 32);
        $name = clip_value($body['name'] ?? '', 80);
        $password = (string)($body['password'] ?? '');
        $role = (string)($body['role'] ?? 'si');
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) throw new ApiError(400, 'Username: 3–32 letters, numbers, dot, dash or underscore');
        if ($name === '') throw new ApiError(400, 'Name is required');
        if (strlen($password) < 8 || strlen($password) > 128) throw new ApiError(400, 'Password must be 8–128 characters');
        if (!in_array($role, ['si', 'reviewer'], true)) throw new ApiError(400, 'Choose an SI or fix reviewer role');
        $check = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetchColumn()) throw new ApiError(409, 'That username is already taken');
        $id = bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('INSERT INTO users (id, username, name, role, password_hash) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $username, $name, $role, password_hash($password, PASSWORD_DEFAULT)]);
        $change = revision_change();
        json_response(array_merge(['user' => ['id' => $id, 'username' => $username, 'name' => $name, 'role' => $role]], $change));
    }

    if (preg_match('#^/api/users/([a-f0-9]+)(?:/(password))?$#', $path, $match)) {
        require_admin();
        $id = $match[1];
        $isPassword = isset($match[2]) && $match[2] === 'password';
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('si', 'reviewer')");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new ApiError(404, 'User account not found');
        if ($method === 'POST' && $isPassword) {
            $password = (string)($body['password'] ?? '');
            if (strlen($password) < 8 || strlen($password) > 128) throw new ApiError(400, 'Password must be 8–128 characters');
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            json_response(['ok' => true]);
        }
        if ($method === 'DELETE' && !$isPassword) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM assignments WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $change = revision_change();
            $assignments = [];
            foreach ($pdo->query('SELECT site_key, user_id FROM assignments')->fetchAll() as $assignment) $assignments[$assignment['site_key']] = $assignment['user_id'];
            $pdo->commit();
            json_response(array_merge(['ok' => true, 'assignments' => $assignments], $change));
        }
    }

    if ($method === 'POST' && $path === '/api/assign') {
        require_admin_or_reviewer();
        $userId = !empty($body['userId']) ? (string)$body['userId'] : null;
        if ($userId !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'si'");
            $stmt->execute([$userId]);
            if (!$stmt->fetchColumn()) throw new ApiError(400, 'Unknown SI');
        }
        if (!is_array($body['sites'] ?? null) || count($body['sites']) < 1 || count($body['sites']) > 5000) throw new ApiError(400, 'Pick 1–5000 sites');
        $keys = array_values(array_unique(array_map(fn($site) => lower_key((string)$site), $body['sites'])));
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT LOWER(COALESCE(NULLIF(place, ''), '(Unnamed site)')) FROM cameras WHERE LOWER(COALESCE(NULLIF(place, ''), '(Unnamed site)')) IN ($marks)");
        $stmt->execute($keys);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($keys)) throw new ApiError(400, 'Unknown site in request');
        $pdo->beginTransaction();
        foreach ($keys as $key) {
            if ($userId === null) $pdo->prepare('DELETE FROM assignments WHERE site_key = ?')->execute([$key]);
            else $pdo->prepare('INSERT INTO assignments (site_key, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')->execute([$key, $userId]);
        }
        $change = revision_change();
        $assignments = [];
        foreach ($pdo->query('SELECT site_key, user_id FROM assignments')->fetchAll() as $assignment) $assignments[$assignment['site_key']] = $assignment['user_id'];
        $pdo->commit();
        json_response(array_merge(['assignments' => $assignments, 'count' => count($keys)], $change));
    }

    if ($method === 'POST' && $path === '/api/cameras') {
        $user = require_admin_or_reviewer();
        $row = build_camera($body, null);
        if ($user['role'] === 'reviewer') {
            $site = $pdo->prepare("SELECT 1 FROM cameras WHERE LOWER(COALESCE(NULLIF(place, ''), '(Unnamed site)')) = ? LIMIT 1");
            $site->execute([lower_key($row['place'])]);
            if (!$site->fetchColumn()) throw new ApiError(400, 'Fix reviewers can only add cameras to an existing site');
        }
        save_camera($row, true);
        $change = revision_change();
        json_response(array_merge(safe_return_camera($row), $change));
    }

    if (preg_match('#^/api/cameras/([^/]+)(?:/(action))?$#', $path, $match)) {
        $id = rawurldecode($match[1]);
        $isAction = isset($match[2]) && $match[2] === 'action';
        $user = require_user();
        $camera = camera_by_id($id);
        if (!$camera || !user_can_access($user, $camera)) throw new ApiError(404, 'Camera not found');

        if ($method === 'POST' && $isAction) {
            $pdo->beginTransaction();
            $workbookEvent = null;
            $camera = camera_by_id($id, true);
            if (!$camera || !user_can_access($user, $camera)) throw new ApiError(404, 'Camera not found');
            $activity = decode_json($camera['activity']);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if (($body['action'] ?? '') === 'si_fixed') {
                if ($user['role'] !== 'si') throw new ApiError(403, 'Only the SI can mark a camera as fixed');
                if ($camera['status'] === 'OK' || !count(decode_json($camera['issues']))) throw new ApiError(409, 'Nothing to fix on this camera');
                if (is_pending($camera)) throw new ApiError(409, 'Already marked as fixed');
                $note = clip_value($body['note'] ?? '', 300);
                $activity[] = ['a' => 'SI_FIXED', 't' => $now, 'by' => $user['name'], 'username' => $user['username'], 'role' => $user['role'], 'note' => $note];
                $adminIds = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'reviewer')")->fetchAll(PDO::FETCH_COLUMN);
                $notification = $pdo->prepare('INSERT INTO notifications (id, user_id, camera_id, channel, site, issues, note, by_name, created_at, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
                foreach ($adminIds as $adminId) {
                    $notification->execute([bin2hex(random_bytes(6)), $adminId, $camera['id'], $camera['channel'], $camera['place'], encode_json(decode_json($camera['issues'])), $note, $user['name'], $now]);
                }
            } elseif (($body['action'] ?? '') === 'check_ok') {
                if (!in_array($user['role'], ['admin', 'reviewer'], true)) throw new ApiError(403, 'Only an admin or fix reviewer can verify');
                if (!is_pending($camera)) throw new ApiError(409, 'This camera is not waiting for a check');
                $activity[] = ['a' => 'CHECK_OK', 't' => $now, 'by' => $user['name'], 'username' => $user['username'], 'role' => $user['role']];
                $camera['status'] = 'OK';
                $workbookEvent = ['action' => 'verified', 'issues' => [], 'note' => ''];
            } elseif (($body['action'] ?? '') === 'check_notok') {
                if (!in_array($user['role'], ['admin', 'reviewer'], true)) throw new ApiError(403, 'Only an admin or fix reviewer can verify');
                if (!is_pending($camera)) throw new ApiError(409, 'This camera is not waiting for a check');
                $issues = clean_issues($body['issues'] ?? []);
                if (!count($issues)) throw new ApiError(400, 'Select at least one issue');
                $note = clip_value($body['note'] ?? '', 300);
                $activity[] = ['a' => 'CHECK_NOTOK', 't' => $now, 'by' => $user['name'], 'username' => $user['username'], 'role' => $user['role'], 'issues' => $issues, 'note' => $note];
                $workbookEvent = ['action' => 'refix', 'issues' => $issues, 'note' => $note];
                $assign = $pdo->prepare('SELECT user_id FROM assignments WHERE site_key = ?');
                $assign->execute([lower_key((string)$camera['place'])]);
                $siId = $assign->fetchColumn();
                if ($siId) {
                    $notificationId = bin2hex(random_bytes(6));
                    $stmt = $pdo->prepare('INSERT INTO notifications (id, user_id, camera_id, channel, site, issues, note, by_name, created_at, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
                    $stmt->execute([$notificationId, $siId, $camera['id'], $camera['channel'], $camera['place'], encode_json($issues), $note, $user['name'], $now]);
                    $trim = $pdo->prepare('SELECT id FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 200, 10000');
                    $trim->execute([$siId]);
                    $oldIds = $trim->fetchAll(PDO::FETCH_COLUMN);
                    if ($oldIds) {
                        $delMarks = implode(',', array_fill(0, count($oldIds), '?'));
                        $pdo->prepare("DELETE FROM notifications WHERE id IN ($delMarks)")->execute($oldIds);
                    }
                }
            } else {
                throw new ApiError(400, 'Unknown action');
            }
            $camera['activity'] = encode_json($activity);
            $stmt = $pdo->prepare('UPDATE cameras SET status = ?, activity = ? WHERE id = ?');
            $stmt->execute([$camera['status'], $camera['activity'], $id]);
            $workbookStage = null;
            $workbookInstall = null;
            try {
                if ($workbookEvent !== null) {
                    $workbookStage = RegisterWorkbookUpdater::stage($id, $workbookEvent['action'], $now, $workbookEvent['issues'], $workbookEvent['note']);
                }
                $change = revision_change();
                if ($workbookStage !== null) $workbookInstall = RegisterWorkbookUpdater::install($workbookStage);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($workbookInstall !== null) RegisterWorkbookUpdater::restore($workbookInstall);
                if ($workbookStage !== null) RegisterWorkbookUpdater::discard($workbookStage);
                throw $error;
            }
            if ($workbookInstall !== null) RegisterWorkbookUpdater::finalize($workbookInstall);
            json_response(array_merge(safe_return_camera(camera_json_row($camera)), $change));
        }

        if ($method === 'PUT' && !$isAction) {
            if (!in_array($user['role'], ['admin', 'reviewer'], true)) throw new ApiError(403, 'Admin or fix reviewer only');
            $row = build_camera($body, $camera);
            save_camera($row, false);
            $change = revision_change();
            json_response(array_merge(safe_return_camera($row), $change));
        }
        if ($method === 'DELETE' && !$isAction) {
            if ($user['role'] !== 'admin') throw new ApiError(403, 'Admin only');
            $pdo->prepare('DELETE FROM cameras WHERE id = ?')->execute([$id]);
            $change = revision_change();
            json_response(array_merge(['ok' => true], $change));
        }
    }

    throw new ApiError(404, 'Not found');
}

$secure = defined('COOKIE_SECURE') ? COOKIE_SECURE : false;
session_set_cookie_params(['lifetime' => 43200, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
try {
    api_dispatch();
} catch (ApiError $error) {
    if (db()->inTransaction()) db()->rollBack();
    json_response(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Camera Fix Console API error: ' . $error->getMessage());
    if (db()->inTransaction()) db()->rollBack();
    json_response(['error' => 'Server error'], 500);
}
