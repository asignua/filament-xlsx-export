# Changelog

All notable changes to `asignua/filament-xlsx-export` are documented here.

## v1.0.0 - unreleased

- `XlsxExportAction` (header action) and `XlsxExportBulkAction`: an immediate, streamed `.xlsx` of the table's current query — filters, search, sort, and for the bulk action the selected rows (including "select all" across pages). No queue, no temporary CSV, no stored file.
- Typed cells: numbers (money and decimals included) stay numbers, dates and date-times are Excel dates with a number format, booleans are TRUE/FALSE, enums give their `HasLabel` label, lists are joined.
- Column picker with select-all; defaults to the columns the table shows now. A picked column the table has toggled off gets the same eager loading and `counts()` / `sum()` / `exists()` aggregate as a shown one, so it is neither empty nor lazy-loaded per row.
- `ColumnFormat` DSL: number formats (`integer`, `decimal`, `money`, `percent`, `number('...')`), date formats, `text()`, `boolean()`, `width()`, `value()` closure, virtual columns, `divideBy()`, `formatted()`, `sum()` total row, `exclude()`, `unselected()`.
- Optional title row (also the sheet name), caption row, frozen header, auto filter, column widths.
- Export options that change the query: `exportOptions()` adds fields to the modal, `queryUsing()` receives their values.
- Streaming mode: above `streaming.above_rows` (or with `->streamed()`) the file is streamed by a signed, short-lived, one-shot, same-user route from a rehydrated Livewire component, with no Livewire buffering; `streaming.hard_cap` bounds it, and so does an explicit `->rowLimit()`; both are checked again when the file is downloaded. Grouped, `HAVING` and `UNION` queries count their result rows for the limits, the streaming threshold and `$rowCount`.
- Security: panels with tenancy never stream (the download request cannot carry the tenant, so Filament's tenant scope would not apply and the file would hold every tenant's rows); the route also refuses a token issued for such a panel. The route boots the panel like Filament's own `SetUpPanel` middleware, spends a token atomically (`Cache::add`), and checks the URL signature in the controller itself, so the route needs no `signed` middleware and a custom route cannot lose the check. Tables outside any panel stream without a default panel (app default guard, no panel booted). The download request restores the locale of the click.
- Security: the title, caption and footer rows are written as text cells, like the data, so a value starting with `=` (for example a search term echoed in the caption) cannot become a formula.
- A link that cannot be used (expired, used, another user's, action not found) sends the user back to the page with a notification instead of a bare 403/410 page.
- A page header action and a table action with the same name are told apart by the streaming route.
- The bulk export honours `authorizeIndividualRecords()` and the table's `checkIfRecordIsSelectableUsing()`, like core bulk actions: refused rows are skipped as the file streams (`XlsxExporter::filterRecordsUsing()`).
- `->footer()` lines under the data and total rows.
- Memory-safe chunked reads (`lazy()`), a configurable row limit and a friendly notification when it is exceeded. `row_limit`, `streaming.hard_cap` and a `rowLimit()` closure may be numeric strings (`env()` in a published config).
- `ExportsTypedXlsx` trait for Filament's own queued `Exporter` classes: typed cells, widths, frozen header and filter in the XLSX they produce.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
