<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Asignua\FilamentXlsxExport\Jobs\ExportXlsxJob;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
        $connection ??= self::string(config('filament-xlsx-export.queue.connection'));
        $queue ??= self::string(config('filament-xlsx-export.queue.queue'));

        // The domain the click came from: a panel on its own domain needs the link signed for it,
        // and a worker has only APP_URL.
        $payload['root'] = request()->root();

        $job = new ExportXlsxJob($id, $payload);
        $job->timeout = self::timeoutFor($connection);

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        self::reserve($payload, $id);
        dispatch($job);

        return $id;
    }

    /**
     * The job's timeout, kept under the connection's `retry_after`. With `tries = 1` a job that runs
     * longer than `retry_after` is picked up by a second worker and failed while the first one is
     * still writing — the user would get "failed" and then "ready". Killing the job first turns
     * that into one clean failure.
     */
    public static function timeoutFor(?string $connection): int
    {
        $timeout = max(30, (int) config('filament-xlsx-export.queue.timeout', 600));
        $retryAfter = config('queue.connections.'.($connection ?? config('queue.default')).'.retry_after');

        return is_numeric($retryAfter) ? max(10, min($timeout, (int) $retryAfter - 10)) : $timeout;
    }

    /**
     * Whether this user may start another export: at most `queue.max_per_user` unfinished ones
     * (0 = no limit). A slot is held until the job finishes or fails, or for its timeout plus a
     * minute — a killed worker cannot free it.
     *
     * @param array<string, mixed> $payload
     */
    public static function hasFreeSlot(array $payload): bool
    {
        $max = (int) config('filament-xlsx-export.queue.max_per_user', 2);

        return $max <= 0 || count(self::active($payload)) < $max;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function reserve(array $payload, string $id): void
    {
        self::withSlots($payload, static function (array $slots) use ($id): array {
            $slots[$id] = time() + self::timeoutFor(null) + 60;

            return $slots;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function release(array $payload, string $id): void
    {
        self::withSlots($payload, static function (array $slots) use ($id): array {
            unset($slots[$id]);

            return $slots;
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, int>
     */
    private static function active(array $payload): array
    {
        $slots = Cache::get(self::slotsKey($payload), []);

        return array_filter(is_array($slots) ? $slots : [], static fn (mixed $until): bool => is_int($until) && $until > time());
    }

    /**
     * @param array<string, mixed>                            $payload
     * @param Closure(array<string, int>): array<string, int> $change
     */
    private static function withSlots(array $payload, Closure $change): void
    {
        $key = self::slotsKey($payload);
        $apply = static fn () => Cache::put($key, $change(self::active($payload)), 86400);

        try {
            Cache::lock($key.':lock', 5)->block(3, $apply);
        } catch (Throwable) {
            $apply();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function slotsKey(array $payload): string
    {
        return 'filament-xlsx-export:slots:'.($payload['guard'] ?? '').':'.($payload['user'] ?? '');
    }

    public static function temporaryDirectory(): string
    {
        return storage_path('framework/cache/filament-xlsx-export');
    }

    public static function temporaryFile(): string
    {
        $dir = self::temporaryDirectory();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        $path = tempnam($dir, 'xlsx-export-');

        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for the export.');
        }

        return $path;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::string(config('filament-xlsx-export.queue.disk')) ?? 'local');
    }

    public static function directory(): string
    {
        return trim(self::string(config('filament-xlsx-export.queue.directory')) ?? 'filament-xlsx-exports', '/');
    }

    /**
     * Hours a finished file lives; 0 = never pruned.
     */
    public static function retentionHours(): int
    {
        return max(0, (int) config('filament-xlsx-export.queue.retention_hours', 24));
    }

    /**
     * How long a download link works: as long as the file lives, or 30 days when files are kept for ever.
     */
    private static function linkHours(): int
    {
        return self::retentionHours() ?: 24 * 30;
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
     * Runs `$callback` as the person who clicked, then puts the worker back as it was. The job has
     * no session and no panel middleware, so the locale, the guard, the user and the panel are set
     * by hand — and, because a worker runs the next job in the same process, undone afterwards
     * (otherwise an unrelated job would run as the exporter, in their language).
     *
     * The callback gets null when the user is gone, the panel is gone or tenancy, or the user can no
     * longer open the panel.
     *
     * @param array<string, mixed>                 $payload
     * @param Closure(Authenticatable|null): mixed $callback
     */
    public static function within(array $payload, Closure $callback): mixed
    {
        $guardName = is_string($payload['guard'] ?? null) ? $payload['guard'] : Auth::getDefaultDriver();
        $previousLocale = app()->getLocale();
        $previousDefault = Auth::getDefaultDriver();
        $previousPanel = Filament::getCurrentPanel();
        $driver = Auth::guard($guardName);
        $previousUser = $driver->hasUser() ? $driver->user() : null;

        try {
            return $callback(self::actAs($payload, $guardName));
        } finally {
            app()->setLocale($previousLocale);
            Auth::shouldUse($previousDefault);
            Filament::setCurrentPanel($previousPanel);

            if ($previousUser !== null) {
                $driver->setUser($previousUser);
            } elseif (method_exists($driver, 'forgetUser')) {
                $driver->forgetUser();
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function actAs(array $payload, string $guard): ?Authenticatable
    {
        $locale = $payload['locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        Auth::shouldUse($guard);

        $panel = null;
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

        if (!$user instanceof Authenticatable) {
            return null;
        }

        // The click was allowed then; the job runs later. Somebody who lost the panel meanwhile gets nothing.
        if ($panel !== null && $user instanceof FilamentUser && !$user->canAccessPanel($panel)) {
            return null;
        }

        $driver->setUser($user);

        // The panel's own middleware does not run in a worker: scoping it would have applied
        // (a tenant-like filter, a setting) is the app's to re-apply here.
        $before = config('filament-xlsx-export.queue.before');

        if (is_callable($before)) {
            $before($user, $panel);
        }

        return $user;
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

    public static function url(string $id, ?string $root = null): string
    {
        // A worker has no request, so the URL generator falls back to APP_URL; a panel on its own
        // domain needs the link signed for that domain. The forced root and scheme are undone straight away.
        if ($root !== null && $root !== '') {
            URL::forceRootUrl($root);
            URL::forceScheme(parse_url($root, PHP_URL_SCHEME) ?: null);
        }

        try {
            return URL::temporarySignedRoute(self::ROUTE, now()->addHours(self::linkHours()), ['id' => $id]);
        } finally {
            if ($root !== null && $root !== '') {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        }
    }

    public static function notifyReady(Authenticatable $user, string $id, string $fileName, int $rows, ?string $root = null): void
    {
        $notification = Notification::make()
            ->success()
            ->title(__('filament-xlsx-export::xlsx-export.ready_title'))
            ->body(__('filament-xlsx-export::xlsx-export.ready_body', ['file' => $fileName, 'count' => number_format($rows)]))
            ->actions([
                Action::make('download')
                    ->label(__('filament-xlsx-export::xlsx-export.download'))
                    ->url(self::url($id, $root))
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
