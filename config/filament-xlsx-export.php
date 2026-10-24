<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Row limit (Livewire mode)
    |--------------------------------------------------------------------------
    |
    | The largest number of data rows one in-request export may contain. Livewire keeps a
    | download in memory (it is base64-encoded into the response), so this is a
    | safety net for the PHP worker, not a spreadsheet limit. Above it the user
    | gets a notification instead of a file. Set to null to disable the check.
    | Override per action with ->rowLimit().
    |
    */
    'row_limit' => 25_000,

    /*
    |--------------------------------------------------------------------------
    | Streaming mode
    |--------------------------------------------------------------------------
    |
    | Above `above_rows` the action does not build the file inside the Livewire request. It
    | hands the browser a short-lived signed URL; that plain HTTP request rehydrates the table
    | and streams the workbook straight to the client, so nothing is buffered. Force it per
    | action with ->streamed() / ->streamed(false). `hard_cap` is the limit that applies to
    | streamed exports (null = none); `row_limit` then applies only to Livewire mode.
    | `ttl` is how long the link works, in seconds. Middleware must start the session; the
    | controller switches the default guard to the panel's guard itself. The route does NOT run the panel's own
    | middleware: add here whatever per-request scoping or settings your panel middleware
    | applies (the locale of the click is restored by the plugin itself).
    |
    */
    'streaming' => [
        'enabled' => true,
        'above_rows' => 5_000,
        'hard_cap' => 500_000,
        'ttl' => 120,
        'register_route' => true,
        'path' => 'filament-xlsx-export/download/{token}',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued mode (->queue() / ->queueWhenMoreThan())
    |--------------------------------------------------------------------------
    |
    | An action that opts in builds its file in a background job and tells the user through a
    | Filament database notification (the panel needs ->databaseNotifications()) with a signed
    | download link. `enabled` = false makes every queued action synchronous again.
    | `connection` / `queue` are the defaults for actions that do not name their own (null = the
    | app's). The file goes to `disk` / `directory`; it and its link live `retention_hours`, then
    | `xlsx-export:prune` deletes it (scheduled daily at `schedule.time` while `schedule.enabled`).
    | `hard_cap` is the row limit of a queued export (null = none). `timeout` is the job's, in
    | seconds. The route needs a session (`middleware`); its signed URL is built from APP_URL.
    |
    */
    'queue' => [
        'enabled' => true,
        'connection' => null,
        'queue' => null,
        'disk' => 'local',
        'directory' => 'filament-xlsx-exports',
        'retention_hours' => 24,
        'hard_cap' => 1_000_000,
        'timeout' => 3600,
        'register_route' => true,
        'path' => 'filament-xlsx-export/file/{id}',
        'middleware' => ['web'],
        'schedule' => [
            'enabled' => true,
            'time' => '03:50',
        ],
    ],

    /*
    | How many rows are read from the database at a time.
    */
    'chunk_size' => 500,

    'header' => [
        'bold' => true,
        'background' => 'E5E7EB',
    ],

    'freeze_header' => true,
    'auto_filter' => true,

    /*
    | Excel number formats used when a column does not declare its own.
    */
    'formats' => [
        'date' => 'yyyy-mm-dd',
        'date_time' => 'yyyy-mm-dd hh:mm',
        'time' => 'hh:mm',
        'money' => '#,##0.00',
    ],

    /*
    | Column widths, in characters. A column without an explicit ->width() gets
    | its header label length plus padding, clamped to this range (a stream
    | cannot look at the data first).
    */
    'width' => [
        'min' => 8,
        'max' => 60,
    ],

    'total_label' => null,
];
