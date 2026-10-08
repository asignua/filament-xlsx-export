<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\Actions\XlsxExportBulkAction;
use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Filament\Resources\Orders\OrderResource;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Livewire\OrdersTable;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

class StreamedDownloadTest extends TestCase
{
    protected function tearDown(): void
    {
        OrdersTable::$header = null;
        OrdersTable::$bulk = null;
        OrdersTable::$selectable = null;
        ListOrders::$header = null;
        OrderResource::$tableHeader = null;
        OrderResource::$ownOrdersOnly = false;

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

    /**
     * Filament eager-loads and aggregates only the columns the table shows; a column the user
     * toggled off but ticked in the picker needs the same, or it is empty / lazy-loaded per row.
     *
     * @return array<string, array{bool}>
     */
    public static function modes(): array
    {
        return ['livewire' => [false], 'streamed' => [true]];
    }

    #[DataProvider('modes')]
    public function test_picked_toggled_off_relationship_and_aggregate_columns_are_loaded(bool $streamed): void
    {
        $acme = Customer::query()->create(['name' => 'Acme']);
        $this->makeOrder('Alpha', ['customer_id' => $acme->id]);
        $this->makeOrder('Bravo');
        OrdersTable::$header = static fn () => XlsxExportAction::make()->streamed($streamed);
        Model::preventLazyLoading();

        try {
            $component = Livewire::test(OrdersTable::class)
                ->sortTable('name')
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name', 'buyer.name', 'customer_exists']]);

            $book = $streamed ? $this->workbookOf($this->linkOf($component)) : $this->downloadedWorkbook($component);
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame(['Name', 'Buyer', 'Has customer'], array_map(static fn (array $c): ?string => $c['value'], $book->rows[1]));
        $this->assertSame('Acme', $book->cell(2, 1)['value']);
        $this->assertNotNull($book->cell(2, 2)['value'], 'The exists() aggregate was not added to the query.');
        $this->assertNotNull($book->cell(3, 2)['value'], 'The exists() aggregate was not added to the query.');
    }

    /**
     * Like every core bulk action, the export leaves out the selected rows the user may not act on
     * (`authorizeIndividualRecords()`) and the rows the table does not let be selected.
     */
    #[DataProvider('modes')]
    public function test_the_bulk_export_honours_individual_authorization_and_selectability(bool $streamed): void
    {
        $this->orders();
        $this->makeOrder('Delta');
        OrdersTable::$bulk = static fn () => XlsxExportBulkAction::make()
            ->streamed($streamed)
            ->authorizeIndividualRecords(static fn (Order $record): bool => $record->name !== 'Bravo');
        OrdersTable::$selectable = static fn (Order $record): bool => $record->name !== 'Charlie';

        $component = Livewire::test(OrdersTable::class)
            ->selectTableRecords(Order::query()->pluck('id')->all())
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $book = $streamed ? $this->workbookOf($this->linkOf($component)) : $this->downloadedWorkbook($component);

        $this->assertEqualsCanonicalizing(['Alpha', 'Delta'], $book->column(0));
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

    public function test_caps_read_from_env_as_numeric_strings_still_apply(): void
    {
        $this->orders();

        // A published config with env('XLSX_HARD_CAP', ...) gives strings.
        config(['filament-xlsx-export.streaming.hard_cap' => '2']);
        $this->streamedHeader();
        $capped = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
        $this->assertArrayNotHasKey('redirect', $capped->effects);

        config(['filament-xlsx-export.streaming.hard_cap' => null, 'filament-xlsx-export.row_limit' => '1']);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->streamed(false);
        $limited = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
        $this->assertArrayNotHasKey('download', $limited->effects);

        config(['filament-xlsx-export.row_limit' => null]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->streamed()->rowLimit(static fn (): string => '2');
        Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
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

        $this->assertRefusedBackToThePanel($this->get($url), __('filament-xlsx-export::xlsx-export.link_expired'));
    }

    public function test_another_user_cannot_use_the_link_and_does_not_burn_it(): void
    {
        $this->orders();
        $this->streamedHeader();

        $owner = auth()->user();
        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->assertRefusedBackToThePanel($this->actingAs(User::factory()->create())->get($url));

        auth()->logout();
        $this->assertRefusedBackToThePanel($this->get($url));

        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_a_link_works_once(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        $this->get($url)->assertOk()->streamedContent();
        $this->assertRefusedBackToThePanel($this->get($url));
    }

    public function test_a_link_already_being_spent_by_a_concurrent_request_is_refused(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        // A simultaneous request has passed the atomic check-and-mark but not yet removed the payload.
        Cache::add('filament-xlsx-export:'.$this->tokenOf($url).':spent', true, 60);

        $this->assertRefusedBackToThePanel($this->get($url));
    }

    public function test_a_tenant_panel_never_streams(): void
    {
        $this->orders();
        $this->streamedHeader();
        Filament::setCurrentPanel('tenant');

        $component = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);

        $this->assertArrayNotHasKey('redirect', $component->effects);
        $this->assertArrayHasKey('download', $component->effects);
    }

    public function test_the_route_refuses_a_token_issued_on_a_tenant_panel(): void
    {
        $this->orders();
        $this->streamedHeader();

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));
        $key = 'filament-xlsx-export:'.$this->tokenOf($url);
        Cache::put($key, [...Cache::get($key), 'panel' => 'tenant'], 60);

        $this->assertRefusedBackToThePanel($this->get($url));
    }

    public function test_a_table_outside_any_panel_streams_without_a_default_panel(): void
    {
        $this->orders();
        $this->streamedHeader();

        // A Livewire table on a page outside every panel, in an app with no default() panel.
        Filament::setCurrentPanel(null);
        Filament::getPanel('admin')->default(false);
        app(PanelRegistry::class)->defaultPanel = null;

        try {
            $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

            $payload = Cache::get('filament-xlsx-export:'.$this->tokenOf($url));
            $this->assertIsArray($payload);
            $this->assertNull($payload['panel']);
            $this->assertSame('web', $payload['guard'], 'Without a panel the guard is the app default.');

            $book = $this->workbookOf($url);
            $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], array_slice($book->column(0), 0, 3));
            $this->assertNull(Filament::getCurrentPanel(), 'No unrelated panel is made current or booted.');
        } finally {
            Filament::getPanel('admin')->default();
            app(PanelRegistry::class)->defaultPanel = null;
            Filament::setCurrentPanel('admin');
        }
    }

    public function test_the_download_request_uses_the_locale_of_the_click(): void
    {
        $this->orders();
        $this->streamedHeader();

        app()->setLocale('uk');
        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));
        $expected = __('filament-xlsx-export::xlsx-export.link_expired', [], 'uk');
        $this->assertNotSame(__('filament-xlsx-export::xlsx-export.link_expired', [], 'en'), $expected);

        // The download request runs no panel middleware that would set the user's locale, and a
        // refusal happens before the component (whose snapshot carries the locale) is rehydrated.
        app()->setLocale('en');
        // Past the link's ttl (120 s), within the payload's grace period in the cache (+30 s).
        $this->travel(130)->seconds();

        $this->assertRefusedBackToThePanel($this->get($url), $expected);
    }

    public function test_an_explicit_row_limit_also_caps_a_streamed_export(): void
    {
        $this->orders();
        config(['filament-xlsx-export.streaming.above_rows' => 1]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->rowLimit(2);

        $component = Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));

        $this->assertArrayNotHasKey('redirect', $component->effects);

        OrdersTable::$header = static fn () => XlsxExportAction::make()->rowLimit(0);
        $unbounded = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);
        $this->assertIsString($unbounded->effects['redirect'] ?? null, 'rowLimit(0) leaves only the hard cap');
    }

    public function test_a_resource_list_page_header_action_streams(): void
    {
        $this->orders();
        ListOrders::$header = static fn (): array => [XlsxExportAction::make()->streamed()];

        $url = $this->linkOf(Livewire::test(ListOrders::class)->callAction('xlsxExport', ['columns' => ['name']]));

        $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], $this->workbookOf($url)->column(0));
    }

    public function test_a_grouped_page_header_action_streams(): void
    {
        $this->orders();
        ListOrders::$header = static fn (): array => [ActionGroup::make([XlsxExportAction::make()->streamed()])];

        $url = $this->linkOf(Livewire::test(ListOrders::class)->callAction('xlsxExport', ['columns' => ['name']]));

        $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], $this->workbookOf($url)->column(0));
    }

    public function test_a_page_action_and_a_table_action_with_the_same_name_are_told_apart(): void
    {
        $this->orders();
        ListOrders::$header = static fn (): array => [XlsxExportAction::make()->streamed()->title('From the page')];
        OrderResource::$tableHeader = static fn (): array => [XlsxExportAction::make()->streamed()->title('From the table')];

        $url = $this->linkOf(Livewire::test(ListOrders::class)->callAction('xlsxExport', ['columns' => ['name']]));

        $this->assertSame('From the page', $this->workbookOf($url)->cell(1, 0)['value']);
    }

    public function test_the_resource_query_scope_holds_in_the_streamed_file(): void
    {
        $owner = auth()->user();
        $other = User::factory()->create();
        $this->makeOrder('Mine', ['secret' => (string) $owner?->getAuthIdentifier()]);
        $this->makeOrder('Theirs', ['secret' => (string) $other->getKey()]);
        OrderResource::$ownOrdersOnly = true;
        ListOrders::$header = static fn (): array => [XlsxExportAction::make()->streamed()];

        $url = $this->linkOf(Livewire::test(ListOrders::class)->callAction('xlsxExport', ['columns' => ['name']]));

        $this->assertSame(['Mine'], $this->workbookOf($url)->column(0));
    }

    public function test_the_download_request_uses_the_panels_guard_as_the_default_guard(): void
    {
        config([
            'auth.guards.staff' => ['driver' => 'session', 'provider' => 'users'],
        ]);

        $panel = Filament::getPanel('admin');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->makeOrder('Mine', ['secret' => (string) $owner->getKey()]);
        $this->makeOrder('Theirs', ['secret' => (string) $other->getKey()]);
        OrderResource::$ownOrdersOnly = true;
        ListOrders::$header = static fn (): array => [XlsxExportAction::make()->streamed()];

        $panel->authGuard('staff');

        try {
            // The user is logged in on the panel's guard only; the app default (`web`) has nobody.
            $this->actingAs($owner, 'staff');
            $url = $this->linkOf(Livewire::test(ListOrders::class)->callAction('xlsxExport', ['columns' => ['name']]));

            Auth::guard('web')->forgetUser();
            Auth::shouldUse('web');
            $this->assertNull(auth()->id());

            $this->assertSame(['Mine'], $this->workbookOf($url)->column(0));
        } finally {
            $panel->authGuard('web');
        }
    }

    public function test_a_guest_never_gets_a_streaming_link(): void
    {
        $this->orders();
        $this->streamedHeader();

        Filament::setCurrentPanel(null);
        Filament::getPanel('admin')->default(false);
        app(PanelRegistry::class)->defaultPanel = null;

        try {
            Auth::guard('web')->forgetUser();

            $component = Livewire::test(OrdersTable::class)
                ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]);

            $this->assertArrayNotHasKey('redirect', $component->effects);
            $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], array_slice($this->downloadedWorkbook($component)->column(0), 0, 3));
        } finally {
            Filament::getPanel('admin')->default();
            app(PanelRegistry::class)->defaultPanel = null;
            Filament::setCurrentPanel('admin');
        }
    }

    public function test_a_grouped_query_counts_its_groups_for_the_limit_the_threshold_and_row_count(): void
    {
        $this->orders();
        $this->makeOrder('Delta', ['status' => 'new']);
        // Four orders in two groups: a plain count() would report the first group's size (3).
        $grouped = static fn (): XlsxExportAction => XlsxExportAction::make()
            ->queryUsing(fn (Builder $query): Builder => $query->selectRaw('min(id) as id, status, count(*) as total')->groupBy('status'))
            ->caption(fn (int $rowCount): string => 'Rows: '.$rowCount);

        config(['filament-xlsx-export.streaming.above_rows' => 2]);
        OrdersTable::$header = static fn (): XlsxExportAction => $grouped()->rowLimit(2);
        $component = Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['status']]);

        $this->assertArrayNotHasKey('redirect', $component->effects, 'Two groups are under the streaming threshold of 2.');
        $book = $this->downloadedWorkbook($component);
        $this->assertSame('Rows: 2', $book->cell(1, 0)['value']);
        $this->assertCount(2, $book->column(0, 3));

        OrdersTable::$header = static fn (): XlsxExportAction => $grouped()->rowLimit(1);
        Livewire::test(OrdersTable::class)
            ->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['status']])
            ->assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
    }

    public function test_the_row_cap_is_checked_again_when_the_link_is_used(): void
    {
        $this->orders();
        OrdersTable::$header = static fn (): XlsxExportAction => XlsxExportAction::make()->streamed()->rowLimit(3);

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));

        // A row added between the click and the download.
        $this->makeOrder('Delta');

        $this->assertRefusedBackToThePanel($this->get($url), __('filament-xlsx-export::xlsx-export.too_many_rows_title'));
    }

    public function test_the_hard_cap_is_checked_again_when_the_link_is_used(): void
    {
        $this->orders();
        $this->streamedHeader();
        config(['filament-xlsx-export.streaming.hard_cap' => 3]);

        $url = $this->linkOf(Livewire::test(OrdersTable::class)->callAction(TestAction::make('xlsxExport')->table(), ['columns' => ['name']]));
        $this->makeOrder('Delta');

        $this->assertRefusedBackToThePanel($this->get($url), __('filament-xlsx-export::xlsx-export.too_many_rows_title'));
    }

    private function tokenOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return basename($path);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertRefusedBackToThePanel(TestResponse $response, ?string $title = null): void
    {
        $response->assertRedirect();
        $notifications = session('filament.notifications');
        $this->assertIsArray($notifications);
        $this->assertSame(
            $title ?? __('filament-xlsx-export::xlsx-export.link_unusable'),
            $notifications[array_key_last($notifications)]['title'] ?? null,
        );
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
