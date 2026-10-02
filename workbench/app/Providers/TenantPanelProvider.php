<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Workbench\App\Models\Team;

/**
 * A panel with tenancy, so tests can check that streaming never runs where the download request
 * could not carry the tenant.
 */
class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant')
            ->tenant(Team::class);
    }
}
