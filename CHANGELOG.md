# Changelog

All notable changes to `asignua/filament-xlsx-export` are documented here.

## v1.0.0 - unreleased

- `XlsxExportAction` (header action) and `XlsxExportBulkAction`: an immediate, streamed `.xlsx` of the table's current query — filters, search, sort, and for the bulk action the selected rows (including "select all" across pages). No queue, no temporary CSV, no stored file.
- Typed cells: numbers (money and decimals included) stay numbers, dates and date-times are Excel dates with a number format, booleans are TRUE/FALSE, enums give their `HasLabel` label, lists are joined.
- Column picker with select-all; defaults to the columns the table shows now.
- `ColumnFormat` DSL: number formats (`integer`, `decimal`, `money`, `percent`, `number('...')`), date formats, `text()`, `boolean()`, `width()`, `value()` closure, virtual columns, `divideBy()`, `formatted()`, `sum()` total row, `exclude()`, `unselected()`.
- Optional title row (also the sheet name), caption row, frozen header, auto filter, column widths.
- Export options that change the query: `exportOptions()` adds fields to the modal, `queryUsing()` receives their values.
- Streaming mode: above `streaming.above_rows` (or with `->streamed()`) the file is streamed by a signed, short-lived, one-shot, same-user route from a rehydrated Livewire component, with no Livewire buffering; `streaming.hard_cap` bounds it.
- `->footer()` lines under the data and total rows.
- Memory-safe chunked reads (`lazy()`), a configurable row limit and a friendly notification when it is exceeded.
- `ExportsTypedXlsx` trait for Filament's own queued `Exporter` classes: typed cells, widths, frozen header and filter in the XLSX they produce.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
