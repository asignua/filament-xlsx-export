<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\Actions\XlsxExportBulkAction;
use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Workbench\App\Livewire\OrdersTable;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

class StreamedDownloadTest extends TestCase
{
    protected function tearDown(): void
    {
        OrdersTable::$header = null;
        OrdersTable::$bulk = null;

        parent::tearDown();
    }

    private function orders(): void
    {
        $this->makeOrder('Alpha', ['amount' => '10.50', 'status' => 'shipped', 'paid' => true]);
        $this->makeOrder('Bravo', ['amount' => '99.99', 'status' => 'new']);
        $this->makeOrder('Charlie', ['amount' => '5.00', 'status' => 'new', 'paid' => true]);
    }

    private function streamedHeader(): void
    {
        OrdersTable::$header = static fn () => XlsxExportAction::make()->streamed()->footer('Generated for tests');
    }

    private function linkOf(Testable $component): string
    {
        $url = $component->effects['redirect'] ?? null;
        $this->assertIsString($url, 'The action did not redirect to a streaming link.');
        $this->assertArrayNotHasKey('download', $component->effects);

        return $url;
    }

    private function workbookOf(string $url): Workbook
    {
        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));

        return Workbook::fromBinary($response->streamedContent());
    }

    public function test_a_streamed_export_hands_over_a_signed_link_and_streams_a_workbook(): void
    {
        $this->orders();
        $this->streamedHeader();

        $component = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'amount']]);

        $url = $this->linkOf($component);
        $this->assertStringContainsString('signature=', $url);

        $book = $this->workbookOf($url);

        $this->assertSame(['Name', 'Amount'], array_map(static fn (array $c): ?string => $c['value'], $book->rows[1]));
        $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], array_slice($book->column(0), 0, 3));
        $this->assertSame('n', $book->cell(2, 1)['type']);
        $this->assertSame('Generated for tests', $book->cell(6, 0)['value']);
    }

    public function test_the_streamed_file_respects_filters_search_and_sort(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(
            Livewire::test(OrdersTable::class)
                ->filterTable('status', 'new')
                ->sortTable('amount', 'desc')
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]),
        );

        $this->assertSame(['Bravo', 'Charlie'], array_slice($this->workbookOf($url)->column(0), 0, 2));

        $this->streamedHeader();
        $searched = $this->linkOf(
            Livewire::test(OrdersTable::class)
                ->searchTable('Alp')
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]),
        );

        $this->assertSame(['Alpha'], array_slice($this->workbookOf($searched)->column(0), 0, 1));
    }

    public function test_the_streamed_bulk_action_exports_only_the_selection(): void
    {
        $this->orders();
        OrdersTable::$bulk = static fn () => XlsxExportBulkAction::make()->streamed();

        $ids = Order::query()->whereIn('name', ['Alpha', 'Charlie'])->pluck('id')->all();

        $url = $this->linkOf(
            Livewire::test(OrdersTable::class)
                ->selectTableRecords($ids)
                ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]),
        );

        $book = $this->workbookOf($url);

        $this->assertEqualsCanonicalizing(['Alpha', 'Charlie'], $book->column(0));
    }

    public function test_it_switches_to_streaming_above_the_configured_threshold(): void
    {
        $this->orders();

        config(['filament-xlsx-export.streaming.above_rows' => 2]);
        $big = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);
        $this->assertIsString($big->effects['redirect'] ?? null);

        config(['filament-xlsx-export.streaming.above_rows' => 10]);
        $small = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);
        $this->assertArrayHasKey('download', $small->effects);

        config(['filament-xlsx-export.streaming.enabled' => false, 'filament-xlsx-export.streaming.above_rows' => 1]);
        $off = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);
        $this->assertArrayHasKey('download', $off->effects);
    }

    public function test_the_row_limit_is_for_livewire_mode_and_the_hard_cap_for_streaming(): void
    {
        $this->orders();
        config(['filament-xlsx-export.row_limit' => 1]);

        $this->streamedHeader();
        $streamed = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);
        $this->assertIsString($streamed->effects['redirect'] ?? null, 'row_limit must not apply to streaming');

        config(['filament-xlsx-export.streaming.hard_cap' => 2]);
        $capped = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
        $this->assertArrayNotHasKey('redirect', $capped->effects);
    }

    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->get(strtok($url, '?') ?: $url)->assertForbidden();
        $this->get($url.'x')->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->travel(5)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_another_user_cannot_use_the_link_and_does_not_burn_it(): void
    {
        $this->orders();
        $this->streamedHeader();

        $owner = auth()->user();
        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        auth()->logout();
        $this->get($url)->assertForbidden();

        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_a_link_works_once(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->get($url)->assertOk()->streamedContent();
        $this->get($url)->assertStatus(410);
    }

    public function test_twenty_thousand_rows_stream_with_flat_memory(): void
    {
        $rows = [];

        for ($i = 1; $i <= 20000; $i++) {
            $rows[] = ['name' => 'Order '.$i, 'amount' => $i, 'quantity' => 1, 'status' => 'new', 'paid' => 0, 'notes' => str_repeat('x', 200)];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        OrdersTable::$header = static fn () => XlsxExportAction::make()->streamed()->chunkSize(250);

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'notes', 'amount']]));

        gc_collect_cycles();
        $before = memory_get_usage();

        $response = $this->get($url);
        $content = $response->streamedContent();

        $growth = memory_get_peak_usage() - $before;
        $book = Workbook::fromBinary($content);

        $this->assertCount(20001, $book->rows);
        $this->assertSame('Order 20000', $book->cell(20001, 0)['value']);
        // Holding 20k hydrated orders with 200-byte notes would be several times this; the file
        // itself is captured by the test harness, so allow for it.
        $this->assertLessThan(48 * 1024 * 1024, $growth);
    }
}
