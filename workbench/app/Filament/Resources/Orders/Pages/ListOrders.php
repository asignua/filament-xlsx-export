<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Orders\Pages;

use Closure;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Orders\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Replaces the header actions in a test (static: Livewire rebuilds them on every call). */
    public static ?Closure $header = null;

    protected function getHeaderActions(): array
    {
        return static::$header !== null ? (static::$header)() : [OrderResource::exportAction()];
    }
}
