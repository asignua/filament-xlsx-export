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
all" across pages, with its deselections) into a query, never into a loaded collection. Like every core bulk action it honours
`->authorizeIndividualRecords()` and the table's `checkIfRecordIsSelectableUsing()`: refused rows are skipped as the file
streams (the row count used for the limits and `$rowCount` is taken before that check).

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
| `text()`, `boolean()` | force the cell type (`boolean()` writes FALSE for an empty CSV cell, so a nullable boolean reads FALSE for NULL) |
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

A guest (no logged-in user on the panel's guard, e.g. a public table outside any panel) never streams: the link is bound
to the user it was issued to, so such a table always stays in Livewire mode, bound by `row_limit`.

In streaming mode the config `row_limit` does not apply; `streaming.hard_cap` (default 500 000, `null` = none) does. An
explicit `->rowLimit(n)` on the action holds in both modes: when streaming, the lower of it and `hard_cap` applies.
The download request counts the rows again, so rows added between the click and the download cannot carry the file
past the cap. Grouped, `HAVING` and `UNION` queries (for example from `queryUsing()`) are counted by their result rows,
not by the size of their first group.

**Panels with tenancy never stream.** Filament scopes a tenant panel's queries through a global scope that does nothing
without a current tenant, and the tenant comes from the page's URL and the tenant middleware — neither of which the
download request has. A streamed file would therefore contain every tenant's rows. So on a panel with `->tenant(...)`
the action always stays in Livewire mode (bound by `row_limit`, even with `->streamed()`), and the route refuses a token
issued for such a panel.

**When a link cannot be used** — it expired (a slow click, a retry from the browser history), it was already used, it
belongs to another user, or the action cannot be found after rehydration — the user is sent back to the page that asked
for the file with a notification, not to an error page. A link with a forged or altered signature gets a 403. An error
while rows are already being streamed cannot be turned into a message any more (the response has started): the browser
gets a truncated file, and the exception is reported as usual.

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
- The panel is restored from the id stored with the token and booted (a table outside any panel gets no panel, and its
  user is checked against the app's default guard); the rehydrated component sees the logged-in user, but not the
  page's route. Panel tenancy is not carried over, which is why tenant panels never stream (see above).
- **The download route does not run the panel's middleware** — only `streaming.middleware`. The locale of the click is
  restored (from the token and from the component's snapshot), so labels match Livewire mode. Everything else a panel's
  persistent middleware does is not: `canAccessPanel()` is not re-checked within the link's ttl, and scopes or settings
  that your own panel middleware applies per request are missing. Add such middleware to `streaming.middleware`.
- The action must be reachable by name from the rehydrated component (table header/toolbar/bulk actions, or the page's
  header actions, groups included). A renamed action is fine; one created on the fly is not.
- State changes between click and download (a few seconds) are not seen: the snapshot is the state at the click.
- The token store is the default cache store; use a shared one (Redis, database) behind several servers.

Route options live under `streaming` in the config: `register_route`, `path`, `middleware` (default `['web']`; it only
needs to start the session; the controller itself switches the default guard to the panel's guard). The controller checks the URL signature itself. To register your own route
instead (`register_route => false`), keep its name and its `{token}` parameter, since the action generates the link with
`URL::temporarySignedRoute(StreamedExports::ROUTE, ...)`:

```php
Route::middleware(['web'])
    ->get('exports/{token}', \Asignua\FilamentXlsxExport\Http\DownloadController::class)
    ->where('token', '[A-Za-z0-9]{48}')
    ->name(\Asignua\FilamentXlsxExport\Support\StreamedExports::ROUTE); // 'filament-xlsx-export.download'
```

## Big exports in the background

Opt in per action and the click no longer waits for the file: a queued job builds it, then the user gets a Filament
database notification with a **Download** button.

```php
XlsxExportAction::make()->queue();                                   // always in the background
XlsxExportAction::make()->queue(connection: 'redis', queue: 'exports');
XlsxExportAction::make()->queueWhenMoreThan(10_000);                 // small exports stay instant
XlsxExportBulkAction::make()->queue();                               // same on the bulk action
```

The user sees a toast ("Export started, you will get a notification"); when the job finishes, the bell shows the file
with a signed link. A failure produces a danger notification instead, and a table that grew past the cap while the job
waited produces the "too many rows" one.

**Requirements.** The panel needs `->databaseNotifications()` and the `notifications` table; the user model must use
`Notifiable`; a queue worker must run (with `QUEUE_CONNECTION=sync` the job runs inside the click, which works but is
no faster). Without database notifications the file is still written, but nobody is told: the job logs one `info` line
instead — so turn them on. Panels with tenancy and guests stay synchronous (the job has no tenant and nobody to
notify), exactly like they never stream.

**How the query reaches the job — and why not by serializing it.** A query cannot be shipped soundly: eager loads,
casts and the table's columns are closures, and replaying SQL plus bindings would lose them. Filament core's own
export action serializes the builder with `anourvalar/eloquent-serialize` (`EloquentSerializeFacade`), which keeps
wheres, orders and eager-load names but not the table's state or closures; ours must reproduce the *screen* — filters, search,
sort, the bulk selection with "select all" and deselections, toggled columns. So the job gets the same hand-over as the
streaming route: the component's own signed Livewire **snapshot** (plain arrays), the action's name and the modal's
values. The worker rehydrates the component, finds the action by name and asks the table for
`getFilteredSortedTableQuery()` / `getSelectedTableRecordsQuery()` — the query the table itself would build. Title,
caption, footer, formats and `queryUsing()` are code and are evaluated there, with the same `$data`, `$livewire` and
`$rowCount` as in the synchronous path; the per-record bulk gates (`authorizeIndividualRecords()`,
`checkIfRecordIsSelectableUsing()`) run in the job under the clicking user. The trade-offs are the streaming mode's (see
above): the component must be rehydratable from its public properties, the panel's persistent middleware does not run,
and the state is the one at the click. The snapshot travels in the job payload, so a bulk selection of thousands
of hand-picked keys makes a payload of that size (a "select all" is only a flag plus the deselected keys).

**Where the file goes.** `queue.disk` (default `local`) under `queue.directory` (`filament-xlsx-exports/`), as
`<ulid>.xlsx` plus a small `<ulid>.json` (owner, download name) — no table, no migration. The link is a temporary signed
route (`queue.path`, built from `APP_URL`, so set it for workers) that streams the file only to the user it belongs to;
another user, a guest, a forged or expired link and a pruned file are sent back to the panel with a notification. The
link is not single-use (a notification can be clicked twice) and lives `queue.retention_hours` (24).

**Pruning.** `php artisan xlsx-export:prune [--hours=24]` deletes older files; the plugin schedules it daily at
`queue.schedule.time` (03:50) while `queue.schedule.enabled` is true — the app's scheduler (`schedule:run`) must run.
`--hours=0` deletes nothing.

Config (`queue.*`): `enabled` (false makes every queued action synchronous), `connection`, `queue`, `disk`,
`directory`, `retention_hours`, `hard_cap` (rows, default 1 000 000; also `->rowLimit(n)`, the lower holds), `timeout`
(job, seconds), `register_route`, `path`, `middleware`, `schedule.enabled`, `schedule.time`.

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
the rest stay text. Core writes an empty CSV cell for both `false` and `null`, so a `boolean()` column reads FALSE for NULL too (core turns `null` into an empty string, which `boolean()` reads as FALSE). If NULL must stay empty, drop `boolean()` and keep the column as text. The header row stays text, and the trait adds widths, a frozen header and a filter. It is still
queued and still goes through CSV — that is core's design. What it cannot do: format a value that the CSV already lost
(a number rounded by `formatStateUsing()`), and it needs the column's CSV text to parse (`Y-m-d H:i:s` for dates).

## Configuration

`config/filament-xlsx-export.php`: `row_limit`, `streaming.*`, `queue.*`, `chunk_size`, `header.bold`, `header.background`, `freeze_header`,
`auto_filter`, `formats.*` (Excel number formats for dates, date-times, times and money), `width.min`, `width.max`,
`total_label`. Everything is a plain value, so the config can be cached.

## Gotchas

- **Livewire mode buffers the file.** Only the streaming mode (see above) avoids it; that is what `row_limit` protects.
- **Money in cents.** The exporter cannot see `->money(divideBy: 100)`; declare `ColumnFormat::make()->divideBy(100)`.
- **Dates have no timezone in Excel.** A date-time is written as the wall time in the column's timezone
  (`->timezone()`, else `app.timezone`).
- **Numbers over 15 digits** (IBANs, card numbers) stay text; Excel keeps 15 significant digits.
- **Long text** is cut at 32 767 characters, Excel's cell limit.
- **Sorting a chunked read.** Rows are read in pages; unless the query already sorts by the primary key, the plugin adds
  it as the last sort column, so rows that tie on a non-unique sort are neither repeated nor skipped across pages. A
  grouped query (`GROUP BY`, `HAVING`, `UNION`) is left as it is, since the key is not a valid sort column there; give it a
  unique order yourself if it can span more than one page.
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
