<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Asignua\FilamentXlsxExport\Jobs\ExportXlsxJob;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the queued mode shares between the action (dispatch), the job (build, store, tell)
 * and the download route (check, stream).
 *
 * A finished file is `<directory>/<id>.xlsx` plus `<id>.json` next to it: who it belongs to, the
 * download name, the panel. A sidecar instead of a table, so the plugin needs no migration and the
 * prune command works on the disk alone. The id is a random ULID; the file is reachable only
 * through a signed, expiring URL that also checks the owner.
 */
final class QueuedExports
{
    public const string ROUTE = 'filament-xlsx-export.file';

    public const string ID_PATTERN = '[0-9a-z]{26}';

    /**
     * @param array<string, mixed> $payload `StreamedExports::capture()`
     */
    public static function dispatch(array $payload, ?string $connection = null, ?string $queue = null): string
    {
        $id = strtolower((string) Str::ulid());
        $job = new ExportXlsxJob($id, $payload);

        $connection ??= self::string(config('filament-xlsx-export.queue.connection'));
        $queue ??= self::string(config('filament-xlsx-export.queue.queue'));

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return $id;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::string(config('filament-xlsx-export.queue.disk')) ?? 'local');
    }

    public static function directory(): string
    {
        return trim(self::string(config('filament-xlsx-export.queue.directory')) ?? 'filament-xlsx-exports', '/');
    }

    public static function retentionHours(): int
    {
        return max(1, (int) config('filament-xlsx-export.queue.retention_hours', 24));
    }

    public static function filePath(string $id): string
    {
        return self::directory().'/'.$id.'.xlsx';
    }

    public static function metaPath(string $id): string
    {
        return self::directory().'/'.$id.'.json';
    }

    /**
     * What the download route and the notification need: one place that knows how to log in as the
     * person who clicked. The job has no session and no panel middleware, so the guard, the user,
     * the panel and the locale are restored by hand, as the streaming route does.
     *
     * @param array<string, mixed> $payload
     */
    public static function actAs(array $payload): ?Authenticatable
    {
        $locale = $payload['locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        $guard = is_string($payload['guard'] ?? null) ? $payload['guard'] : Auth::getDefaultDriver();
        Auth::shouldUse($guard);

        $panelId = $payload['panel'] ?? null;

        if (is_string($panelId)) {
            try {
                $panel = Filament::getPanel($panelId);
            } catch (Throwable) {
                return null;
            }

            if (!StreamedExports::supportsPanel($panel)) {
                return null;
            }

            Filament::setCurrentPanel($panel);
            Filament::bootCurrentPanel();
        }

        $id = $payload['user'] ?? null;
        $driver = Auth::guard($guard);
        $provider = method_exists($driver, 'getProvider') ? $driver->getProvider() : null;
        $user = $id !== null ? $provider?->retrieveById($id) : null;

        if ($user instanceof Authenticatable) {
            $driver->setUser($user);
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function store(string $id, string $localPath, string $fileName, int $rows, array $payload): void
    {
        $disk = self::disk();
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Cannot read the finished export at {$localPath}.");
        }

        try {
            $disk->put(self::filePath($id), $stream);
        } finally {
            fclose($stream);
        }

        $disk->put(self::metaPath($id), (string) json_encode([
            'user' => $payload['user'] ?? null,
            'guard' => $payload['guard'] ?? null,
            'panel' => $payload['panel'] ?? null,
            'file_name' => $fileName,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{user: int|string|null, guard: string|null, panel: string|null, file_name: string, rows: int}|null
     */
    public static function meta(string $id): ?array
    {
        $disk = self::disk();

        if (!$disk->exists(self::metaPath($id)) || !$disk->exists(self::filePath($id))) {
            return null;
        }

        $meta = json_decode((string) $disk->get(self::metaPath($id)), true);

        if (!is_array($meta) || !is_string($meta['file_name'] ?? null)) {
            return null;
        }

        /** @var array{user: int|string|null, guard: string|null, panel: string|null, file_name: string, rows: int} */
        return $meta;
    }

    public static function url(string $id): string
    {
        return URL::temporarySignedRoute(self::ROUTE, now()->addHours(self::retentionHours()), ['id' => $id]);
    }

    public static function notifyReady(Authenticatable $user, string $id, string $fileName, int $rows): void
    {
        $notification = Notification::make()
            ->success()
            ->title(__('filament-xlsx-export::xlsx-export.ready_title'))
            ->body(__('filament-xlsx-export::xlsx-export.ready_body', ['file' => $fileName, 'count' => number_format($rows)]))
            ->actions([
                Action::make('download')
                    ->label(__('filament-xlsx-export::xlsx-export.download'))
                    ->url(self::url($id))
                    ->button()
                    ->markAsRead(),
            ]);

        self::send($notification, $user, "export {$id} is ready ({$fileName})");
    }

    public static function notifyFailed(Authenticatable $user, string $id): void
    {
        self::send(
            Notification::make()
                ->danger()
                ->title(__('filament-xlsx-export::xlsx-export.failed_title'))
                ->body(__('filament-xlsx-export::xlsx-export.failed_body')),
            $user,
            "export {$id} failed",
        );
    }

    public static function notifyTooLarge(Authenticatable $user, string $id, ExportTooLarge $exception): void
    {
        self::send(
            Notification::make()
                ->warning()
                ->title(__('filament-xlsx-export::xlsx-export.too_many_rows_title'))
                ->body(__('filament-xlsx-export::xlsx-export.too_many_rows_body', [
                    'count' => number_format($exception->count),
                    'limit' => number_format($exception->limit),
                ])),
            $user,
            "export {$id} refused: {$exception->getMessage()}",
        );
    }

    /**
     * Into the user's database notifications — the only channel a job has (a toast needs a
     * session). A panel without `->databaseNotifications()` has nowhere to show it: the file is
     * still written, and a log line says so. See the README.
     */
    private static function send(Notification $notification, Authenticatable $user, string $what): void
    {
        if (!(Filament::getCurrentPanel()?->hasDatabaseNotifications() ?? false) || !$user instanceof Model) {
            Log::info("filament-xlsx-export: {$what}, but the panel has no database notifications, so nobody was notified.");

            return;
        }

        $notification->sendToDatabase($user);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
