<?php

declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/**
 * Writer XLSX minimale (un foglio, stringhe inline, nessuna dipendenza):
 * sufficiente per l'export catalogo senza il peso di PhpSpreadsheet.
 */
final class XlsxWriter
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     * @return string percorso del file temporaneo generato
     */
    public function write(string $sheetName, array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo per l\'export');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossibile aprire l\'archivio XLSX');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

        $safeSheetName = htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_XML1 | ENT_QUOTES);
        $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="{$safeSheetName}" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheet .= self::rowXml(1, $headers);
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet .= self::rowXml($rowIndex, $row);
            $rowIndex++;
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }

    /** @param list<string|int|float|null> $cells */
    private static function rowXml(int $rowIndex, array $cells): string
    {
        $xml = "<row r=\"{$rowIndex}\">";
        foreach ($cells as $i => $cell) {
            $ref = self::columnLetter($i) . $rowIndex;
            if ($cell === null || $cell === '') {
                continue;
            }
            if (is_int($cell) || is_float($cell)) {
                $xml .= "<c r=\"{$ref}\"><v>{$cell}</v></c>";
            } else {
                $value = htmlspecialchars($cell, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$value}</t></is></c>";
            }
        }

        return $xml . '</row>';
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod - 1, 26);
        }

        return $letter;
    }
}
