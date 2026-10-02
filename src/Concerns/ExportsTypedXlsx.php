<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Concerns;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use Asignua\FilamentXlsxExport\Support\CellBuilder;
use Asignua\FilamentXlsxExport\Support\CellFactory;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * For Filament's own queued `Exporter` classes: typed cells in the XLSX it produces.
 *
 * Core builds the workbook from CSV files, so every value arrives as a string; this trait
 * converts the columns you declare in `xlsxColumnFormats()` back into numbers, dates and
 * booleans while the workbook is written, and adds widths, a frozen header and a filter.
 * Columns you do not declare stay text, exactly as before.
 *
 * It still goes through the queue and the CSV intermediate (that is core's design); for an
 * immediate download of the current table use `XlsxExportAction`.
 *
 * ```php
 * class OrderExporter extends Exporter
 * {
 *     use ExportsTypedXlsx;
 *
 *     public function xlsxColumnFormats(): array
 *     {
 *         return [
 *             'total' => ColumnFormat::make()->money(),
 *             'created_at' => ColumnFormat::make()->dateTime(),
 *             'paid' => ColumnFormat::make()->boolean(),
 *         ];
 *     }
 * }
 * ```
 */
trait ExportsTypedXlsx
{
    /**
     * Keyed by the export column name.
     *
     * @return array<string, ColumnFormat>
     */
    public function xlsxColumnFormats(): array
    {
        return [];
    }

    /**
     * @param array<mixed> $values
     */
    public function makeXlsxRow(array $values, ?Style $style = null): Row
    {
        $factory = new CellFactory;
        $builder = new CellBuilder;
        $formats = $this->xlsxColumnFormats();
        $names = array_map('strval', array_keys($this->columnMap));
        $cells = [];

        foreach (array_values($values) as $index => $value) {
            $name = (string) ($names[$index] ?? $index);
            $export = new ExportColumn($name, $name, null, $formats[$name] ?? ColumnFormat::make());
            $cells[] = $builder->make($factory->fromText($export, $value === null ? null : (string) $value));
        }

        return new Row($cells, $style);
    }

    /**
     * @param array<mixed> $values
     */
    public function makeXlsxHeaderRow(array $values, ?Style $style = null): Row
    {
        // Labels stay text: a column called "2024" must not become a number.
        return new Row(array_map(
            static fn (mixed $value): StringCell => new StringCell((string) $value, $style),
            array_values($values),
        ), $style);
    }

    public function configureXlsxWriterAfterOpen(Writer $writer): Writer
    {
        $sheet = $writer->getCurrentSheet();
        $formats = $this->xlsxColumnFormats();
        $labels = array_values($this->columnMap);
        $min = (float) config('filament-xlsx-export.width.min', 8);
        $max = (float) config('filament-xlsx-export.width.max', 60);

        foreach (array_keys($this->columnMap) as $index => $name) {
            $width = ($formats[$name] ?? null)?->getWidth() ?? min($max, max($min, mb_strlen((string) ($labels[$index] ?? $name)) * 1.2 + 4));
            $sheet->setColumnWidth($width, $index + 1);
        }

        if (config('filament-xlsx-export.freeze_header', true)) {
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        }

        return $writer;
    }

    public function configureXlsxWriterBeforeClose(Writer $writer): Writer
    {
        if (config('filament-xlsx-export.auto_filter', true) && $this->columnMap !== []) {
            $rows = max(1, (int) $this->export->total_rows);
            $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, count($this->columnMap) - 1, 1 + $rows));
        }

        return $writer;
    }
}
