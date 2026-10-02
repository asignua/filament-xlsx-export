<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Orders;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\ColumnFormat;
use Closure;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\OrderStatus;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    /** When true, a user sees only the orders whose `secret` holds their id (a per-user scope). */
    public static bool $ownOrdersOnly = false;

    /** Table header actions for a test (none by default; the list page carries the export). */
    public static ?Closure $tableHeader = null;

    /**
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return static::$ownOrdersOnly ? $query->where('secret', (string) auth()->id()) : $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->alignEnd(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('amount')->money('USD')->sortable()->alignEnd(),
                TextColumn::make('quantity')->numeric()->sortable()->alignEnd(),
                TextColumn::make('status')->badge(),
                IconColumn::make('paid')->boolean(),
                TextColumn::make('shipped_at')->date(),
                TextColumn::make('placed_at')->dateTime(),
                TextColumn::make('zip'),
                TextColumn::make('notes')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('secret')->hidden(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class),
            ])
            ->headerActions(static::$tableHeader !== null ? (static::$tableHeader)() : []);
    }

    public static function exportAction(): XlsxExportAction
    {
        return XlsxExportAction::make()
            ->title('Orders')
            ->columnFormats([
                'zip' => ColumnFormat::make()->text(),
                'amount' => ColumnFormat::make()->money('$')->sum()->width(14),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}
