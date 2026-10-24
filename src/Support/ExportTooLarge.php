<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use RuntimeException;

/**
 * The table grew past the queued cap between the click and the job. Not a failure to retry: the
 * user is told the numbers instead.
 */
final class ExportTooLarge extends RuntimeException
{
    public function __construct(public readonly int $count, public readonly int $limit)
    {
        parent::__construct("The export has {$count} rows, the limit is {$limit}.");
    }
}
