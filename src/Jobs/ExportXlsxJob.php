<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Jobs;

use Asignua\FilamentXlsxExport\Support\ExportTooLarge;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Builds one export in the background: rehydrates the table component from the snapshot captured
 * at the click, asks it for the same query the table would build, writes the workbook with the
 * ordinary exporter, stores it and tells the user.
 *
 * The payload is plain data (see `StreamedExports::capture()`); the closures of the action —
 * columns, formats, title, footer, `queryUsing()` — are code and are found again by name on the
 * rehydrated component. One try: a half-written workbook is rebuilt from scratch by the user's
 * next click, not by a retry that would notify twice.
 */
final class ExportXlsxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly string $id, public readonly array $payload)
    {
        $this->timeout = QueuedExports::timeoutFor(null);
    }

    public function handle(): void
    {
        try {
            QueuedExports::within($this->payload, $this->build(...));
        } finally {
            QueuedExports::release($this->payload, $this->id);
        }
    }

    private function build(?Authenticatable $user): void
    {
        if ($user === null) {
            Log::warning("filament-xlsx-export: export {$this->id} dropped: its user or panel is gone, or the user can no longer open the panel.");

            return;
        }

        $component = StreamedExports::rehydrate($this->snapshot());
        $action = StreamedExports::findAction($component, $this->string('action'), (bool) ($this->payload['bulk'] ?? false), $this->tableFlag());

        if ($action === null || !method_exists($action, 'queuedExport')) {
            throw new RuntimeException("The export action \"{$this->string('action')}\" was not found on the rehydrated component.");
        }

        if (method_exists($action, 'isVisible') && !$action->isVisible()) {
            Log::warning("filament-xlsx-export: export {$this->id} dropped: the action is no longer visible to the user.");

            return;
        }

        $query = ($this->payload['bulk'] ?? false)
            ? $component->getSelectedTableRecordsQuery()
            : $component->getFilteredSortedTableQuery();

        if ($query === null) {
            throw new RuntimeException('The rehydrated table has no query.');
        }

        // Inside storage/, where `xlsx-export:prune` also looks: a killed worker cannot clean up after itself.
        $temporary = QueuedExports::temporaryFile();

        try {
            /** @var array<string, mixed> $data */
            $data = is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];
            /** @var array{file_name: string, rows: int} $result */
            $result = $action->queuedExport($component, $query, $data, $temporary);

            QueuedExports::store($this->id, $temporary, $result['file_name'], $result['rows'], $this->payload);
        } catch (ExportTooLarge $exception) {
            QueuedExports::notifyTooLarge($user, $this->id, $exception);

            return;
        } finally {
            @unlink($temporary);
        }

        $root = $this->payload['root'] ?? null;

        QueuedExports::notifyReady($user, $this->id, $result['file_name'], $result['rows'], is_string($root) ? $root : null);
    }

    public function failed(Throwable $exception): void
    {
        QueuedExports::release($this->payload, $this->id);
        QueuedExports::disk()->delete([QueuedExports::filePath($this->id), QueuedExports::metaPath($this->id)]);

        QueuedExports::within($this->payload, function (?Authenticatable $user): void {
            if ($user !== null) {
                QueuedExports::notifyFailed($user, $this->id);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $snapshot = $this->payload['snapshot'] ?? null;

        if (!is_array($snapshot)) {
            throw new RuntimeException('The export payload has no snapshot.');
        }

        /** @var array<string, mixed> */
        return $snapshot;
    }

    private function string(string $key): string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function tableFlag(): ?bool
    {
        $flag = $this->payload['table'] ?? null;

        return is_bool($flag) ? $flag : null;
    }
}
