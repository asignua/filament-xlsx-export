<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Asignua\FilamentXlsxExport\XlsxExporter;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Livewire\OrdersTable;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

class ExportTest extends TestCase
{
    private function seedOrders(): void
    {
        $acme = Customer::query()->create(['name' => 'Acme']);

        $this->makeOrder('Alpha', [
            'customer_id' => $acme->id,
            'amount' => '1234.50',
            'quantity' => 3,
            'status' => 'shipped',
            'paid' => true,
            'shipped_at' => '2026-03-04',
            'placed_at' => '2026-03-01 13:30:00',
            'zip' => '00123',
            'notes' => 'secret note',
            'secret' => 'hidden',
        ]);
        $this->makeOrder('Bravo', ['amount' => '99.99', 'quantity' => 1, 'status' => 'new', 'paid' => false]);
        $this->makeOrder('Charlie', ['amount' => '5.00', 'quantity' => 12, 'status' => 'new', 'paid' => true]);
    }

    public function test_cells_keep_their_types_and_formats(): void
    {
        $this->seedOrders();

        $component = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table())
            ->assertHasNoActionErrors();

        $book = $this->downloadedWorkbook($component);

        // Title row, blank line, header, then data.
        $this->assertSame('Orders', $book->sheetName);
        $this->assertSame('Orders', $book->cell(1, 0)['value']);
        $this->assertTrue($book->cell(1, 0)['bold']);
        $header = array_map(static fn (array $cell): ?string => $cell['value'], $book->rows[3]);
        $this->assertSame(['Id', 'Name', 'Customer', 'Amount', 'Quantity', 'Status', 'Paid', 'Shipped at', 'Placed at', 'Zip'], $header);

        $alpha = $book->rows[4];

        $this->assertSame('n', $alpha[0]['type']);
        $this->assertSame('Alpha', $alpha[1]['value']);
        $this->assertSame('Acme', $alpha[2]['value']);

        // Money: a number with a currency format, not "$1,234.50".
        $this->assertSame('n', $alpha[3]['type']);
        $this->assertEquals(1234.5, (float) $alpha[3]['value']);
        $this->assertSame('"$" #,##0.00', $alpha[3]['format']);

        $this->assertSame('n', $alpha[4]['type']);
        $this->assertSame('3', $alpha[4]['value']);

        // Enum -> its label.
        $this->assertSame('On its way', $alpha[5]['value']);
        $this->assertSame('inlineStr', $alpha[5]['type']);

        $this->assertSame('b', $alpha[6]['type']);
        $this->assertSame('1', $alpha[6]['value']);
        $this->assertSame('0', $book->rows[5][6]['value']);

        // Dates are serial numbers with a date format (2026-03-04 is serial 46085).
        $this->assertSame('n', $alpha[7]['type']);
        $this->assertSame('46085', $alpha[7]['value']);
        $this->assertSame('yyyy-mm-dd', $alpha[7]['format']);

        $this->assertSame('n', $alpha[8]['type']);
        $this->assertEqualsWithDelta(46082.5625, (float) $alpha[8]['value'], 0.0001);
        $this->assertSame('yyyy-mm-dd hh:mm', $alpha[8]['format']);

        // ->text(): a zip with a leading zero stays text.
        $this->assertSame('inlineStr', $alpha[9]['type']);
        $this->assertSame('00123', $alpha[9]['value']);

        // Empty values are empty cells, not "0" or "".
        $this->assertNull($book->cell(5, 2)['value']);
    }

    public function test_hidden_and_toggled_columns_are_not_exported_by_default(): void
    {
        $this->seedOrders();

        $book = $this->downloadedWorkbook(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table()));
        $labels = array_map(static fn (array $cell): ?string => $cell['value'], $book->rows[3]);

        $this->assertNotContains('Notes', $labels);
        $this->assertNotContains('Secret', $labels);
    }

    public function test_the_picker_lists_every_visible_column_and_defaults_to_the_shown_ones(): void
    {
        $this->seedOrders();

        $component = Livewire::test(OrdersTable::class)->mountAction(TestAction::make('xlsxExport')->table());
        $field = invade($component->instance())->getMountedActionSchema()->getComponent('columns');

        $options = $field->getOptions();
        $this->assertArrayHasKey('notes', $options);
        $this->assertArrayNotHasKey('secret', $options);
        $this->assertTrue($field->isBulkToggleable());
        $this->assertNotContains('notes', $field->getDefaultState());
        $this->assertContains('amount', $field->getDefaultState());
    }

    public function test_picked_columns_decide_the_file(): void
    {
        $this->seedOrders();

        $component = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'notes']]);

        $book = $this->downloadedWorkbook($component);

        $this->assertSame(['Name', 'Notes'], array_map(static fn (array $cell): ?string => $cell['value'], $book->rows[3]));
        $this->assertSame('secret note', $book->rows[4][1]['value']);
    }

    public function test_it_exports_the_filtered_searched_and_sorted_table(): void
    {
        $this->seedOrders();

        $component = Livewire::test(OrdersTable::class)
            ->filterTable('status', 'new')
            ->sortTable('amount', 'desc')
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);

        $this->assertSame(['Bravo', 'Charlie'], $this->downloadedWorkbook($component)->column(0, 3));

        $searched = Livewire::test(OrdersTable::class)
            ->searchTable('Alp')
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);

        $this->assertSame(['Alpha'], $this->downloadedWorkbook($searched)->column(0, 3));
    }

    public function test_the_bulk_action_exports_only_the_selected_rows(): void
    {
        $this->seedOrders();
        $selected = Order::query()->whereIn('name', ['Alpha', 'Charlie'])->get();

        $component = Livewire::test(OrdersTable::class)
            ->selectTableRecords($selected->pluck('id')->all())
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $this->assertEqualsCanonicalizing(['Alpha', 'Charlie'], $this->downloadedWorkbook($component)->column(0, 1));
    }

    public function test_the_bulk_action_respects_the_current_filter_when_everything_is_selected(): void
    {
        $this->seedOrders();

        $component = Livewire::test(OrdersTable::class)
            ->filterTable('status', 'new')
            ->selectTableRecords(Order::query()->where('status', 'new')->pluck('id')->all())
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $this->assertEqualsCanonicalizing(['Bravo', 'Charlie'], $this->downloadedWorkbook($component)->column(0, 1));
    }

    public function test_it_refuses_a_file_over_the_row_limit_with_a_notification(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()->rowLimit(2);

        try {
            $component = Livewire::test(OrdersTable::class)
                ->callAction(TestAction::make('xlsxExport')->table())
                ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));

            $this->assertArrayNotHasKey('download', $component->effects);
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_the_configured_row_limit_applies_and_zero_disables_it(): void
    {
        $this->seedOrders();
        config(['filament-xlsx-export.row_limit' => 2]);

        $blocked = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table());
        $this->assertArrayNotHasKey('download', $blocked->effects);

        OrdersTable::$header = static fn () => XlsxExportAction::make()->rowLimit(0);

        try {
            $free = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table());
            $this->assertArrayHasKey('download', $free->effects);
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_export_options_can_change_the_query_the_name_and_the_caption(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()
            ->exportOptions([Toggle::make('only_paid')->default(false)])
            ->queryUsing(fn (Builder $query, array $data): Builder => ($data['only_paid'] ?? false) ? $query->where('paid', true) : $query)
            ->caption(fn (array $data, int $rowCount): string => ($data['only_paid'] ? 'Paid only' : 'Everything').', '.$rowCount.' rows')
            ->fileName(fn (array $data): string => $data['only_paid'] ? 'paid' : 'all');

        try {
            $component = Livewire::test(OrdersTable::class)
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name'], 'only_paid' => true]);

            $book = $this->downloadedWorkbook($component);

            // No title: caption on row 1, blank row 2, header row 3.
            $this->assertSame('Paid only, 2 rows', $book->cell(1, 0)['value']);
            $this->assertSame('Name', $book->cell(3, 0)['value']);
            $this->assertEqualsCanonicalizing(['Alpha', 'Charlie'], $book->column(0, 3));
            $this->assertSame('paid.xlsx', $component->effects['download']['name']);
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_the_layout_has_a_merged_title_a_frozen_header_a_filter_widths_and_a_total(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()
            ->title('Orders')
            ->caption('Everything')
            ->columnFormats(['amount' => ColumnFormat::make()->money('$')->sum()->width(21)]);

        try {
            $component = Livewire::test(OrdersTable::class)
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'amount']]);

            $book = $this->downloadedWorkbook($component);

            // Title (1), caption (2), blank (3), header (4), 3 data rows (5-7), total (8).
            $this->assertSame(['A1:B1'], $book->merges);
            $this->assertSame('Everything', $book->cell(2, 0)['value']);
            $this->assertSame('Amount', $book->cell(4, 1)['value']);
            $this->assertTrue($book->cell(4, 1)['bold']);
            $this->assertSame('A5', $book->freezeCell);
            $this->assertSame('A4:B7', $book->autoFilter);
            $this->assertSame(21.0, $book->widths[2]);

            $this->assertSame(__('filament-xlsx-export::xlsx-export.total'), $book->cell(8, 0)['value']);
            $this->assertTrue($book->cell(8, 1)['bold']);
            $this->assertEqualsWithDelta(1339.49, (float) $book->cell(8, 1)['value'], 0.001);
            $this->assertSame('"$" #,##0.00', $book->cell(8, 1)['format']);
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_freeze_and_filter_can_be_switched_off(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()->freezeHeader(false)->autoFilter(false);

        try {
            $book = $this->downloadedWorkbook(
                Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]),
            );

            $this->assertNull($book->freezeCell);
            $this->assertNull($book->autoFilter);
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_without_the_picker_the_action_exports_the_shown_columns_straight_away(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()->chooseColumns(false);

        try {
            $component = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table());
            $book = $this->downloadedWorkbook($component);

            $this->assertSame('Id', $book->cell(1, 0)['value']);
            $this->assertSame(10, count($book->rows[1]));
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_virtual_columns_text_and_boolean_formats_and_chunking(): void
    {
        $this->seedOrders();

        OrdersTable::$header = static fn () => XlsxExportAction::make()
            ->chunkSize(2)
            ->columnFormats([
                'amount' => ColumnFormat::make('Cents')->divideBy(0.01)->integer(),
                'quantity' => ColumnFormat::make()->text(),
                'paid' => ColumnFormat::make()->formatted(),
                'vat' => ColumnFormat::make('VAT')->value(fn (Order $record): float => $record->quantity * 0.2)->decimal(2),
            ]);

        try {
            $component = Livewire::test(OrdersTable::class)
                ->sortTable('id')
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'amount', 'quantity', 'vat']]);

            $book = $this->downloadedWorkbook($component);

            $this->assertSame(['Name', 'Cents', 'Quantity', 'VAT'], array_map(static fn (array $cell): ?string => $cell['value'], $book->rows[1]));
            // Three rows over chunks of two: none lost, none doubled.
            $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $book->column(0));
            $this->assertEqualsWithDelta(123450.0, (float) $book->cell(2, 1)['value'], 0.5);
            $this->assertSame('builtin:3', $book->cell(2, 1)['format']); // Excel's own id for #,##0
            $this->assertSame('inlineStr', $book->cell(2, 2)['type']);
            $this->assertSame('3', $book->cell(2, 2)['value']);
            $this->assertEqualsWithDelta(0.6, (float) $book->cell(2, 3)['value'], 0.0001);
            $this->assertSame('builtin:4', $book->cell(2, 3)['format']); // #,##0.00
        } finally {
            OrdersTable::$header = null;
        }
    }

    public function test_a_value_that_looks_like_a_formula_stays_text(): void
    {
        $this->makeOrder('=HYPERLINK("http://evil.test","x")');
        $this->makeOrder('+1+1');

        $book = $this->downloadedWorkbook(
            Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]),
        );

        $this->assertSame('inlineStr', $book->cell(4, 0)['type']);
        $this->assertSame('=HYPERLINK("http://evil.test","x")', $book->cell(4, 0)['value']);
        $this->assertSame('inlineStr', $book->cell(5, 0)['type']);
    }

    public function test_the_list_page_of_a_resource_carries_the_header_action(): void
    {
        $this->seedOrders();

        Livewire::test(ListOrders::class)
            ->assertActionExists('xlsxExport')
            ->callAction('xlsxExport', ['columns' => ['name']])
            ->assertFileDownloaded();
    }

    public function test_file_names_are_made_safe_and_get_the_extension(): void
    {
        $this->assertSame('a b.xlsx', XlsxExporter::safeFilename('a/b'));
        $this->assertSame('Звіт.xlsx', XlsxExporter::safeFilename('Звіт'));
        $this->assertSame('keep.xlsx', XlsxExporter::safeFilename('keep.xlsx'));
        $this->assertSame('export.xlsx', XlsxExporter::safeFilename('//'));
        $this->assertSame('A B', XlsxExporter::normalizeSheetName('A:B'));
        $this->assertSame(31, mb_strlen(XlsxExporter::normalizeSheetName(str_repeat('x', 50))));
    }
}
