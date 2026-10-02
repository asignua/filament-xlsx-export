## Filament XLSX Export (asignua/filament-xlsx-export)

- Immediate, streamed `.xlsx` of the CURRENT Filament table (filters, search, sort), with real number/date/boolean cells. No queue, no temp CSV. Add `Asignua\FilamentXlsxExport\Actions\XlsxExportAction::make()` to a list page's `getHeaderActions()` or a table's `headerActions()`, and `XlsxExportBulkAction::make()` to `toolbarActions()` for the selected rows.
- Defaults: the columns the table shows now, a modal with a column picker (select-all included). `->chooseColumns(false)` skips the modal.
- Per-column overrides: `->columnFormats(['total' => ColumnFormat::make()->money('$')->sum(), 'zip' => ColumnFormat::make()->text(), 'vat' => ColumnFormat::make('VAT')->value(fn (Order $record) => $record->total * 0.2)])`. A key that is not a table column and has `->value()` adds a column. `->divideBy(100)` for money stored in cents (a table's `->money(divideBy: 100)` is invisible to the exporter).
- Layout: `->title()` (bold, also the sheet name), `->caption(fn (array $data, int $rowCount) => ...)`, `->fileName()`, `->freezeHeader()`, `->autoFilter()`.
- Options that change the query: `->exportOptions([Toggle::make('only_paid')])` + `->queryUsing(fn (Builder $query, array $data) => ...)`.
- Row limit: config `filament-xlsx-export.row_limit` (25 000) or `->rowLimit(n)`; `0` disables. Over it the user gets a notification, not a file. Livewire holds a download in memory, so keep the limit sane.
- Above config `streaming.above_rows` (5 000) or with `->streamed()` the action redirects to a signed one-shot route that rehydrates the Livewire component and streams the workbook (no Livewire buffering; `streaming.hard_cap` applies, not `row_limit`). Use `->streamed(false)` if the table depends on request state. `->footer(...)` adds lines under the data.
- For Filament's queued `Exporter` classes use the `Asignua\FilamentXlsxExport\Concerns\ExportsTypedXlsx` trait and `xlsxColumnFormats()`.
- Column state is read from the table column (`getState()`), so `getStateUsing()`, relationships and enum casts work; image columns are skipped; `formatStateUsing()` is NOT applied unless `ColumnFormat::make()->formatted()`.
