<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport;

use Asignua\FilamentXlsxExport\Http\DownloadController;
use Asignua\FilamentXlsxExport\Support\StreamedExports;
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
            ->hasTranslations();
    }

    public function packageBooted(): void
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
}
