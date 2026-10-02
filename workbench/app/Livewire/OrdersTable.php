<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\Actions\XlsxExportBulkAction;
use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Workbench\App\Filament\Resources\Orders\OrderResource;
use Workbench\App\Models\Order;

/**
 * A bare table over the Order resource's columns, so a test can mount the export actions with
 * any configuration (the closures are static because Livewire rebuilds the table on every call).
 */
class OrdersTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public static ?Closure $header = null;

    public static ?Closure $bulk = null;

    public function table(Table $table): Table
    {
        return OrderResource::table($table->query(Order::query()))
            ->headerActions([(static::$header ?? static fn (): XlsxExportAction => OrderResource::exportAction())()])
            ->toolbarActions([(static::$bulk ?? static fn (): XlsxExportBulkAction => XlsxExportBulkAction::make())()]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}
