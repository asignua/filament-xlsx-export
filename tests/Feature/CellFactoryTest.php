<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use Asignua\FilamentXlsxExport\Support\CellFactory;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Asignua\FilamentXlsxExport\XlsxExporter;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;
use Livewire\Livewire;
use stdClass;
use Workbench\App\Livewire\OrdersTable;
use Workbench\App\Models\Order;

class CellFactoryTest extends TestCase
{
    private function mounted(Column $column): Column
    {
        return $column->table(Livewire::test(OrdersTable::class)->instance()->getTable());
    }

    private function order(): Order
    {
        return Order::query()->create(['name' => 'Alpha', 'amount' => '1.00', 'quantity' => 1, 'status' => 'new', 'paid' => true, 'placed_at' => '2026-01-05 10:00:00']);
    }

    public function test_a_row_index_column_gets_its_loop(): void
    {
        $column = TextColumn::make('#')->rowIndex();
        $export = new ExportColumn('#', '#', $this->mounted($column), ColumnFormat::make());

        $this->assertEquals(3, (new CellFactory)->make($export, $this->order(), [], 2)->value);
    }

    public function test_a_row_index_column_counts_from_the_first_row_of_the_file_not_the_table_page(): void
    {
        $component = Livewire::test(OrdersTable::class)->set('paginators.page', 3);
        $livewire = $component->instance();
        $this->assertSame(3, $livewire->getTablePage());

        $column = TextColumn::make('#')->rowIndex()->table($livewire->getTable());
        $export = new ExportColumn('#', '#', $column, ColumnFormat::make());

        $this->assertEquals(1, (new CellFactory)->make($export, $this->order(), ['livewire' => $livewire], 0)->value);
        $this->assertEquals(2, (new CellFactory)->make($export, $this->order(), ['livewire' => $livewire], 1)->value);
    }

    public function test_a_row_loop_closure_stays_file_based_on_a_later_table_page(): void
    {
        $component = Livewire::test(OrdersTable::class)->set('paginators.page', 3);
        $livewire = $component->instance();

        $column = TextColumn::make('name')->state(fn (stdClass $rowLoop): string => $rowLoop->iteration.($rowLoop->first ? ':first' : ':other'))
            ->table($livewire->getTable());
        $export = new ExportColumn('name', 'Name', $column, ColumnFormat::make());

        $this->assertSame('1:first', (new CellFactory)->make($export, $this->order(), ['livewire' => $livewire], 0)->value);
        $this->assertSame('2:other', (new CellFactory)->make($export, $this->order(), ['livewire' => $livewire], 1)->value);
    }

    public function test_a_closure_typed_with_the_row_loop_does_not_crash(): void
    {
        $column = TextColumn::make('name')->state(fn (stdClass $rowLoop): int => $rowLoop->iteration);
        $export = new ExportColumn('name', 'Name', $this->mounted($column), ColumnFormat::make());

        $this->assertSame(1, (new CellFactory)->make($export, $this->order())->value);
    }

    public function test_formatted_dates_stay_text(): void
    {
        $column = TextColumn::make('placed_at')->date('d/m/Y');
        $export = new ExportColumn('placed_at', 'Placed', $this->mounted($column), ColumnFormat::make()->formatted());

        $this->assertSame('05/01/2026', (new CellFactory)->make($export, $this->order())->value);
    }

    public function test_formatted_list_states_are_formatted_item_by_item(): void
    {
        $column = TextColumn::make('name')->state(['a', 'b'])->formatStateUsing(fn (string $state): string => strtoupper($state));
        $export = new ExportColumn('name', 'Name', $this->mounted($column), ColumnFormat::make()->formatted());

        $this->assertSame('A, B', (new CellFactory)->make($export, $this->order())->value);
    }

    public function test_a_select_column_exports_the_option_label(): void
    {
        $column = SelectColumn::make('status')->options(['new' => 'Brand new']);
        $export = new ExportColumn('status', 'Status', $this->mounted($column), ColumnFormat::make());

        $this->assertSame('Brand new', (new CellFactory)->make($export, $this->order())->value);
    }

    public function test_an_empty_csv_cell_of_a_boolean_column_is_false(): void
    {
        $export = new ExportColumn('paid', 'Paid', null, ColumnFormat::make()->boolean());

        $this->assertFalse((new CellFactory)->fromText($export, '')->value);
    }

    public function test_sheet_and_file_names_avoid_what_excel_and_symfony_refuse(): void
    {
        $this->assertSame('history 1', XlsxExporter::normalizeSheetName('history'));

        foreach (['Prices in ¢', 'H½ report', 'a⁄b'] as $name) {
            $this->assertStringNotContainsString('/', Str::ascii(XlsxExporter::safeFilename($name)));
        }
    }
}
