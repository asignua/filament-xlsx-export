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
    | `ttl` is how long the link works, in seconds. Middleware must start the session and
    | authenticate the same guard as the panel.
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
