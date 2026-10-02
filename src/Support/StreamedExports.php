<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

use function Livewire\trigger;

/**
 * The hand-over between the Livewire request that was asked for a file and the plain HTTP request
 * that streams it.
 *
 * Replaying a query outside Livewire is the hard part: eager loads, casts and the table's own
 * columns (closures, not data) cannot be serialised. So nothing about the query is stored. What
 * is stored is the component's own signed snapshot — its filters, search, sort, selected keys and
 * mount state — plus the action's name and the modal's values. The route rehydrates the component
 * and asks it for the same query the table itself would build.
 *
 * The token is random, lives in the cache for `streaming.ttl` seconds, belongs to one user and
 * is spent by the first download.
 */
final class StreamedExports
{
    public const string ROUTE = 'filament-xlsx-export.download';

    private const string PREFIX = 'filament-xlsx-export:';

    /**
     * @param array<string, mixed> $data        the export modal's values
     * @param bool|null            $tableAction whether the action belongs to the table (true) or
     *                                          to the page's header (false); null searches both
     */
    public static function issue(HasTable $livewire, string $action, bool $bulk, array $data, ?bool $tableAction = null): string
    {
        $token = Str::random(48);
        $panel = Filament::getCurrentPanel();
        // A table outside any panel has no panel guard; Filament::getAuthGuard() would fall back to
        // the default panel (or throw without one), whose guard may not be the page's.
        $guard = $panel?->getAuthGuard() ?? Auth::getDefaultDriver();
        $ttl = self::ttl();

        Cache::put(self::PREFIX.$token, [
            'snapshot' => self::snapshot($livewire),
            'action' => $action,
            'bulk' => $bulk,
            'table' => $bulk ? true : $tableAction,
            'data' => $data,
            'guard' => $guard,
            'user' => Auth::guard($guard)->id(),
            'panel' => $panel?->getId(),
            'locale' => app()->getLocale(),
            'back' => self::backUrl(),
        ], $ttl + 30);

        return URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($ttl), ['token' => $token]);
    }

    /**
     * Whether a panel can stream at all. A panel with tenancy cannot: its tenant comes from the
     * page's URL and the tenant middleware, neither of which the download request has, and
     * Filament's tenant scope does nothing without a current tenant — the file would hold every
     * tenant's rows. Such panels stay in Livewire mode.
     */
    public static function supportsPanel(?Panel $panel): bool
    {
        return !($panel?->hasTenancy() ?? false);
    }

    /**
     * Where the user is sent back to when a link cannot be used: the page that asked for the
     * file (from the Livewire request's own same-origin Referer), else null.
     */
    private static function backUrl(): ?string
    {
        $referer = request()->headers->get('referer');

        if (!is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return $host === request()->getHost() ? $referer : null;
    }

    private static function ttl(): int
    {
        return max(10, (int) config('filament-xlsx-export.streaming.ttl', 120));
    }

    /**
     * What Livewire itself would send to the browser after this request: the `dehydrate` hooks
     * run first (they add the release token and the children memo that verifying a snapshot
     * needs), then the snapshot is taken with their context. Hook effects land in a throw-away
     * context, never in the real response.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(HasTable $livewire): array
    {
        $context = new ComponentContext($livewire);

        trigger('dehydrate', $livewire, $context);

        /** @var array<string, mixed> */
        return app(HandleComponents::class)->snapshot($livewire, $context);
    }

    /**
     * @return array{snapshot: array<string, mixed>, action: string, bulk: bool, table?: bool|null, data: array<string, mixed>, guard: string, user: int|string|null, panel: string|null, locale?: string|null, back?: string|null}|null
     */
    public static function peek(string $token): ?array
    {
        $payload = Cache::get(self::PREFIX.$token);

        /** @var array{snapshot: array<string, mixed>, action: string, bulk: bool, table?: bool|null, data: array<string, mixed>, guard: string, user: int|string|null, panel: string|null, locale?: string|null, back?: string|null}|null */
        return is_array($payload) ? $payload : null;
    }

    /**
     * Spends the token. False when somebody else got there first.
     *
     * `Cache::pull()` alone is a get followed by a forget, so two simultaneous requests (a double
     * click, a download manager retrying) could both see the payload. `Cache::add()` is atomic on
     * every store that supports it (Redis, database, Memcached, array, file): only the first request
     * plants the marker.
     */
    public static function spend(string $token): bool
    {
        if (!Cache::add(self::PREFIX.$token.':spent', true, self::ttl() + 30)) {
            return false;
        }

        return Cache::pull(self::PREFIX.$token) !== null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function rehydrate(array $snapshot): HasTable
    {
        [$component, $context] = app(HandleComponents::class)->fromSnapshot($snapshot);

        // What a normal Livewire update does between hydrating the properties and calling
        // anything: the `hydrate` hooks run `boot()` / `booted()`, which is where a Filament
        // component builds its table.
        $memo = is_array($snapshot['memo'] ?? null) ? $snapshot['memo'] : [];
        trigger('hydrate', $component, $memo, $context);

        abort_unless($component instanceof HasTable, 410);

        return $component;
    }

    /**
     * @param bool|null $tableAction true: only the table's actions; false: only the page's header
     *                               actions (a page action and a table action may share a name);
     *                               null: both, the table's first
     */
    public static function findAction(HasTable $livewire, string $name, bool $bulk, ?bool $tableAction = null): ?object
    {
        $table = $livewire->getTable();
        $candidates = [];

        if ($bulk) {
            $candidates = $table->getFlatBulkActions();
        } else {
            if ($tableAction !== false) {
                $candidates = [...$table->getFlatActions(), ...self::flatten($table->getHeaderActions())];
            }

            if ($tableAction !== true && method_exists($livewire, 'getCachedHeaderActions')) {
                $candidates = [...$candidates, ...self::flatten($livewire->getCachedHeaderActions())];
            }
        }

        foreach ($candidates as $candidate) {
            if (method_exists($candidate, 'streamExport') && method_exists($candidate, 'getName') && $candidate->getName() === $name) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $actions
     *
     * @return list<object>
     */
    private static function flatten(array $actions): array
    {
        $flat = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                array_push($flat, ...self::flatten($action->getFlatActions()));
            } elseif (is_object($action)) {
                $flat[] = $action;
            }
        }

        return $flat;
    }
}
