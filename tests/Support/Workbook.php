<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a generated .xlsx back from its XML, so a test can assert what Excel will see: the
 * cell type, the stored value and the number format — not just the text.
 */
final class Workbook
{
    /** @var array<int, array<int, array{type: string, value: string|null, format: string|null, bold: bool}>> */
    public array $rows = [];

    /** @var list<string> */
    public array $merges = [];

    public ?string $autoFilter = null;

    public ?string $freezeCell = null;

    public string $sheetName = '';

    /** @var array<int, float> 1-based column => width */
    public array $widths = [];

    public static function fromBinary(string $binary): self
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');

        if ($path === false) {
            throw new RuntimeException('No temp file.');
        }

        file_put_contents($path, $binary);

        try {
            return self::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public static function fromFile(string $path): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a zip: '.$path);
        }

        $sheet = new SimpleXMLElement((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $styles = new SimpleXMLElement((string) $zip->getFromName('xl/styles.xml'));
        $workbook = new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();

        $book = new self;
        $book->sheetName = (string) $workbook->sheets->sheet['name'];

        $codes = [];

        foreach ($styles->numFmts->numFmt ?? [] as $format) {
            $codes[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }

        $xfs = [];

        foreach ($styles->cellXfs->xf as $index => $xf) {
            $id = (int) $xf['numFmtId'];
            $fontId = (int) $xf['fontId'];
            $xfs[] = [
                'format' => $codes[$id] ?? ($id === 0 ? null : 'builtin:'.$id),
                'bold' => isset($styles->fonts->font[$fontId]->b),
            ];
        }

        foreach ($sheet->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $index = self::columnIndex((string) $cell['r']);
                $type = (string) ($cell['t'] ?? 'n');
                $style = $xfs[(int) ($cell['s'] ?? 0)] ?? ['format' => null, 'bold' => false];
                $value = match ($type) {
                    'inlineStr' => (string) $cell->is->t,
                    default => isset($cell->v) ? (string) $cell->v : null,
                };

                $cells[$index] = ['type' => $type, 'value' => $value, 'format' => $style['format'], 'bold' => $style['bold']];
            }

            $book->rows[(int) $row['r']] = $cells;
        }

        foreach ($sheet->mergeCells->mergeCell ?? [] as $merge) {
            $book->merges[] = (string) $merge['ref'];
        }

        $book->autoFilter = isset($sheet->autoFilter) ? (string) $sheet->autoFilter['ref'] : null;
        $book->freezeCell = isset($sheet->sheetViews->sheetView->pane) ? (string) $sheet->sheetViews->sheetView->pane['topLeftCell'] : null;

        foreach ($sheet->cols->col ?? [] as $col) {
            for ($i = (int) $col['min']; $i <= (int) $col['max']; $i++) {
                $book->widths[$i] = (float) $col['width'];
            }
        }

        return $book;
    }

    /**
     * Row numbers that hold data, i.e. everything after the header row.
     *
     * @return array<int, array<int, array{type: string, value: string|null, format: string|null, bold: bool}>>
     */
    public function dataRows(int $headerRow = 1): array
    {
        return array_filter($this->rows, static fn (int $number): bool => $number > $headerRow, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<string|null>
     */
    public function column(int $index, int $headerRow = 1): array
    {
        return array_values(array_map(static fn (array $row): ?string => $row[$index]['value'] ?? null, $this->dataRows($headerRow)));
    }

    /**
     * @return array{type: string, value: string|null, format: string|null, bold: bool}
     */
    public function cell(int $row, int $column): array
    {
        return $this->rows[$row][$column] ?? ['type' => 'missing', 'value' => null, 'format' => null, 'bold' => false];
    }

    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', $reference, $match);
        $index = 0;

        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
