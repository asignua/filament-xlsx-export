# Filament XLSX Export

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asignua/filament-xlsx-export.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-xlsx-export)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-xlsx-export/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/asignua/filament-xlsx-export/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/asignua/filament-xlsx-export.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-xlsx-export)
[![License](https://img.shields.io/packagist/l/asignua/filament-xlsx-export.svg?style=flat-square)](https://github.com/asignua/filament-xlsx-export/blob/main/LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/asignua/filament-xlsx-export/composite.svg)](https://plumbphp.dev/asignua/filament-xlsx-export)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-xlsx-export/v1.0.0/art/cover.jpg" alt="Filament XLSX Export">

"Download what is on the screen" as a real Excel file: the table's current filters, search and sort — or the rows
you ticked — streamed straight to the browser, with numbers that are numbers and dates that are dates.

Filament's built-in export is queued, chunked, stored and goes through a CSV first, so every cell of the XLSX ends up as
text. People keep asking for the simple version:

- an immediate download without queues — [discussion #11950](https://github.com/filamentphp/filament/discussions/11950)
- numbers, dates and enums as real cells instead of text, because core builds the XLSX from an intermediate CSV — [#16233](https://github.com/filamentphp/filament/discussions/16233)
- export options that change the query — [#12519](https://github.com/filamentphp/filament/discussions/12519)
- select-all in the column picker — [#16062](https://github.com/filamentphp/filament/discussions/16062)

This plugin does exactly that, and keeps core's `Exporter` usable too (see [Typed cells for core exporters](#typed-cells-for-core-exporters)).

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Typed cells](#typed-cells)
- [ColumnFormat](#columnformat)
- [Export options that change the query](#export-options-that-change-the-query)
- [Layout](#layout)
- [Row limit and memory](#row-limit-and-memory)
- [Streaming mode](#streaming-mode)
- [Typed cells for core exporters](#typed-cells-for-core-exporters)
- [Configuration](#configuration)
- [Gotchas](#gotchas)
- [Translations](#translations)
- [AI agents](#ai-agents)
- [Testing](#testing)

## Screenshots

The export modal with the column picker:

![Export modal](https://raw.githubusercontent.com/asignua/filament-xlsx-export/v1.0.0/art/export-modal.jpg)

The downloaded workbook - numbers, dates and booleans are real cells, the total is a bold row (a rendering of the file's cells, not a screenshot of Excel):

![The resulting workbook](https://raw.githubusercontent.com/asignua/filament-xlsx-export/v1.0.0/art/workbook.jpg)

## Requirements

- PHP 8.3+
- Filament 5, Laravel 12 or 13
- [OpenSpout](https://github.com/openspout/openspout) 4 (installed with the package)

## Installation

```bash
composer require asignua/filament-xlsx-export
```

There are no assets, migrations or panel registration: the package only adds actions. To change the defaults:

```bash
php artisan vendor:publish --tag=filament-xlsx-export-config
```

## Usage

On a list page:

```php
use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;

protected function getHeaderActions(): array
{
    return [
        XlsxExportAction::make(),
    ];
}
```

Selected rows, in the table:

```php
use Asignua\FilamentXlsxExport\Actions\XlsxExportBulkAction;

$table->toolbarActions([
    BulkActionGroup::make([
        XlsxExportBulkAction::make(),
    ]),
]);
```

Both actions open a small modal with the **column picker** (a checkbox list with *select all*), ticked with the
columns the table shows right now: columns toggled off and `hidden()` ones stay out, image columns are never offered.
`->chooseColumns(false)` skips the modal and downloads at once.

The file is exactly the table's query: filters, search and sort. The bulk action turns the selection (including "select
all" across pages, with its deselections) into a query, never into a loaded collection.

```php
XlsxExportAction::make()
    ->title('Orders')                               // bold first row, also the sheet name
    ->footer(fn () => 'Generated '.now()->format('Y-m-d H:i'))   // under the data and totals
    ->caption(fn (array $data, int $rowCount) => "Open orders, {$rowCount} rows")
    ->fileName(fn () => 'orders-'.now()->format('Y-m-d'))
    ->rowLimit(10_000)
    ->columnFormats([...]);
```

Closures may ask for `$data` (the modal's values), `$livewire` and, for `caption()`, `$rowCount`.

## Typed cells

| Table value | Cell |
| --- | --- |
| `int`, `float`, decimal strings of `numeric()` / `money()` columns | number (money columns get `#,##0.00`) |
| `Carbon` / `DateTimeInterface`, strings of `date()` / `dateTime()` columns | Excel date with a number format (`yyyy-mm-dd`, `yyyy-mm-dd hh:mm`), shown in the column's timezone |
| `time()` columns | day fraction with `hh:mm` |
| `bool` | TRUE / FALSE |
| `HasLabel` enum | its label; other backed enums their value |
| arrays, collections, relationship lists | joined with `, ` |
| everything else | text (never a formula, even when it starts with `=`) |

The value is the column's `getState()`, so relationships (`customer.name`), `getStateUsing()`, accessors and casts work.
`formatStateUsing()`, prefixes and limits are **not** applied — you get the typed value. Opt in per column with
`ColumnFormat::make()->formatted()`.

## ColumnFormat

Override a column by its name:

```php
use Asignua\FilamentXlsxExport\ColumnFormat;

XlsxExportAction::make()->columnFormats([
    'total'      => ColumnFormat::make()->money('€')->sum(),        // "€" #,##0.00 and a bold total row
    'price'      => ColumnFormat::make()->divideBy(100)->decimal(2), // stored in cents
    'ratio'      => ColumnFormat::make()->percent(1),
    'weight'     => ColumnFormat::make()->number('0.000'),
    'zip'        => ColumnFormat::make()->text(),                    // keeps "00123"
    'created_at' => ColumnFormat::make('Created')->date('dd.mm.yyyy')->width(14),
    'paid'       => ColumnFormat::make()->boolean(),
    'internal'   => ColumnFormat::make()->exclude(),                 // never offered
    'notes'      => ColumnFormat::make()->unselected(),              // offered, not ticked
    'vat'        => ColumnFormat::make('VAT')                        // virtual column
                        ->value(fn (Order $record, $state, array $data) => $record->total * 0.2)
                        ->decimal(2),
]);
```

A key that is not a table column and has `->value()` is added after the table's columns and appears in the picker.
The `value()` closure takes `$record`, `$state` (what the table column would have given), `$data` and `$livewire`.

| Method | Effect |
| --- | --- |
| `label()` | header text |
| `number(?string)`, `integer()`, `decimal($places)`, `money($symbol, $places)`, `percent($places)` | number formats |
| `date()`, `dateTime()`, `time()` | date formats (Excel format codes) |
| `text()`, `boolean()` | force the cell type |
| `width()` | column width in characters |
| `value()` | where the value comes from |
| `divideBy()` | divide numbers (money stored in cents) |
| `formatted()` | use the text the table shows |
| `sum()` | bold total row under the column |
| `exclude()`, `unselected()` | picker behaviour |

## Export options that change the query

Add fields to the modal and use them to reshape the query, the file name and the caption:

```php
XlsxExportAction::make()
    ->exportOptions([
        Toggle::make('only_paid')->label('Only paid orders'),
    ])
    ->queryUsing(fn (Builder $query, array $data) => ($data['only_paid'] ?? false)
        ? $query->where('paid', true)
        : $query)
    ->caption(fn (array $data) => $data['only_paid'] ? 'Paid orders' : 'All orders');
```

The row-limit check runs on the final query.

## Layout

The sheet is: optional title (merged across the columns, bold), optional caption, a blank line when either is there,
the header (bold, grey), the data, a bold total row when a column asks for `sum()`, and the `footer()` lines (string,
list or closure) after a blank row.

The header row is frozen, an auto filter covers the data, and every column gets a width (the label length plus padding
within `width.min` and `width.max`, or your `->width()`). Switch the first two off with `->freezeHeader(false)` and
`->autoFilter(false)`, or in the config.

## Row limit and memory

Rows are read with `lazy()` in chunks (`chunk_size`, default 500) and written through OpenSpout with inline strings, so
the workbook never exists in memory as a whole. In **Livewire mode** (up to `streaming.above_rows`) the limit
`row_limit` (default 25 000, per action `->rowLimit(n)`, `0` disables) is checked with a `COUNT(*)` first; over it the
user sees a notification instead of a download. Livewire still holds that finished file in memory once, which is what
the limit protects. Bigger exports use the streaming mode below.

## Streaming mode

A Livewire action cannot stream: it captures the response and sends it back base64-encoded. So above
`streaming.above_rows` (default 5 000), or when forced with `->streamed()`, the action does something else:

1. In the Livewire request it validates the choice, then stores a hand-over in the cache under a random 48-character
   token (`streaming.ttl` seconds, default 120) and redirects the browser to a **temporary signed URL**.
2. That plain HTTP request checks the signature, that the logged-in user is the one the token was issued to, and spends
   the token (**one download per link**). It then streams the workbook to `php://output` with `lazy()` — nothing is
   buffered, so memory stays flat at any size (a test exports 20 000 rows with a memory bound).

```php
XlsxExportAction::make()->streamed();        // always stream
XlsxExportAction::make()->streamed(false);   // never; stay in Livewire (bound by row_limit)
```

In streaming mode `row_limit` does not apply; `streaming.hard_cap` (default 500 000, `null` = none) does.

**How the query is rebuilt outside Livewire, and the trade-off.** A query cannot be serialised soundly: eager loads, casts
and the table's columns (closures) are code, and replaying SQL plus bindings would lose them. Storing the filtered
primary keys would work for the rows but not for the columns, and needs a cap. So nothing about the query is stored;
what is stored is the component's own Livewire **snapshot** (filters, search, sort, selected keys, mount state, signed
with your app key), the action's name and the modal's values. The route rehydrates the component, runs its lifecycle
hooks, finds the action by name and asks the table for `getFilteredSortedTableQuery()` (or, for the bulk action,
`getSelectedTableRecordsQuery()`) — the same query the table would build itself. The cost:

- The component must be rehydratable from its public properties alone. That holds for resource list pages and for plain
  table components. A component whose table depends on request state (route parameters read in `table()`, a tenant
  resolved from the URL, a query-string value) will see the download request instead of the page request. Force
  `->streamed(false)` on such tables.
- The panel is restored from the id stored with the token; the rehydrated component sees the logged-in user, but not the
  page's route. Panel multi-tenancy is not carried over.
- The action must be reachable by name from the rehydrated component (table header/toolbar/bulk actions, or the page's
  header actions, groups included). A renamed action is fine; one created on the fly is not.
- State changes between click and download (a few seconds) are not seen: the snapshot is the state at the click.
- The token store is the default cache store; use a shared one (Redis, database) behind several servers.

Route options live under `streaming` in the config: `register_route` (set `false` to register your own route to
`DownloadController`), `path`, `middleware` (default `['web']`; it must start the session and see the panel's guard —
`signed` is always appended).

## Typed cells for core exporters

Core's queued `Exporter` writes CSV files and then copies them into an XLSX as strings; the plugin cannot change the
queued job, but core exposes the hooks, so a trait covers the common case:

```php
use Asignua\FilamentXlsxExport\Concerns\ExportsTypedXlsx;

class OrderExporter extends Exporter
{
    use ExportsTypedXlsx;

    public function xlsxColumnFormats(): array
    {
        return [
            'total'      => ColumnFormat::make()->money(),
            'created_at' => ColumnFormat::make()->dateTime(),
            'paid'       => ColumnFormat::make()->boolean(),
        ];
    }
}
```

There is deliberately **no auto-detection**: a CSV cell `00123` or `1e5` is indistinguishable from a number, and guessing
would silently corrupt zip codes, phone numbers and IDs. Columns you declare are converted from the CSV text back into numbers, dates and booleans while the workbook is written;
the rest stay text. The header row stays text, and the trait adds widths, a frozen header and a filter. It is still
queued and still goes through CSV — that is core's design. What it cannot do: format a value that the CSV already lost
(a number rounded by `formatStateUsing()`), and it needs the column's CSV text to parse (`Y-m-d H:i:s` for dates).

## Configuration

`config/filament-xlsx-export.php`: `row_limit`, `streaming.*`, `chunk_size`, `header.bold`, `header.background`, `freeze_header`,
`auto_filter`, `formats.*` (Excel number formats for dates, date-times, times and money), `width.min`, `width.max`,
`total_label`. Everything is a plain value, so the config can be cached.

## Gotchas

- **Livewire mode buffers the file.** Only the streaming mode (see above) avoids it; that is what `row_limit` protects.
- **Money in cents.** The exporter cannot see `->money(divideBy: 100)`; declare `ColumnFormat::make()->divideBy(100)`.
- **Dates have no timezone in Excel.** A date-time is written as the wall time in the column's timezone
  (`->timezone()`, else `app.timezone`).
- **Numbers over 15 digits** (IBANs, card numbers) stay text; Excel keeps 15 significant digits.
- **Long text** is cut at 32 767 characters, Excel's cell limit.
- **Sorting a chunked read.** Rows are read in pages; with no explicit sort the plugin orders by the primary key so pages
  do not overlap.
- Tables without an Eloquent query (array or API data sources) are not supported.

## Translations

The interface ships in English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and
Turkish under the `filament-xlsx-export::xlsx-export` namespace. A test keeps every language in step with the English
keys and placeholders. Override a string by publishing the translations (`--tag=filament-xlsx-export-translations`).

## AI agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines (`resources/boost/guidelines/core.blade.php`)
that describe the actions, `ColumnFormat` and the options, so a coding agent wires it up correctly.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

The suite runs on [Orchestra Testbench](https://packages.tools/testbench) with a `workbench/` panel and an `Order`
resource. Tests read the generated workbook back from its XML and assert cell types, values and number formats.

## Changelog

See [CHANGELOG.md](https://github.com/asignua/filament-xlsx-export/blob/main/CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](https://github.com/asignua/filament-xlsx-export/blob/main/LICENSE.md).
