<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Concerns;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use Asignua\FilamentXlsxExport\Support\ColumnResolver;
use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Asignua\FilamentXlsxExport\XlsxExporter;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The configuration and the work shared by `XlsxExportAction` and `XlsxExportBulkAction`.
 */
trait ExportsTableToXlsx
{
    protected string|Closure|null $xlsxFileName = null;

    protected string|Closure|null $xlsxTitle = null;

    protected string|Closure|null $xlsxCaption = null;

    /** @var array<string, ColumnFormat> */
    protected array $xlsxColumnFormats = [];

    /** @var array<int, mixed>|Closure */
    protected array|Closure $xlsxOptions = [];

    protected ?Closure $xlsxQueryUsing = null;

    protected int|Closure|null $xlsxRowLimit = null;

    protected bool $xlsxChooseColumns = true;

    protected ?bool $xlsxFreezeHeader = null;

    protected ?bool $xlsxAutoFilter = null;

    protected ?int $xlsxChunkSize = null;

    protected ?string $xlsxTotalLabel = null;

    protected ?bool $xlsxStreamed = null;

    /** @var array<int, string>|Closure|string|null */
    protected string|array|Closure|null $xlsxFooter = null;

    /**
     * File name without the extension. Closures may take `$data` and `$livewire`.
     * Default: the table's plural label and today's date.
     */
    public function fileName(string|Closure|null $fileName): static
    {
        $this->xlsxFileName = $fileName;

        return $this;
    }

    /**
     * A bold title in the first row (also the sheet name).
     */
    public function title(string|Closure|null $title): static
    {
        $this->xlsxTitle = $title;

        return $this;
    }

    /**
     * A line under the title that says which question the sheet answers: the filters, a period.
     * Closures may take `$data`, `$livewire` and `$rowCount` (rows about to be written).
     */
    public function caption(string|Closure|null $caption): static
    {
        $this->xlsxCaption = $caption;

        return $this;
    }

    /**
     * Per-column overrides, keyed by the table column name. A key that is not a table column
     * and has `->value()` adds a virtual column.
     *
     * @param array<string, ColumnFormat> $formats
     */
    public function columnFormats(array $formats): static
    {
        $this->xlsxColumnFormats = $formats;

        return $this;
    }

    /**
     * Extra fields for the export modal, shown under the column picker. Their values reach
     * `queryUsing()`, `fileName()`, `caption()` and the value closures as `$data`.
     *
     * @param array<int, mixed>|Closure $components
     */
    public function exportOptions(array|Closure $components): static
    {
        $this->xlsxOptions = $components;

        return $this;
    }

    /**
     * Change the query by what the user chose in the modal. Receives `Builder $query`,
     * `array $data`, `$livewire`; returns the builder (or nothing, when it only mutates).
     */
    public function queryUsing(?Closure $callback): static
    {
        $this->xlsxQueryUsing = $callback;

        return $this;
    }

    /**
     * Most rows one file may have. In Livewire mode it replaces config `row_limit` (`0` disables
     * the check); in streaming mode the lower of it and `streaming.hard_cap` applies, so an
     * explicit cap holds whichever mode the export ends up in. Default: the config values.
     */
    public function rowLimit(int|Closure|null $limit): static
    {
        $this->xlsxRowLimit = $limit;

        return $this;
    }

    /**
     * `false` skips the picker and exports the columns the table shows now.
     */
    public function chooseColumns(bool $condition = true): static
    {
        $this->xlsxChooseColumns = $condition;

        return $this;
    }

    public function freezeHeader(bool $condition = true): static
    {
        $this->xlsxFreezeHeader = $condition;

        return $this;
    }

    public function autoFilter(bool $condition = true): static
    {
        $this->xlsxAutoFilter = $condition;

        return $this;
    }

    public function chunkSize(int $size): static
    {
        $this->xlsxChunkSize = $size;

        return $this;
    }

    public function totalLabel(?string $label): static
    {
        $this->xlsxTotalLabel = $label;

        return $this;
    }

    /**
     * Lines written under the data and the total row (e.g. "Generated at ..."). A string, a
     * list of strings, or a closure returning either; it may take `$data`, `$livewire`, `$rowCount`.
     *
     * @param array<int, string>|Closure|string|null $lines
     */
    public function footer(string|array|Closure|null $lines): static
    {
        $this->xlsxFooter = $lines;

        return $this;
    }

    /**
     * Force the download mode: `true` always goes through the signed streaming route (no
     * Livewire buffering), `false` always stays in Livewire (bound by `row_limit`). Default
     * `null`: streaming above config `streaming.above_rows`. A panel with tenancy never streams
     * (the download request cannot carry the tenant); there `true` falls back to Livewire mode.
     */
    public function streamed(?bool $condition = true): static
    {
        $this->xlsxStreamed = $condition;

        return $this;
    }

    protected function setUpXlsxExport(): void
    {
        $this->color('gray');
        $this->icon(Heroicon::OutlinedArrowDownTray);
        $this->modalSubmitActionLabel(__('filament-xlsx-export::xlsx-export.download'));
        $this->modalWidth('lg');
        $this->schema(fn (HasTable $livewire): array => $this->xlsxFormSchema($livewire));
    }

    /**
     * @return array<int, mixed>
     */
    protected function xlsxFormSchema(HasTable $livewire): array
    {
        $schema = [];

        if ($this->xlsxChooseColumns) {
            $available = ColumnResolver::available($livewire->getTable(), $this->xlsxColumnFormats);

            $schema[] = CheckboxList::make('columns')
                ->label(__('filament-xlsx-export::xlsx-export.columns'))
                ->options(collect($available)->mapWithKeys(fn (ExportColumn $column): array => [$column->name => $column->label])->all())
                ->default(array_map(fn (ExportColumn $column): string => $column->name, ColumnResolver::pick($available, null)))
                ->bulkToggleable()
                ->searchable(count($available) > 12)
                ->columns(2)
                ->minItems(1)
                ->required();
        }

        $options = $this->evaluate($this->xlsxOptions);

        return [...$schema, ...(is_array($options) ? $options : [])];
    }

    /**
     * The Livewire entry: decides between an in-request download and the signed streaming route.
     *
     * @param Builder<Model>       $query
     * @param array<string, mixed> $data
     */
    protected function exportQuery(HasTable $livewire, Builder $query, array $data): ?StreamedResponse
    {
        $columns = $this->pickedColumns($livewire, $data);
        $query = $this->loadPickedColumns($this->scopedQuery($livewire, $query, $data), $columns);

        if ($columns === []) {
            Notification::make()->title(__('filament-xlsx-export::xlsx-export.no_columns'))->warning()->send();

            return null;
        }

        $count = $this->countXlsxRows($query);
        $streaming = (bool) config('filament-xlsx-export.streaming.enabled', true)
            && StreamedExports::supportsPanel(Filament::getCurrentPanel());
        $stream = $streaming && ($this->xlsxStreamed ?? $count > (int) config('filament-xlsx-export.streaming.above_rows', 5000));

        if ($this->exceedsXlsxRowLimit($livewire, $data, $count, $stream)) {
            return null;
        }

        if ($stream) {
            /** @var Component&HasTable $livewire every Filament table host is a Livewire component */
            $livewire->redirect(StreamedExports::issue($livewire, (string) $this->getName(), $this->isBulkExport(), $data, $this->belongsToXlsxTable()));

            return null;
        }

        return $this->makeExporter($livewire, $query, $columns, $data, $count)->download($this->resolveFileName($livewire, $data, $count));
    }

    /**
     * The route entry: the same file, rebuilt from a rehydrated component. Called by the signed
     * streaming route only.
     *
     * @param Builder<Model>       $query
     * @param array<string, mixed> $data
     */
    public function streamExport(HasTable $livewire, Builder $query, array $data): ?StreamedResponse
    {
        $columns = $this->pickedColumns($livewire, $data);
        $query = $this->loadPickedColumns($this->scopedQuery($livewire, $query, $data), $columns);
        $count = $this->countXlsxRows($query);

        // Rows may have been added between the click and the download (up to the link's ttl), and
        // the click checked the count it saw then: the caps hold for the file actually written.
        if ($this->exceedsXlsxRowLimit($livewire, $data, $count, true)) {
            return null;
        }

        return $this->makeExporter($livewire, $query, $columns, $data, $count)->download($this->resolveFileName($livewire, $data, $count));
    }

    /**
     * The number of rows the file will have. A plain `count()` on a grouped, HAVING or UNION query
     * returns the size of its first group (one aggregate row per group); the pagination count
     * wraps such a query in a subquery and counts its rows.
     *
     * @param Builder<Model> $query
     */
    protected function countXlsxRows(Builder $query): int
    {
        return $query->clone()->reorder()->toBase()->getCountForPagination();
    }

    /**
     * Livewire mode: `rowLimit()`, else config `row_limit`. Streaming: the lower of `rowLimit()`
     * and `streaming.hard_cap`. Sends the refusal notification when the count is over it.
     *
     * @param array<string, mixed> $data
     */
    protected function exceedsXlsxRowLimit(HasTable $livewire, array $data, int $count, bool $stream): bool
    {
        $explicit = $this->xlsxRowLimit === null
            ? null
            : $this->evaluate($this->xlsxRowLimit, ['livewire' => $livewire, 'data' => $data]);

        if ($stream) {
            $caps = array_filter(
                [$explicit, config('filament-xlsx-export.streaming.hard_cap')],
                static fn (mixed $cap): bool => is_int($cap) && $cap > 0,
            );
            $limit = $caps === [] ? null : min($caps);
        } else {
            $limit = $explicit ?? config('filament-xlsx-export.row_limit');
        }

        if (!is_int($limit) || $limit <= 0 || $count <= $limit) {
            return false;
        }

        Notification::make()
            ->title(__('filament-xlsx-export::xlsx-export.too_many_rows_title'))
            ->body(__('filament-xlsx-export::xlsx-export.too_many_rows_body', [
                'count' => number_format($count),
                'limit' => number_format($limit),
            ]))
            ->warning()
            ->send();

        return true;
    }

    public function isBulkExport(): bool
    {
        return $this instanceof BulkAction;
    }

    /**
     * Whether the action sits on the table (header/toolbar) rather than in the page's header, so
     * the download route looks it up in the same place when both have an action of this name.
     */
    protected function belongsToXlsxTable(): bool
    {
        return $this->getTable() !== null;
    }

    /**
     * @param Builder<Model>       $query
     * @param array<string, mixed> $data
     *
     * @return Builder<Model>
     */
    protected function scopedQuery(HasTable $livewire, Builder $query, array $data): Builder
    {
        if ($this->xlsxQueryUsing instanceof Closure) {
            $query = $this->evaluate($this->xlsxQueryUsing, ['query' => $query, 'data' => $data, 'livewire' => $livewire]) ?? $query;
        }

        return $query;
    }

    /**
     * Filament eager-loads relationships and adds `counts()` / `sum()` / `exists()` aggregates for
     * the columns the table SHOWS; the picker also offers the toggled-off ones. Without this a
     * picked toggled-off aggregate column is exported empty and a relationship column lazy-loads
     * once per row (or throws mid-stream under `Model::preventLazyLoading()`). The bulk query
     * already covers every table column.
     *
     * @param Builder<Model>     $query
     * @param list<ExportColumn> $columns
     *
     * @return Builder<Model>
     */
    protected function loadPickedColumns(Builder $query, array $columns): Builder
    {
        if ($this->isBulkExport()) {
            return $query;
        }

        foreach ($columns as $column) {
            if ($column->column === null || !$column->column->isToggledHidden()) {
                continue;
            }

            $column->column->applyRelationshipAggregates($query);
            $column->column->applyEagerLoading($query);
        }

        return $query;
    }

    /**
     * The per-record gates Filament applies to the selected records of every core bulk action:
     * `authorizeIndividualRecords()` on the action and `checkIfRecordIsSelectableUsing()` on the
     * table. The export reads the selection as a query (so "select all" never loads a collection),
     * which bypasses both; they are checked row by row as the file streams instead. The row count
     * for the limits and `$rowCount` is taken before them.
     */
    protected function selectedRecordFilter(HasTable $livewire): ?Closure
    {
        $table = $livewire->getTable();
        $selectable = $table->checksIfRecordIsSelectable();
        $authorize = $this->shouldAuthorizeIndividualRecords();

        if (!$selectable && !$authorize) {
            return null;
        }

        return fn (Model $record): bool => (!$selectable || $table->isRecordSelectable($record))
            && (!$authorize || $this->getIndividualRecordAuthorizationResponse($record)->allowed());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ExportColumn>
     */
    protected function pickedColumns(HasTable $livewire, array $data): array
    {
        $available = ColumnResolver::available($livewire->getTable(), $this->xlsxColumnFormats);
        $picked = $data['columns'] ?? null;

        return ColumnResolver::pick($available, is_array($picked) ? array_values(array_map('strval', $picked)) : null);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function resolveFileName(HasTable $livewire, array $data, int $count): string
    {
        $fileName = $this->evaluate($this->xlsxFileName, ['data' => $data, 'livewire' => $livewire, 'rowCount' => $count]);

        return is_string($fileName) && $fileName !== ''
            ? $fileName
            : Str::title((string) $livewire->getTable()->getPluralModelLabel()).' '.now()->format('Y-m-d');
    }

    /**
     * @param Builder<Model>       $query
     * @param list<ExportColumn>   $columns
     * @param array<string, mixed> $data
     */
    protected function makeExporter(HasTable $livewire, Builder $query, array $columns, array $data, int $count): XlsxExporter
    {
        $evaluation = ['data' => $data, 'livewire' => $livewire, 'rowCount' => $count];
        $table = $livewire->getTable();
        $title = $this->evaluate($this->xlsxTitle, $evaluation);
        $caption = $this->evaluate($this->xlsxCaption, $evaluation);
        $footer = $this->evaluate($this->xlsxFooter, $evaluation);

        $exporter = XlsxExporter::make($columns, $query)
            ->title(is_string($title) && $title !== '' ? $title : null)
            ->caption(is_string($caption) && $caption !== '' ? $caption : null)
            ->footer(is_array($footer) ? array_values(array_map('strval', $footer)) : (is_string($footer) && $footer !== '' ? [$footer] : []))
            ->sheetName(is_string($title) && $title !== '' ? $title : (string) $table->getPluralModelLabel())
            ->params(['data' => $data, 'livewire' => $livewire])
            ->totalLabel($this->xlsxTotalLabel);

        if ($this->isBulkExport()) {
            $exporter->filterRecordsUsing($this->selectedRecordFilter($livewire));
        }

        if ($this->xlsxChunkSize !== null) {
            $exporter->chunkSize($this->xlsxChunkSize);
        }

        if ($this->xlsxFreezeHeader !== null) {
            $exporter->freezeHeader($this->xlsxFreezeHeader);
        }

        if ($this->xlsxAutoFilter !== null) {
            $exporter->autoFilter($this->xlsxAutoFilter);
        }

        return $exporter;
    }
}
