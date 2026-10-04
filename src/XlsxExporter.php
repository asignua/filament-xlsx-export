<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport;

use Asignua\FilamentXlsxExport\Support\CellBuilder;
use Asignua\FilamentXlsxExport\Support\CellFactory;
use Asignua\FilamentXlsxExport\Support\CellValue;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes the rows of an Eloquent query to an .xlsx, one chunk at a time.
 *
 * The workbook is streamed by OpenSpout (inline strings, no shared-string table), so memory
 * stays flat whatever the row count. Nothing goes through a CSV or a queue.
 *
 * Layout: optional title (merged, bold), optional caption, a blank line when either is there,
 * the header row, the data, and an optional bold total row.
 */
final class XlsxExporter
{
    public const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private ?string $title = null;

    private ?string $caption = null;

    private ?string $sheetName = null;

    private ?int $chunkSize = null;

    private ?bool $freezeHeader = null;

    private ?bool $autoFilter = null;

    private ?string $totalLabel = null;

    /** @var list<string> */
    private array $footer = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private int $writtenRows = 0;

    private ?Closure $recordFilter = null;

    private CellBuilder $builder;

    /**
     * @param list<ExportColumn> $columns
     * @param Builder<Model>     $query
     */
    public function __construct(
        private readonly array $columns,
        private readonly Builder $query,
        private readonly CellFactory $cells = new CellFactory,
    ) {
        $this->builder = new CellBuilder;
    }

    /**
     * @param list<ExportColumn> $columns
     * @param Builder<Model>     $query
     */
    public static function make(array $columns, Builder $query): self
    {
        return new self($columns, $query);
    }

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function caption(?string $caption): self
    {
        $this->caption = $caption;

        return $this;
    }

    public function sheetName(?string $sheetName): self
    {
        $this->sheetName = $sheetName;

        return $this;
    }

    public function chunkSize(int $chunkSize): self
    {
        $this->chunkSize = max(1, $chunkSize);

        return $this;
    }

    public function freezeHeader(?bool $condition = true): self
    {
        $this->freezeHeader = $condition;

        return $this;
    }

    public function autoFilter(?bool $condition = true): self
    {
        $this->autoFilter = $condition;

        return $this;
    }

    /**
     * Lines written after the data and the total row, separated from them by a blank row.
     *
     * @param list<string> $lines
     */
    public function footer(array $lines): self
    {
        $this->footer = $lines;

        return $this;
    }

    public function totalLabel(?string $label): self
    {
        $this->totalLabel = $label;

        return $this;
    }

    /**
     * Named arguments handed to `ColumnFormat::value()` closures.
     *
     * @param array<string, mixed> $params
     */
    public function params(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Data rows written by the last run (without the header and the total).
     */
    /**
     * Skip the records the closure refuses (`fn (Model $record): bool`), checked as they stream.
     */
    public function filterRecordsUsing(?Closure $filter): self
    {
        $this->recordFilter = $filter;

        return $this;
    }

    public function writtenRows(): int
    {
        return $this->writtenRows;
    }

    public function download(string $filename): StreamedResponse
    {
        return response()->streamDownload(
            // NOT openToBrowser(): it sends its own headers through bare header() calls,
            // which then fight the ones Laravel sets around this callback.
            function (): void {
                @set_time_limit(0);
                $this->writeTo('php://output');
            },
            self::safeFilename($filename),
            ['Content-Type' => self::CONTENT_TYPE, 'X-Accel-Buffering' => 'no'],
        );
    }

    public function writeTo(string $path): void
    {
        $this->writtenRows = 0;
        $this->builder = new CellBuilder;

        $options = new Options;
        $lastColumn = max(0, count($this->columns) - 1);
        $preamble = ($this->title !== null ? 1 : 0) + ($this->caption !== null ? 1 : 0);
        $headerRow = $preamble + ($preamble > 0 ? 1 : 0) + 1;

        if ($this->title !== null && $lastColumn > 0) {
            $options->mergeCells(0, 1, $lastColumn, 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(self::normalizeSheetName($this->sheetName ?? $this->title));

        if ($this->freezeHeader ?? (bool) config('filament-xlsx-export.freeze_header', true)) {
            $sheet->setSheetView((new SheetView)->setFreezeRow($headerRow + 1));
        }

        foreach ($this->columns as $index => $column) {
            $sheet->setColumnWidth($this->widthOf($column), $index + 1);
        }

        if ($this->title !== null) {
            // Explicit StringCells (as for the data): Row::fromValues() would turn a title, caption
            // or footer starting with "=" — often built from the table's search or the modal's
            // values — into a live formula.
            $titleStyle = (new Style)->setFontBold()->setFontSize(14);
            $writer->addRow(new Row([new StringCell($this->title, $titleStyle)], $titleStyle));
        }

        if ($this->caption !== null) {
            $writer->addRow(new Row([new StringCell($this->caption, null)]));
        }

        if ($preamble > 0) {
            $writer->addRow(new Row([]));
        }

        $writer->addRow($this->headerRow());

        $totals = [];

        foreach ($this->records() as $record) {
            $values = [];

            foreach ($this->columns as $index => $column) {
                $cell = $this->cells->make($column, $record, $this->params);
                $values[$index] = $cell;

                if ($column->format->hasSum() && (is_int($cell->value) || is_float($cell->value))) {
                    $totals[$index] = ($totals[$index] ?? 0) + $cell->value;
                }
            }

            $writer->addRow($this->dataRow($values));
            $this->writtenRows++;
        }

        if ($totals !== []) {
            $writer->addRow($this->totalRow($totals));
        }

        if ($this->footer !== []) {
            $writer->addRow(new Row([]));

            foreach ($this->footer as $line) {
                $writer->addRow(new Row([new StringCell($line, null)]));
            }
        }

        if ($this->autoFilter ?? (bool) config('filament-xlsx-export.auto_filter', true)) {
            $sheet->setAutoFilter(new AutoFilter(0, $headerRow, $lastColumn, $headerRow + max(1, $this->writtenRows)));
        }

        $writer->close();
    }

    /**
     * @return iterable<Model>
     */
    private function records(): iterable
    {
        $query = clone $this->query;

        // lazy() pages with LIMIT/OFFSET: without a unique last sort key, rows that tie on the
        // sort columns may be repeated or skipped across chunks. Append the primary key unless the
        // query already orders by it. A grouped, HAVING or UNION query is left alone: there the key
        // is not a selectable sort column (Postgres, MySQL ONLY_FULL_GROUP_BY reject it).
        $base = $query->getQuery();

        if (!$this->ordersByKey($query) && empty($base->groups) && empty($base->havings) && empty($base->unions)) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        $records = $query->lazy($this->chunkSize ?? max(1, (int) config('filament-xlsx-export.chunk_size', 500)));

        if ($this->recordFilter !== null) {
            $records = $records->filter($this->recordFilter);
        }

        /** @var iterable<Model> */
        return $records;
    }

    /**
     * @param Builder<Model> $query
     */
    private function ordersByKey(Builder $query): bool
    {
        $model = $query->getModel();
        $keys = [$model->getKeyName(), $model->getQualifiedKeyName()];

        foreach ($query->getQuery()->orders ?? [] as $order) {
            $column = $order['column'] ?? null;

            if (is_string($column) && in_array($column, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    private function headerRow(): Row
    {
        $style = new Style;

        if ((bool) config('filament-xlsx-export.header.bold', true)) {
            $style->setFontBold();
        }

        $background = config('filament-xlsx-export.header.background');

        if (is_string($background) && $background !== '') {
            $style->setBackgroundColor($background);
        }

        return new Row(array_map(
            static fn (ExportColumn $column): Cell => new StringCell($column->label, $style),
            $this->columns,
        ));
    }

    /**
     * @param array<int, CellValue> $values
     */
    private function dataRow(array $values): Row
    {
        $cells = [];

        foreach ($values as $cell) {
            $cells[] = $this->builder->make($cell, bold: false);
        }

        return new Row($cells);
    }

    /**
     * @param array<int, float|int> $totals
     */
    private function totalRow(array $totals): Row
    {
        $label = $this->totalLabel ?? config('filament-xlsx-export.total_label') ?? __('filament-xlsx-export::xlsx-export.total');
        $cells = [];

        foreach ($this->columns as $index => $column) {
            if (array_key_exists($index, $totals)) {
                $cell = new CellValue(round($totals[$index], 10), $column->format->getNumberFormat() ?? $this->sumFormat($column));
            } else {
                $cell = new CellValue($index === 0 ? (string) $label : null);
            }

            $cells[] = $this->builder->make($cell, bold: true);
        }

        return new Row($cells);
    }

    private function sumFormat(ExportColumn $column): ?string
    {
        $fakeTotal = $column->column instanceof TextColumn && $column->column->isMoney();

        return $fakeTotal ? (string) config('filament-xlsx-export.formats.money', '#,##0.00') : null;
    }

    private function widthOf(ExportColumn $column): float
    {
        if ($column->format->getWidth() !== null) {
            return $column->format->getWidth();
        }

        $min = (float) config('filament-xlsx-export.width.min', 8);
        $max = (float) config('filament-xlsx-export.width.max', 60);

        return min($max, max($min, mb_strlen($column->label) * 1.2 + 4));
    }

    public static function normalizeSheetName(?string $title): string
    {
        $name = trim(str_replace(['\\', '/', '?', '*', ':', '[', ']', "'"], ' ', (string) $title));

        return $name === '' ? 'Export' : Str::limit($name, 31, '');
    }

    /**
     * Laravel writes both a transliterated `filename=` and an RFC 6266 `filename*=UTF-8''`, so
     * non-Latin names survive. What it does not do is defend the name: Symfony throws on '/',
     * '\' and '%', and a title is often free text.
     */
    public static function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[\/\\\\%"\x00-\x1F]+/u', ' ', $filename) ?? $filename;
        $filename = trim((string) preg_replace('/\s+/u', ' ', $filename));
        $filename = $filename === '' ? 'export' : $filename;

        if (!str_ends_with(mb_strtolower($filename), '.xlsx')) {
            $filename = mb_substr($filename, 0, 120).'.xlsx';
        }

        return $filename;
    }
}
