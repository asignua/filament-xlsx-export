<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport;

use Asignua\FilamentXlsxExport\Commands\PruneCommand;
use Asignua\FilamentXlsxExport\Http\DownloadController;
use Asignua\FilamentXlsxExport\Http\QueuedDownloadController;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class XlsxExportServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-xlsx-export';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommand(PruneCommand::class);
    }

    public function packageBooted(): void
    {
        $this->registerStreamingRoute();
        $this->registerQueuedRoute();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (!(bool) config('filament-xlsx-export.queue.schedule.enabled', true)) {
                return;
            }

            $time = config('filament-xlsx-export.queue.schedule.time', '03:50');

            $schedule->command('xlsx-export:prune')->dailyAt(is_string($time) ? $time : '03:50');
        });
    }

    private function registerStreamingRoute(): void
    {
        if (!config('filament-xlsx-export.streaming.register_route', true)) {
            return;
        }

        // No `signed` middleware: DownloadController checks the signature itself (a forged link
        // gets a 403, an expired one a notification back in the panel).
        Route::middleware((array) config('filament-xlsx-export.streaming.middleware', ['web']))
            ->get((string) config('filament-xlsx-export.streaming.path', 'filament-xlsx-export/download/{token}'), DownloadController::class)
            ->where('token', '[A-Za-z0-9]{48}')
            ->name(StreamedExports::ROUTE);
    }

    private function registerQueuedRoute(): void
    {
        if (!config('filament-xlsx-export.queue.register_route', true)) {
            return;
        }

        Route::middleware((array) config('filament-xlsx-export.queue.middleware', ['web']))
            ->get((string) config('filament-xlsx-export.queue.path', 'filament-xlsx-export/file/{id}'), QueuedDownloadController::class)
            ->where('id', QueuedExports::ID_PATTERN)
            ->name(QueuedExports::ROUTE);
    }
}
