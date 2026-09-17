<?php

declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/**
 * Styled .xlsx writer for campus member exports in Hub-like column order.
 */
final class MemberRosterXlsxWriter
{
    /**
     * @param list<array{campus:string,rows:list<array<string,mixed>>}> $sheets
     */
    public function build(array $sheets, string $title = 'Christlikeness member export'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary workbook.');
        }
        $path = $tmp . '.xlsx';
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Excel workbook.');
        }
        $sheetXml = [];
        $sheetMeta = [];
        $i = 1;
        foreach ($sheets as $sheet) {
            $name = $this->sheetName((string) ($sheet['campus'] ?? 'Campus ' . $i));
            $sheetXml[] = $this->worksheet($sheet['rows'] ?? [], $title, $name);
            $sheetMeta[] = ['name' => $name, 'path' => 'xl/worksheets/sheet' . $i . '.xml', 'rid' => 'rId' . $i];
            $i++;
        }
        if ($sheetXml === []) {
            $sheetXml[] = $this->worksheet([], $title, 'Members');
            $sheetMeta[] = ['name' => 'Members', 'path' => 'xl/worksheets/sheet1.xml', 'rid' => 'rId1'];
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($sheetMeta));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetMeta));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($sheetMeta));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($sheetMeta as $idx => $meta) {
            $zip->addFromString($meta['path'], $sheetXml[$idx]);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        return $bytes;
    }

    /** @param list<array<string,mixed>> $rows */
    private function worksheet(array $rows, string $title, string $sheetName): string
    {
        $headers = [
            'confirmed w/ the brethren',
            'LAST NAME, FIRST NAME',
            'Preferred Name',
            'MIDDLE NAME',
            'Birthday (MONTH/DAY/YEAR)',
            'Address',
            'Contact Information',
            'Email',
            'Member Since',
            'Member Type',
            'Ministry',
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><tabColor rgb="FF0C5A45"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>'
            . '<col min="1" max="1" width="18" customWidth="1"/>'
            . '<col min="2" max="2" width="28" customWidth="1"/>'
            . '<col min="3" max="3" width="16" customWidth="1"/>'
            . '<col min="4" max="4" width="16" customWidth="1"/>'
            . '<col min="5" max="5" width="22" customWidth="1"/>'
            . '<col min="6" max="6" width="42" customWidth="1"/>'
            . '<col min="7" max="7" width="16" customWidth="1"/>'
            . '<col min="8" max="8" width="28" customWidth="1"/>'
            . '<col min="9" max="9" width="14" customWidth="1"/>'
            . '<col min="10" max="10" width="16" customWidth="1"/>'
            . '<col min="11" max="11" width="22" customWidth="1"/>'
            . '</cols><sheetData>';
        $xml .= $this->rowXml(1, [
            $title . ' — ' . $sheetName . ' — ' . date('F j, Y'),
        ], 2, true);
        $xml .= $this->rowXml(2, $headers, 1, false);
        $r = 3;
        foreach ($rows as $i => $person) {
            $xml .= $this->rowXml($r, $this->values($person), ($i % 2 === 0) ? 3 : 4, false);
            $r++;
        }
        $lastCol = 'K';
        $xml .= '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A1:' . $lastCol . '1"/></mergeCells>'
            . '<autoFilter ref="A2:' . $lastCol . max(2, $r - 1) . '"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6"/>'
            . '</worksheet>';
        return $xml;
    }

    /** @param array<string,mixed> $p
     * @return list<string> */
    private function values(array $p): array
    {
        $last = trim((string) ($p['last_name'] ?? ''));
        $first = trim((string) ($p['first_name'] ?? ''));
        $name = $last . ($first !== '' ? ', ' . $first : '');
        $months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $bm = (int) ($p['bm'] ?? $p['birth_month'] ?? 0);
        $bd = (int) ($p['bd'] ?? $p['birth_day'] ?? 0);
        $by = (int) ($p['by2'] ?? $p['birth_year'] ?? 0);
        $bday = '';
        if ($bm > 0 && $bd > 0) {
            $bday = ($months[$bm] ?? (string) $bm) . ' ' . $bd . ($by > 0 ? ', ' . $by : '');
        }
        $addr = trim((string) ($p['address_raw'] ?? ''));
        if ($addr === '') {
            $addr = implode(', ', array_filter([
                trim((string) ($p['address1'] ?? '')),
                trim((string) ($p['city'] ?? '')),
                trim(implode(' ', array_filter([(string) ($p['state'] ?? ''), (string) ($p['zip'] ?? '')]))),
            ]));
        }
        return [
            '',
            $name,
            (string) ($p['preferred_name'] ?? ''),
            (string) ($p['middle_name'] ?? ''),
            $bday,
            $addr,
            (string) ($p['cell'] ?? $p['phone'] ?? ''),
            (string) ($p['email'] ?? ''),
            (string) ($p['member_since'] ?? ''),
            (string) ($p['member_type'] ?? ''),
            (string) ($p['ministry'] ?? ''),
        ];
    }

    /** @param list<string> $cells */
    private function rowXml(int $r, array $cells, int $style, bool $spanTitle): string
    {
        $xml = '<row r="' . $r . '" ht="' . ($r === 1 ? '28' : '20') . '" customHeight="1">';
        foreach ($cells as $i => $val) {
            $col = $this->col($i + 1);
            $ref = $col . $r;
            $s = $style;
            if ($spanTitle && $i === 0) {
                $s = 2;
            }
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">'
                . $this->xml((string) $val) . '</t></is></c>';
        }
        $xml .= '</row>';
        return $xml;
    }

    private function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheetName(string $name): string
    {
        $name = trim(str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $name));
        if ($name === '') {
            $name = 'Members';
        }
        return substr($name, 0, 31);
    }

    /** @param list<array{name:string,path:string,rid:string}> $sheets */
    private function contentTypes(array $sheets): string
    {
        $overrides = '';
        foreach ($sheets as $s) {
            $overrides .= '<Override PartName="/' . $s['path'] . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** @param list<array{name:string,rid:string}> $sheets */
    private function workbook(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $i = 1;
        foreach ($sheets as $s) {
            $xml .= '<sheet name="' . $this->xml($s['name']) . '" sheetId="' . $i . '" r:id="' . $s['rid'] . '"/>';
            $i++;
        }
        return $xml . '</sheets></workbook>';
    }

    /** @param list<array{path:string,rid:string}> $sheets */
    private function workbookRels(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $s) {
            $target = substr($s['path'], 3);
            $xml .= '<Relationship Id="' . $s['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $target . '"/>';
        }
        $xml .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $xml . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF0C5A45"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0C5A45"/><bgColor rgb="FF0C5A45"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F7F5"/><bgColor rgb="FFF3F7F5"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor rgb="FFFFFFFF"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFC7D4CD"/></left>'
            . '<right style="thin"><color rgb="FFC7D4CD"/></right>'
            . '<top style="thin"><color rgb="FFC7D4CD"/></top>'
            . '<bottom style="thin"><color rgb="FFC7D4CD"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf fontId="0" fillId="0" borderId="0"/>'
            . '<xf fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment wrapText="1" vertical="center"/></xf>'
            . '<xf fontId="2" fillId="0" borderId="0" applyFont="1"><alignment vertical="center"/></xf>'
            . '<xf fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1"><alignment wrapText="1" vertical="center"/></xf>'
            . '<xf fontId="0" fillId="4" borderId="1" applyFill="1" applyBorder="1"><alignment wrapText="1" vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
    }
}
