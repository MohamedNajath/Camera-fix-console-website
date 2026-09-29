<?php
declare(strict_types=1);

final class SourceWorkbookImporter
{
    private const FIELDS = ['id','channel','place','category','organization','chCategory','camType','model','ip','lon','lat','swVer','fw','integrator','remark','issues','status','activity','siContactName','siContactMobile','siContactEmail'];

    public static function preview(PDO $pdo, ?string $path = null): array
    {
        return self::previewPrepared(self::scan($pdo, $path));
    }

    public static function prepare(PDO $pdo, string $path): array
    {
        return self::scan($pdo, $path);
    }

    public static function previewPrepared(array $scan): array
    {
        return [
            'cameras' => count($scan['rows']),
            'duplicateIds' => $scan['duplicates'],
            'invalidRows' => $scan['invalid'],
            'sites' => $scan['sites'],
        ];
    }

    public static function import(PDO $pdo, ?string $path = null): array
    {
        return self::insertPrepared($pdo, self::scan($pdo, $path));
    }

    public static function insertPrepared(PDO $pdo, array $scan): array
    {
        if (!$scan['rows']) return self::previewResult($scan);

        $columns = self::FIELDS;
        $sql = 'INSERT INTO cameras (`' . implode('`,`', $columns) . '`) VALUES (:' . implode(',:', $columns) . ')';
        $stmt = $pdo->prepare($sql);
        foreach ($scan['rows'] as $row) {
            foreach (['issues', 'activity'] as $jsonField) $row[$jsonField] = json_encode($row[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
            $stmt->execute($row);
        }
        return self::previewResult($scan);
    }

    private static function previewResult(array $scan): array
    {
        return [
            'added' => count($scan['rows']),
            'duplicateIds' => $scan['duplicates'],
            'invalidRows' => $scan['invalid'],
            'sites' => $scan['sites'],
        ];
    }

    private static function scan(PDO $pdo, ?string $path): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new ApiError(503, 'PHP ZIP and XML support are required. Restart Apache after enabling the extensions.');
        }
        $path ??= dirname(__DIR__) . '/source-data/camera-fix-register.xlsx';
        if (!is_file($path) || !is_readable($path)) throw new ApiError(503, 'The source workbook is unavailable.');

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new ApiError(503, 'Could not open the source workbook. Close it in Excel and try again.');
        try {
            $sharedStrings = self::sharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) throw new ApiError(400, 'The workbook is missing its first worksheet.');
            $sheet = new DOMDocument();
            if (!$sheet->loadXML($sheetXml, LIBXML_NONET)) throw new ApiError(400, 'The workbook worksheet is invalid.');
            $xpath = new DOMXPath($sheet);
            $rows = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
            $headerRow = $rows->item(0);
            if (!$headerRow instanceof DOMElement) throw new ApiError(400, 'The workbook has no header row.');
            $headerValues = self::readRow($headerRow, $xpath, $sharedStrings);
            $headers = [];
            $headerNames = [];
            foreach ($headerValues as $column => $name) {
                $normalized = self::normalize($name);
                $headerNames[$column] = $normalized;
                if ($normalized !== '' && !array_key_exists($normalized, $headers)) $headers[$normalized] = $column;
            }

            $idColumn = self::column($headers, ['extremechannelcode', 'id', 'cameraid']);
            $channelColumn = self::column($headers, ['channelname', 'channel']);
            $organizationColumn = self::column($headers, ['organization']);
            if ($idColumn === null || $channelColumn === null || $organizationColumn === null) {
                throw new ApiError(400, 'The workbook must contain Extreme Channel Code, Channel Name, and Organization columns.');
            }

            $existing = array_fill_keys($pdo->query('SELECT id FROM cameras')->fetchAll(PDO::FETCH_COLUMN), true);
            $seen = [];
            $newRows = [];
            $sites = [];
            $duplicates = 0;
            $invalid = 0;
            foreach ($rows as $rowNode) {
                if ((int)$rowNode->getAttribute('r') === (int)$headerRow->getAttribute('r')) continue;
                $cells = self::readRow($rowNode, $xpath, $sharedStrings);
                $id = trim((string)($cells[$idColumn] ?? ''));
                $channel = trim((string)($cells[$channelColumn] ?? ''));
                $organization = trim((string)($cells[$organizationColumn] ?? ''));
                if ($id === '' && $channel === '' && $organization === '') continue;
                if ($id === '' || $channel === '' || $organization === '') { $invalid++; continue; }
                if (isset($existing[$id]) || isset($seen[$id])) { $duplicates++; continue; }
                $organizationParts = array_map('trim', explode('/', $organization));
                $place = trim((string)array_shift($organizationParts));
                if ($place === '') { $invalid++; continue; }
                $seen[$id] = true;
                $category = trim((string)($organizationParts[0] ?? ''));
                if (strcasecmp($category, 'POI') === 0) $category = '';

                $issueValues = [];
                foreach (['comment1', 'comment2', 'comment3', 'comment4'] as $header) {
                    $column = self::column($headers, [$header]);
                    $value = $column === null ? '' : trim((string)($cells[$column] ?? ''));
                    if ($value !== '' && strcasecmp($value, 'OK') !== 0) $issueValues[] = $value;
                }
                $issueValues = array_values(array_unique($issueValues));
                $finalStatusColumn = self::column($headers, ['finalstatus', 'status']);
                $finalStatus = $finalStatusColumn === null ? '' : trim((string)($cells[$finalStatusColumn] ?? ''));
                $status = strcasecmp($finalStatus, 'OK') === 0 ? 'OK' : (count($issueValues) ? 'Needs Fix' : 'No Data');
                $activity = [];
                if ($status === 'OK') {
                    $dateColumn = self::dateAfter($headerNames, $finalStatusColumn);
                    $date = self::excelDate($dateColumn === null ? '' : ($cells[$dateColumn] ?? ''));
                    if ($date !== '') $activity[] = ['a' => 'CHECK_OK', 't' => $date, 'by' => 'Imported from workbook'];
                }

                foreach (['remark2', 'remark3', 'remark4'] as $remarkHeader) {
                    $remarkColumn = self::column($headers, [$remarkHeader]);
                    if ($remarkColumn === null) continue;
                    $remark = trim((string)($cells[$remarkColumn] ?? ''));
                    if ($remark === '') continue;
                    $dateColumn = self::dateAfter($headerNames, $remarkColumn);
                    $date = self::excelDate($dateColumn === null ? '' : ($cells[$dateColumn] ?? ''));
                    $activity[] = ['a' => 'CHECK_NOTOK', 't' => $date, 'by' => 'Imported from workbook', 'issues' => [], 'note' => $remark];
                }

                $newRows[] = [
                    'id' => $id,
                    'channel' => self::value($cells, $headers, ['channelname', 'channel'], 300),
                    'place' => self::clip($place, 200),
                    'category' => self::clip($category, 200),
                    'organization' => self::clip($organization, 500),
                    'chCategory' => self::value($cells, $headers, ['channelcategory'], 40),
                    'camType' => self::value($cells, $headers, ['cameratype', 'type'], 100),
                    'model' => self::value($cells, $headers, ['model'], 100),
                    'ip' => self::value($cells, $headers, ['ipaddress', 'ip'], 64),
                    'lon' => self::value($cells, $headers, ['longitude'], 64),
                    'lat' => self::value($cells, $headers, ['latitude'], 64),
                    'swVer' => self::value($cells, $headers, ['softwareversion', 'software'], 100),
                    'fw' => self::value($cells, $headers, ['latustfirmwareversion', 'firmwareversion', 'firmware'], 100),
                    'integrator' => self::value($cells, $headers, ['siname'], 100),
                    'remark' => self::value($cells, $headers, ['remark'], 500),
                    'issues' => $issueValues,
                    'status' => $status,
                    'activity' => $activity,
                    'siContactName' => self::value($cells, $headers, ['sicontactpersonname', 'sicontactname'], 200),
                    'siContactMobile' => self::value($cells, $headers, ['sicontactpersonmobilenumber', 'sicontactmobile'], 100),
                    'siContactEmail' => self::value($cells, $headers, ['emailid', 'sicontactemail'], 200),
                ];
                $sites[$place] = ($sites[$place] ?? 0) + 1;
            }
            return ['rows' => $newRows, 'sites' => $sites, 'duplicates' => $duplicates, 'invalid' => $invalid];
        } finally {
            $zip->close();
        }
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) throw new ApiError(400, 'The workbook shared strings are invalid.');
        $xpath = new DOMXPath($document);
        $strings = [];
        foreach ($xpath->query('//*[local-name()="si"]') as $item) {
            $value = '';
            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $text) $value .= $text->textContent;
            $strings[] = $value;
        }
        return $strings;
    }

    private static function readRow(DOMElement $row, DOMXPath $xpath, array $sharedStrings): array
    {
        $values = [];
        foreach ($xpath->query('./*[local-name()="c"]', $row) as $cell) {
            if (!preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match)) continue;
            $column = self::columnNumber($match[1]);
            $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
            $value = $valueNode ? $valueNode->textContent : '';
            if ($cell->getAttribute('t') === 's' && $value !== '') $value = $sharedStrings[(int)$value] ?? '';
            elseif ($cell->getAttribute('t') === 'inlineStr') $value = $xpath->query('./*[local-name()="is"]', $cell)->item(0)?->textContent ?? '';
            $values[$column] = $value;
        }
        return $values;
    }

    private static function normalize(string $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', trim($value)));
    }

    private static function column(array $headers, array $keys): ?int
    {
        foreach ($keys as $key) if (array_key_exists($key, $headers)) return $headers[$key];
        return null;
    }

    private static function dateAfter(array $headerNames, ?int $column): ?int
    {
        if ($column === null) return null;
        foreach ($headerNames as $candidate => $header) {
            if ($candidate > $column && in_array($header, ['date', 'remark4date'], true)) return $candidate;
        }
        return null;
    }

    private static function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split(strtoupper($letters)) as $letter) $number = $number * 26 + ord($letter) - 64;
        return $number;
    }

    private static function value(array $cells, array $headers, array $names, int $limit): string
    {
        $column = self::column($headers, $names);
        return self::clip($column === null ? '' : (string)($cells[$column] ?? ''), $limit);
    }

    private static function clip(string $value, int $limit): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function excelDate(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (is_numeric($value)) {
            $seconds = (int)round(((float)$value) * 86400);
            return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d\TH:i:s\Z');
        }
        try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
        catch (Throwable) { return ''; }
    }
}
