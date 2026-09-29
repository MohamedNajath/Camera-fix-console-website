<?php
declare(strict_types=1);

$ids = ['11822571293182144', '14357187680798914'];
$path = dirname(__DIR__) . '/source-data/camera-fix-register.xlsx';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::RDONLY) !== true) { fwrite(STDERR, "Workbook locked or unavailable.\n"); exit(1); }
$stringsDoc = new DOMDocument();
$stringsDoc->loadXML((string)$zip->getFromName('xl/sharedStrings.xml'));
$stringsXPath = new DOMXPath($stringsDoc);
$shared = [];
foreach ($stringsXPath->query('//*[local-name()="si"]') as $item) {
    $value = '';
    foreach ($stringsXPath->query('.//*[local-name()="t"]', $item) as $text) $value .= $text->textContent;
    $shared[] = $value;
}
$sheet = new DOMDocument();
$sheet->loadXML((string)$zip->getFromName('xl/worksheets/sheet1.xml'));
$xpath = new DOMXPath($sheet);
$rows = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
foreach ($rows as $row) {
    $cells = [];
    foreach ($xpath->query('./*[local-name()="c"]', $row) as $cell) {
        if (!preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match)) continue;
        $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
        $value = $valueNode ? $valueNode->textContent : '';
        if ($cell->getAttribute('t') === 's' && $value !== '') $value = $shared[(int)$value] ?? '';
        elseif ($cell->getAttribute('t') === 'inlineStr') $value = $xpath->query('./*[local-name()="is"]', $cell)->item(0)?->textContent ?? '';
        $cells[$match[1]] = $value;
    }
    if (in_array($cells['A'] ?? '', $ids, true)) {
        echo 'row ', $row->getAttribute('r'), ': ', json_encode(array_intersect_key($cells, array_flip(['A','D','I','J','K','L','M','N','O','P'])), JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
}
$zip->close();
