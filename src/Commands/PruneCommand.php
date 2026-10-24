<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Commands;

use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Illuminate\Console\Command;

/**
 * Deletes finished queued exports (the workbook and its sidecar) once they are older than the
 * retention. The download links expire at the same moment, so nothing points at a deleted file.
 */
class PruneCommand extends Command
{
    protected $signature = 'xlsx-export:prune {--hours= : Keep files this many hours instead of filament-xlsx-export.queue.retention_hours}';

    protected $description = 'Delete queued XLSX exports older than the retention period';

    public function handle(): int
    {
        $option = $this->option('hours');
        $hours = is_numeric($option) ? (int) $option : QueuedExports::retentionHours();

        if ($hours <= 0) {
            $this->components->info('Retention is off (hours = 0), nothing deleted.');

            return self::SUCCESS;
        }

        $disk = QueuedExports::disk();
        $cutoff = now()->subHours($hours)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files(QueuedExports::directory()) as $file) {
            if (!preg_match('/\.(xlsx|json)$/', $file) || $disk->lastModified($file) >= $cutoff) {
                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        $this->components->info("Deleted {$deleted} export file(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
