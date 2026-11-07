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

        $cutoff = $hours > 0 ? now()->subHours($hours)->getTimestamp() : null;

        if ($cutoff === null) {
            $deleted = $this->pruneLimited();
            $this->components->info('Retention is off (hours = 0); '.$deleted.' export(s) past their own time limit deleted.');

            return self::SUCCESS;
        }

        // Half-written files of workers that were killed mid-export.
        foreach (glob(QueuedExports::temporaryDirectory().'/xlsx-export-*') ?: [] as $leftover) {
            if (is_file($leftover) && filemtime($leftover) < $cutoff) {
                @unlink($leftover);
            }
        }

        $disk = QueuedExports::disk();
        $deleted = 0;

        foreach ($disk->files(QueuedExports::directory()) as $file) {
            if (!preg_match('/\.(xlsx|json)$/', $file) || $disk->lastModified($file) >= $cutoff) {
                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        $deleted += $this->pruneLimited();

        $this->components->info("Deleted {$deleted} export file(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }

    /**
     * Exports a plugin stored with its own time limit (`keep_until` in the sidecar): gone after it
     * whatever the retention is, including "never".
     */
    private function pruneLimited(): int
    {
        $disk = QueuedExports::disk();
        $deleted = 0;

        foreach ($disk->files(QueuedExports::directory()) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }

            $meta = json_decode((string) $disk->get($file), true);

            if (is_array($meta) && is_int($meta['keep_until'] ?? null) && $meta['keep_until'] < now()->getTimestamp()) {
                $disk->delete([$file, substr($file, 0, -5).'.xlsx']);
                $deleted++;
            }
        }

        return $deleted;
    }
}
