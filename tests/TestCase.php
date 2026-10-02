<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests;

use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\XlsxExportServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;
use Workbench\App\Providers\TenantPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            XlsxExportServiceProvider::class,
            AdminPanelProvider::class,
            TenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function makeOrder(string $name, array $attributes = []): Order
    {
        /** @var Order */
        return Order::query()->create(['name' => $name, ...$attributes]);
    }

    /**
     * The workbook a Livewire download effect carries.
     *
     * @param Testable $component
     */
    protected function downloadedWorkbook($component): Workbook
    {
        $download = $component->effects['download'] ?? null;
        $this->assertIsArray($download, 'The component did not return a download.');

        return Workbook::fromBinary((string) base64_decode((string) $download['content'], true));
    }
}
