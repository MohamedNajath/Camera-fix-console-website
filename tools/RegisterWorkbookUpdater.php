<?php
declare(strict_types=1);

final class RegisterWorkbookUpdater
{
    public static function stage(string $cameraId, string $action, string $at, array $issues = [], string $note = '', ?string $sourcePath = null): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new ApiError(503, 'Workbook updates are unavailable. Restart Apache to load PHP ZIP and XML support.');
        }

        $source = $sourcePath ?? dirname(__DIR__) . '/source-data/camera-fix-register.xlsx';
        if (!is_file($source) || !is_readable($source) || !is_writable($source)) {
            throw new ApiError(503, 'The source workbook is unavailable or read-only. Close it in Excel and try again.');
        }

        $temporary = $source . '.tmp-' . bin2hex(random_bytes(6)) . '.xlsx';
        if (!copy($source, $temporary)) {
            throw new ApiError(503, 'Could not stage the source workbook. Close it in Excel and try again.');
        }

        $zip = null;
        $zipOpen = false;
        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary) !== true) throw new ApiError(503, 'Could not open the source workbook.');
            $zipOpen = true;

            $sharedStrings = self::readSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) throw new ApiError(503, 'The workbook is missing its first worksheet.');

            $sheet = new DOMDocument();
            $sheet->preserveWhiteSpace = true;
            if (!$sheet->loadXML($sheetXml, LIBXML_NONET)) throw new ApiError(503, 'The workbook worksheet is invalid.');
            $xpath = new DOMXPath($sheet);
            $rows = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
            $headerRow = $rows->item(0);
            if (!$headerRow instanceof DOMElement) throw new ApiError(503, 'The workbook has no header row.');

            $headers = self::readRow($headerRow, $xpath, $sharedStrings);
            $remark4Column = self::findHeader($headers, 'remark4');
            if ($remark4Column === null) throw new ApiError(503, 'The workbook is missing the Remark 4 column.');
            if (self::findDateAfter($headers, $remark4Column) === null) {
                $date4Column = $remark4Column + 1;
                self::insertColumn($sheet, $xpath, $date4Column);
                self::writeCell($sheet, $xpath, $headerRow, $date4Column, 'Remark 4 Date');
                $headers = self::readRow($headerRow, $xpath, $sharedStrings);
            }

            $statusColumn = self::findHeader($headers, 'finalstatus');
            $idColumn = self::findHeader($headers, 'extremechannelcode');
            $remark2Column = self::findHeader($headers, 'remark2');
            $remark3Column = self::findHeader($headers, 'remark3');
            $remark4Column = self::findHeader($headers, 'remark4');
            if ($statusColumn === null || $idColumn === null || $remark2Column === null || $remark3Column === null || $remark4Column === null) {
                throw new ApiError(503, 'The workbook is missing a required status, ID, or remark column.');
            }

            $dateColumns = [
                self::findDateBetween($headers, $statusColumn, $remark2Column),
                self::findDateBetween($headers, $remark2Column, $remark3Column),
                self::findDateBetween($headers, $remark3Column, $remark4Column),
                self::findDateAfter($headers, $remark4Column),
            ];
            if (in_array(null, $dateColumns, true)) throw new ApiError(503, 'The workbook is missing a required date column.');

            $cameraRow = null;
            foreach ($rows as $row) {
                if ((int)$row->getAttribute('r') === (int)$headerRow->getAttribute('r')) continue;
                $cells = self::readRow($row, $xpath, $sharedStrings);
                if (($cells[$idColumn] ?? '') === $cameraId) {
                    $cameraRow = $row;
                    break;
                }
            }
            if (!$cameraRow instanceof DOMElement) throw new ApiError(409, 'Camera ID was not found in the source workbook. No changes were saved.');

            $date = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($action === 'verified') {
                self::writeCell($sheet, $xpath, $cameraRow, $statusColumn, 'OK');
                self::writeCell($sheet, $xpath, $cameraRow, $dateColumns[0], $date);
            } elseif ($action === 'refix') {
                $remark = implode(', ', $issues);
                if ($note !== '') $remark .= ($remark !== '' ? ' - ' : '') . $note;
                if ($remark === '') throw new ApiError(400, 'A refix remark cannot be empty.');

                $remarkColumns = [$remark2Column, $remark3Column, $remark4Column];
                $targetIndex = null;
                foreach ($remarkColumns as $index => $column) {
                    if (trim($cells[$column] ?? '') === '') {
                        $targetIndex = $index;
                        break;
                    }
                }
                if ($targetIndex === null) {
                    $targetIndex = 2;
                    $remarkColumns[$targetIndex] = $remark4Column;
                    $dateColumns[$targetIndex + 1] = $dateColumns[3];
                    $remark = trim(($cells[$remark4Column] ?? '') . "\n" . $remark);
                    $date = trim(($cells[$dateColumns[3]] ?? '') . "\n" . $date);
                }
                self::writeCell($sheet, $xpath, $cameraRow, $remarkColumns[$targetIndex], $remark);
                self::writeCell($sheet, $xpath, $cameraRow, $dateColumns[$targetIndex + 1], $date);
            } else {
                throw new ApiError(400, 'Unsupported workbook update.');
            }

            if (!$zip->addFromString('xl/worksheets/sheet1.xml', $sheet->saveXML())) throw new ApiError(503, 'Could not write the workbook worksheet.');
            if (!$zip->close()) throw new ApiError(503, 'Could not finish writing the workbook.');
            $zipOpen = false;
            return ['source' => $source, 'temporary' => $temporary];
        } catch (Throwable $error) {
            if ($zipOpen && $zip instanceof ZipArchive) $zip->close();
            @unlink($temporary);
            throw $error;
        }
    }

    public static function stageAppend(array $cameras, ?string $sourcePath = null): array
    {
        if (!$cameras) throw new ApiError(400, 'No new cameras to append.');
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new ApiError(503, 'Workbook updates are unavailable. Restart Apache to load PHP ZIP and XML support.');
        }

        $source = $sourcePath ?? dirname(__DIR__) . '/source-data/camera-fix-register.xlsx';
        if (!is_file($source) || !is_readable($source) || !is_writable($source)) {
            throw new ApiError(503, 'The master workbook is unavailable or read-only. Close it in Excel and try again.');
        }
        $temporary = $source . '.tmp-' . bin2hex(random_bytes(6)) . '.xlsx';
        if (!copy($source, $temporary)) throw new ApiError(503, 'Could not stage the master workbook. Close it in Excel and try again.');

        $zip = null;
        $zipOpen = false;
        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary) !== true) throw new ApiError(503, 'Could not open the master workbook.');
            $zipOpen = true;
            $sharedStrings = self::readSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) throw new ApiError(503, 'The master workbook is missing its first worksheet.');

            $sheet = new DOMDocument();
            $sheet->preserveWhiteSpace = true;
            if (!$sheet->loadXML($sheetXml, LIBXML_NONET)) throw new ApiError(503, 'The master worksheet is invalid.');
            $xpath = new DOMXPath($sheet);
            $sheetData = $xpath->query('//*[local-name()="sheetData"]')->item(0);
            $rows = $xpath->query('./*[local-name()="row"]', $sheetData);
            $headerRow = $rows->item(0);
            if (!$headerRow instanceof DOMElement) throw new ApiError(503, 'The master workbook has no header row.');

            $headers = self::readRow($headerRow, $xpath, $sharedStrings);
            $remark4Column = self::findHeader($headers, 'remark4');
            if ($remark4Column === null) throw new ApiError(503, 'The master workbook is missing the Remark 4 column.');
            if (self::findDateAfter($headers, $remark4Column) === null) {
                self::insertColumn($sheet, $xpath, $remark4Column + 1);
                self::writeCell($sheet, $xpath, $headerRow, $remark4Column + 1, 'Remark 4 Date');
                $headers = self::readRow($headerRow, $xpath, $sharedStrings);
            }

            foreach (['extremechannelcode', 'channelname', 'organization', 'finalstatus', 'remark2', 'remark3', 'remark4'] as $required) {
                if (self::findHeader($headers, $required) === null) throw new ApiError(503, 'The master workbook is missing the ' . $required . ' column.');
            }
            $lastRowNumber = 1;
            $lastRow = $headerRow;
            $styleByColumn = [];
            foreach ($rows as $row) {
                $rowNumber = (int)$row->getAttribute('r');
                if ($rowNumber > $lastRowNumber) {
                    $lastRowNumber = $rowNumber;
                    $lastRow = $row;
                }
            }
            foreach ($xpath->query('./*[local-name()="c"][@r]', $lastRow) as $cell) {
                if (preg_match('/^([A-Z]+)\d+$/', $cell->getAttribute('r'), $match) && $cell->hasAttribute('s')) {
                    $styleByColumn[self::columnNumber($match[1])] = $cell->getAttribute('s');
                }
            }

            $rowNumber = $lastRowNumber;
            foreach ($cameras as $camera) {
                $rowNumber++;
                $rowValues = self::cameraWorkbookValues($camera, $headers);
                $newRow = $sheet->createElementNS($sheet->documentElement->namespaceURI, 'row');
                $newRow->setAttribute('r', (string)$rowNumber);
                foreach ($rowValues as $column => $value) {
                    if ($value === '') continue;
                    $cell = $sheet->createElementNS($sheet->documentElement->namespaceURI, 'c');
                    $cell->setAttribute('r', self::columnName($column) . $rowNumber);
                    if (isset($styleByColumn[$column])) $cell->setAttribute('s', $styleByColumn[$column]);
                    $cell->setAttribute('t', 'inlineStr');
                    $inline = $sheet->createElementNS($sheet->documentElement->namespaceURI, 'is');
                    $text = $sheet->createElementNS($sheet->documentElement->namespaceURI, 't');
                    $text->appendChild($sheet->createTextNode($value));
                    $inline->appendChild($text);
                    $cell->appendChild($inline);
                    $newRow->appendChild($cell);
                }
                $sheetData->appendChild($newRow);
            }

            $dimension = $xpath->query('//*[local-name()="dimension"]')->item(0);
            if ($dimension instanceof DOMElement) {
                $reference = $dimension->getAttribute('ref');
                if (preg_match('/^(.+:)?\$?([A-Z]+)\$?\d+$/', $reference, $match)) {
                    $start = isset($match[1]) ? rtrim($match[1], ':') : 'A1';
                    $endColumn = self::columnName(max(array_keys($headers)));
                    $dimension->setAttribute('ref', $start . ':' . $endColumn . $rowNumber);
                }
            }
            $autoFilter = $xpath->query('//*[local-name()="autoFilter"]')->item(0);
            if ($autoFilter instanceof DOMElement && preg_match('/^(.*\D)\d+$/', $autoFilter->getAttribute('ref'), $match)) {
                $autoFilter->setAttribute('ref', $match[1] . $rowNumber);
            }

            if (!$zip->addFromString('xl/worksheets/sheet1.xml', $sheet->saveXML())) throw new ApiError(503, 'Could not append camera rows to the master worksheet.');
            if (!$zip->close()) throw new ApiError(503, 'Could not finish writing the master workbook.');
            $zipOpen = false;
            return ['source' => $source, 'temporary' => $temporary];
        } catch (Throwable $error) {
            if ($zipOpen && $zip instanceof ZipArchive) $zip->close();
            @unlink($temporary);
            throw $error;
        }
    }

    public static function install(array $stage): array
    {
        $backup = $stage['source'] . '.backup-' . bin2hex(random_bytes(6));
        if (!@rename($stage['source'], $backup)) {
            self::discard($stage);
            throw new ApiError(503, 'Could not replace the source workbook. Close it in Excel and try again.');
        }
        if (!@rename($stage['temporary'], $stage['source'])) {
            if (!@rename($backup, $stage['source'])) {
                throw new RuntimeException('Workbook replacement and recovery both failed; backup is at ' . $backup);
            }
            throw new ApiError(503, 'Could not replace the source workbook. The original was restored.');
        }
        return ['source' => $stage['source'], 'backup' => $backup];
    }

    public static function restore(array $installed): void
    {
        if (is_file($installed['source'])) @unlink($installed['source']);
        if (!@rename($installed['backup'], $installed['source'])) {
            throw new RuntimeException('Database commit failed and workbook recovery is at ' . $installed['backup']);
        }
    }

    public static function finalize(array $installed): void
    {
        @unlink($installed['backup']);
    }

    public static function discard(array $stage): void
    {
        if (isset($stage['temporary']) && is_file($stage['temporary'])) @unlink($stage['temporary']);
    }

    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) throw new ApiError(503, 'The workbook shared strings are invalid.');
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
            $reference = $cell->getAttribute('r');
            if (!preg_match('/^([A-Z]+)\d+$/', $reference, $match)) continue;
            $column = self::columnNumber($match[1]);
            $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
            $value = $valueNode ? $valueNode->textContent : '';
            if ($cell->getAttribute('t') === 's' && $value !== '') $value = $sharedStrings[(int)$value] ?? '';
            elseif ($cell->getAttribute('t') === 'inlineStr') $value = $xpath->query('./*[local-name()="is"]', $cell)->item(0)?->textContent ?? '';
            $values[$column] = $value;
        }
        return $values;
    }

    private static function normalizeHeader(string $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', trim($value)));
    }

    private static function findHeader(array $headers, string $name): ?int
    {
        foreach ($headers as $column => $value) {
            if (self::normalizeHeader($value) === $name) return $column;
        }
        return null;
    }

    private static function findDateBetween(array $headers, int $start, int $end): ?int
    {
        foreach ($headers as $column => $value) {
            if ($column > $start && $column < $end && self::normalizeHeader($value) === 'date') return $column;
        }
        return null;
    }

    private static function findDateAfter(array $headers, int $start): ?int
    {
        foreach ($headers as $column => $value) {
            $header = self::normalizeHeader($value);
            if ($column > $start && in_array($header, ['date', 'remark4date'], true)) return $column;
        }
        return null;
    }

    private static function insertColumn(DOMDocument $sheet, DOMXPath $xpath, int $column): void
    {
        foreach ($xpath->query('//*[local-name()="c"][@r]') as $cell) {
            if (preg_match('/^([A-Z]+)(\d+)$/', $cell->getAttribute('r'), $match) && self::columnNumber($match[1]) >= $column) {
                $cell->setAttribute('r', self::columnName(self::columnNumber($match[1]) + 1) . $match[2]);
            }
        }
        foreach ($xpath->query('//*[local-name()="col"][@min and @max]') as $definition) {
            $min = (int)$definition->getAttribute('min');
            $max = (int)$definition->getAttribute('max');
            if ($min >= $column) $definition->setAttribute('min', (string)($min + 1));
            if ($max >= $column) $definition->setAttribute('max', (string)($max + 1));
        }
        foreach ($xpath->query('//*[@ref or @sqref]') as $node) {
            foreach (['ref', 'sqref'] as $attribute) {
                if (!$node->hasAttribute($attribute)) continue;
                $value = preg_replace_callback('/(?<![A-Z0-9_])(\$?)([A-Z]{1,3})(\$?)(\d+)/i', static function (array $match) use ($column): string {
                    $number = self::columnNumber(strtoupper($match[2]));
                    if ($number >= $column) $number++;
                    return $match[1] . self::columnName($number) . $match[3] . $match[4];
                }, $node->getAttribute($attribute));
                $node->setAttribute($attribute, (string)$value);
            }
        }
        foreach ($xpath->query('//*[local-name()="row"][@spans]') as $row) {
            $row->setAttribute('spans', (string)preg_replace_callback('/(\d+):(\d+)/', static function (array $match) use ($column): string {
                $end = (int)$match[2];
                return $match[1] . ':' . ($end >= $column ? $end + 1 : $end);
            }, $row->getAttribute('spans')));
        }
    }

    private static function writeCell(DOMDocument $sheet, DOMXPath $xpath, DOMElement $row, int $column, string $value): void
    {
        $rowNumber = $row->getAttribute('r');
        $reference = self::columnName($column) . $rowNumber;
        $cell = $xpath->query('./*[local-name()="c"][@r="' . $reference . '"]', $row)->item(0);
        if (!$cell instanceof DOMElement) {
            $namespace = $sheet->documentElement->namespaceURI;
            $cell = $sheet->createElementNS($namespace, 'c');
            $cell->setAttribute('r', $reference);
            $insertBefore = null;
            foreach ($xpath->query('./*[local-name()="c"][@r]', $row) as $existing) {
                if (preg_match('/^([A-Z]+)\d+$/', $existing->getAttribute('r'), $match) && self::columnNumber($match[1]) > $column) {
                    $insertBefore = $existing;
                    break;
                }
            }
            if ($insertBefore) $row->insertBefore($cell, $insertBefore);
            else $row->appendChild($cell);
        }

        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $cell->setAttribute('t', 'inlineStr');
        $namespace = $sheet->documentElement->namespaceURI;
        $inline = $sheet->createElementNS($namespace, 'is');
        $text = $sheet->createElementNS($namespace, 't');
        $text->appendChild($sheet->createTextNode($value));
        $inline->appendChild($text);
        $cell->appendChild($inline);
    }

    private static function cameraWorkbookValues(array $camera, array $headers): array
    {
        $history = is_array($camera['activity'] ?? null) ? $camera['activity'] : [];
        $verified = array_values(array_filter($history, static fn($item) => ($item['a'] ?? '') === 'CHECK_OK'));
        $rejected = array_values(array_filter($history, static fn($item) => ($item['a'] ?? '') === 'CHECK_NOTOK'));
        $finalDate = $verified ? self::formatWorkbookDate((string)end($verified)['t']) : '';
        $remarks = ['', '', ''];
        $remarkDates = ['', '', ''];
        foreach ($rejected as $index => $item) {
            $slot = min($index, 2);
            $text = implode(', ', is_array($item['issues'] ?? null) ? $item['issues'] : []);
            $note = trim((string)($item['note'] ?? ''));
            if ($note !== '') $text .= ($text !== '' ? ' - ' : '') . $note;
            $remarks[$slot] .= ($remarks[$slot] !== '' ? "\n" : '') . $text;
            $remarkDates[$slot] .= ($remarkDates[$slot] !== '' ? "\n" : '') . self::formatWorkbookDate((string)($item['t'] ?? ''));
        }
        $issues = is_array($camera['issues'] ?? null) ? array_values($camera['issues']) : [];
        $fields = [
            'extremechannelcode' => (string)($camera['id'] ?? ''),
            'afrcode' => '',
            'channelname' => (string)($camera['channel'] ?? ''),
            'finalstatus' => ($camera['status'] ?? '') === 'OK' ? 'OK' : '',
            'comment1' => (string)($issues[0] ?? ''),
            'comment2' => (string)($issues[1] ?? ''),
            'comment3' => (string)($issues[2] ?? ''),
            'comment4' => (string)($issues[3] ?? ''),
            'remark2' => $remarks[0],
            'remark3' => $remarks[1],
            'remark4' => $remarks[2],
            'remark4date' => $remarkDates[2],
            'systemintegrator' => '',
            'channelcategory' => (string)($camera['chCategory'] ?? ''),
            'cameratype' => (string)($camera['camType'] ?? ''),
            'latustfirmwareversion' => (string)($camera['fw'] ?? ''),
            'firmwareupdatedornot' => '',
            'model' => (string)($camera['model'] ?? ''),
            'ipaddress' => (string)($camera['ip'] ?? ''),
            'longitude' => (string)($camera['lon'] ?? ''),
            'latitude' => (string)($camera['lat'] ?? ''),
            'softwareversion' => (string)($camera['swVer'] ?? ''),
            'organization' => (string)($camera['organization'] ?? ''),
            'siname' => (string)($camera['integrator'] ?? ''),
            'sicontactpersonname' => (string)($camera['siContactName'] ?? ''),
            'sicontactpersonmobilenumber' => (string)($camera['siContactMobile'] ?? ''),
            'emailid' => (string)($camera['siContactEmail'] ?? ''),
        ];
        $dateValues = [$finalDate, $remarkDates[0], $remarkDates[1], $remarkDates[2]];
        $dateIndex = 0;
        $values = [];
        foreach ($headers as $column => $header) {
            $key = self::normalizeHeader($header);
            if ($key === 'date') $values[$column] = $dateValues[$dateIndex++] ?? '';
            elseif (array_key_exists($key, $fields)) $values[$column] = $fields[$key];
            elseif ($key === 'remark4date') $values[$column] = $remarkDates[2];
            elseif ($key === 'remark2date') $values[$column] = $remarkDates[0];
            elseif ($key === 'remark3date') $values[$column] = $remarkDates[1];
            else $values[$column] = '';
        }
        return $values;
    }

    private static function formatWorkbookDate(string $value): string
    {
        if ($value === '') return '';
        try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        catch (Throwable) { return $value; }
    }

    private static function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split(strtoupper($letters)) as $letter) $number = $number * 26 + ord($letter) - 64;
        return $number;
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }
}