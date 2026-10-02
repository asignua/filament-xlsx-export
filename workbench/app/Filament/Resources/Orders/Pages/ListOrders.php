<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Orders\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [OrderResource::exportAction()];
    }
}
