<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\Actions\XlsxExportBulkAction;
use Asignua\FilamentXlsxExport\Jobs\ExportXlsxJob;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Throwable;
use Workbench\App\Livewire\OrdersTable;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

class QueuedExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        OrdersTable::$header = null;
        OrdersTable::$bulk = null;
        OrdersTable::$selectable = null;

        parent::tearDown();
    }

    private function orders(): void
    {
        $this->makeOrder('Alpha', ['amount' => '10.50', 'status' => 'shipped', 'paid' => true]);
        $this->makeOrder('Bravo', ['amount' => '99.99', 'status' => 'new']);
        $this->makeOrder('Charlie', ['amount' => '5.00', 'status' => 'new', 'paid' => true]);
    }

    private function click(Testable $component, array $columns = ['name', 'amount']): Testable
    {
        return $component->callAction(TestAction::make('xlsxExport')->table(), ['columns' => $columns]);
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function values(Workbook $book): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (array $cell): ?string => $cell['value'], $row),
            $book->rows,
        );
    }

    private function storedBook(): Workbook
    {
        $files = Storage::disk('local')->files('filament-xlsx-exports');
        $xlsx = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.xlsx')));
        $this->assertCount(1, $xlsx, 'Expected exactly one stored export.');

        return Workbook::fromBinary(Storage::disk('local')->get($xlsx[0]));
    }

    private function notification(): array
    {
        $rows = auth()->user()->notifications()->get();
        $this->assertCount(1, $rows);

        /** @var array<string, mixed> */
        return $rows->first()->data;
    }

    public function test_queue_dispatches_the_job_and_toasts_instead_of_downloading(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        $component = $this->click(Livewire::test(OrdersTable::class)->filterTable('status', 'new'));

        Bus::assertDispatchedTimes(ExportXlsxJob::class, 1);
        Bus::assertDispatched(ExportXlsxJob::class, function (ExportXlsxJob $job): bool {
            return $job->payload['action'] === 'xlsxExport'
                && $job->payload['bulk'] === false
                && $job->payload['data'] === ['columns' => ['name', 'amount']]
                && $job->payload['user'] === auth()->id()
                && $job->payload['panel'] === 'admin';
        });
        $this->assertArrayNotHasKey('download', $component->effects);
        $this->assertArrayNotHasKey('redirect', $component->effects);
        Notification::assertNotified(__('filament-xlsx-export::xlsx-export.queued_title'));
    }

    public function test_connection_and_queue_can_be_named(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue(connection: 'database', queue: 'exports');

        $this->click(Livewire::test(OrdersTable::class));

        Bus::assertDispatched(ExportXlsxJob::class, static fn (ExportXlsxJob $job): bool => $job->connection === 'database' && $job->queue === 'exports');
    }

    public function test_without_queue_nothing_is_dispatched(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);

        $component = $this->click(Livewire::test(OrdersTable::class));

        Bus::assertNothingDispatched();
        $this->assertArrayHasKey('download', $component->effects);
    }

    public function test_the_threshold_queues_only_bigger_exports(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queueWhenMoreThan(2);

        // Two rows match: not more than 2, built at once.
        $small = $this->click(Livewire::test(OrdersTable::class)->filterTable('status', 'new'));
        $this->assertArrayHasKey('download', $small->effects);
        Bus::assertNothingDispatched();

        // Three rows: queued.
        $big = $this->click(Livewire::test(OrdersTable::class));
        $this->assertArrayNotHasKey('download', $big->effects);
        Bus::assertDispatchedTimes(ExportXlsxJob::class, 1);
    }

    public function test_the_feature_can_be_switched_off(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        config(['filament-xlsx-export.queue.enabled' => false]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        $component = $this->click(Livewire::test(OrdersTable::class));

        Bus::assertNothingDispatched();
        $this->assertArrayHasKey('download', $component->effects);
    }

    public function test_a_tenant_panel_and_a_guest_stay_synchronous(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        Filament::setCurrentPanel('tenant');
        $tenant = $this->click(Livewire::test(OrdersTable::class));
        $this->assertArrayHasKey('download', $tenant->effects);

        Filament::setCurrentPanel('admin');
        auth()->logout();
        $guest = $this->click(Livewire::test(OrdersTable::class));
        $this->assertArrayHasKey('download', $guest->effects);

        Bus::assertNothingDispatched();
    }

    public function test_the_row_cap_refuses_at_the_click(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        config(['filament-xlsx-export.queue.hard_cap' => 2]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        $this->click(Livewire::test(OrdersTable::class));

        Bus::assertNothingDispatched();
        Notification::assertNotified(__('filament-xlsx-export::xlsx-export.too_many_rows_title'));
    }

    /**
     * The point of the mode: the background file is the file the click would have downloaded.
     */
    public function test_the_job_writes_the_same_rows_and_columns_as_the_synchronous_path(): void
    {
        $this->orders();
        $this->makeOrder('Alpine', ['amount' => '1.00', 'status' => 'new']);
        $columns = ['name', 'amount', 'status', 'paid'];

        $run = function (bool $queued) use ($columns): Testable {
            OrdersTable::$header = static fn () => $queued ? XlsxExportAction::make()->queue()->footer('End') : XlsxExportAction::make()->footer('End');

            return $this->click(
                Livewire::test(OrdersTable::class)->filterTable('status', 'new')->searchTable('Al')->sortTable('amount', 'desc'),
                $columns,
            );
        };

        $sync = $this->values($this->downloadedWorkbook($run(false)));
        $run(true);
        $queued = $this->values($this->storedBook());

        $this->assertSame(['Name', 'Amount', 'Status', 'Paid'], array_slice($queued[1], 0, 4));
        $this->assertSame('Alpine', $queued[2][0]);
        $this->assertSame($sync, $queued);
    }

    public function test_the_job_honours_the_bulk_selection_including_select_all_with_deselections(): void
    {
        $this->orders();
        $this->makeOrder('Delta');
        $bravo = Order::query()->where('name', 'Bravo')->value('id');
        OrdersTable::$bulk = static fn () => XlsxExportBulkAction::make()->queue();

        Livewire::test(OrdersTable::class)
            ->set('isTrackingDeselectedTableRecords', true)
            ->set('deselectedTableRecords', [(string) $bravo])
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $this->assertEqualsCanonicalizing(['Alpha', 'Charlie', 'Delta'], $this->storedBook()->column(0));
    }

    public function test_the_job_skips_records_the_user_may_not_export(): void
    {
        $this->orders();
        $this->makeOrder('Delta');
        OrdersTable::$bulk = static fn () => XlsxExportBulkAction::make()
            ->queue()
            ->authorizeIndividualRecords(static fn (Order $record): bool => $record->name !== 'Bravo');
        OrdersTable::$selectable = static fn (Order $record): bool => $record->name !== 'Charlie';

        Livewire::test(OrdersTable::class)
            ->selectTableRecords(Order::query()->pluck('id')->all())
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $this->assertEqualsCanonicalizing(['Alpha', 'Delta'], $this->storedBook()->column(0));
    }

    public function test_the_payload_survives_the_queue_serialization(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();
        $this->click(Livewire::test(OrdersTable::class)->filterTable('status', 'new'), ['name']);

        // What a real worker does: the job travels as a string, with no component and no closure in it.
        $job = unserialize(serialize(Bus::dispatched(ExportXlsxJob::class)->first()));
        $job->handle();

        $this->assertEqualsCanonicalizing(['Bravo', 'Charlie'], $this->storedBook()->column(0));
    }

    public function test_the_user_is_notified_with_a_signed_link_that_downloads_the_file(): void
    {
        $this->orders();
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue()->fileName('Big orders');

        $this->click(Livewire::test(OrdersTable::class), ['name']);

        $data = $this->notification();
        $this->assertSame(__('filament-xlsx-export::xlsx-export.ready_title'), $data['title']);
        $this->assertStringContainsString('Big orders.xlsx', $data['body']);
        $this->assertSame(__('filament-xlsx-export::xlsx-export.download'), $data['actions'][0]['label']);

        $url = $data['actions'][0]['url'];
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringContainsString('Big orders.xlsx', (string) $response->headers->get('Content-Disposition'));
        $this->assertEqualsCanonicalizing(['Alpha', 'Bravo', 'Charlie'], Workbook::fromBinary($response->streamedContent())->column(0));

        // A notification can be clicked twice.
        $this->get($url)->assertOk();
    }

    private function link(): string
    {
        $this->orders();
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();
        $this->click(Livewire::test(OrdersTable::class), ['name']);

        return $this->notification()['actions'][0]['url'];
    }

    private function assertRefused(TestResponse $response, ?string $title = null): void
    {
        $response->assertRedirect();
        Notification::assertNotified($title ?? __('filament-xlsx-export::xlsx-export.link_unusable'));
    }

    public function test_the_link_refuses_another_user_and_a_guest(): void
    {
        $url = $this->link();
        $owner = auth()->user();

        $this->assertRefused($this->actingAs(User::factory()->create())->get($url));

        auth()->logout();
        $this->assertRefused($this->get($url));

        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_the_link_refuses_a_forged_signature_and_an_expired_one(): void
    {
        $url = $this->link();

        $this->get(strtok($url, '?') ?: $url)->assertForbidden();
        $this->get($url.'x')->assertForbidden();

        $this->travel(QueuedExports::retentionHours() + 1)->hours();
        $this->assertRefused($this->get($url), __('filament-xlsx-export::xlsx-export.link_expired'));
    }

    public function test_a_pruned_file_says_so(): void
    {
        $url = $this->link();
        Storage::disk('local')->deleteDirectory('filament-xlsx-exports');

        $this->assertRefused($this->get($url), __('filament-xlsx-export::xlsx-export.file_gone'));
    }

    public function test_prune_deletes_only_expired_exports(): void
    {
        $disk = Storage::disk('local');
        $disk->put('filament-xlsx-exports/old.xlsx', 'x');
        $disk->put('filament-xlsx-exports/old.json', '{}');
        // A real file's mtime, which Carbon's time travel does not move.
        touch($disk->path('filament-xlsx-exports/old.xlsx'), time() - 30 * 3600);
        touch($disk->path('filament-xlsx-exports/old.json'), time() - 30 * 3600);
        $disk->put('filament-xlsx-exports/new.xlsx', 'x');
        $disk->put('filament-xlsx-exports/new.json', '{}');

        $this->artisan('xlsx-export:prune')->assertSuccessful();

        $this->assertSame(
            ['filament-xlsx-exports/new.json', 'filament-xlsx-exports/new.xlsx'],
            collect($disk->files('filament-xlsx-exports'))->sort()->values()->all(),
        );

        $this->artisan('xlsx-export:prune', ['--hours' => 0])->assertSuccessful();
        $this->assertCount(2, $disk->files('filament-xlsx-exports'));
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'xlsx-export:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('50 3 * * *', $events->first()->expression);
    }

    public function test_a_failing_job_deletes_its_leftovers_and_notifies_the_user(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();
        $this->click(Livewire::test(OrdersTable::class));
        /** @var ExportXlsxJob $job */
        $job = Bus::dispatched(ExportXlsxJob::class)->first();

        // The action vanished from the code between the click and the worker: a real failure.
        $broken = new ExportXlsxJob($job->id, [...$job->payload, 'action' => 'gone']);

        try {
            $broken->handle();
            $this->fail('The job should have thrown.');
        } catch (Throwable $exception) {
            $broken->failed($exception);
        }

        $data = $this->notification();
        $this->assertSame(__('filament-xlsx-export::xlsx-export.failed_title'), $data['title']);
        $this->assertSame([], Storage::disk('local')->files('filament-xlsx-exports'));
    }

    public function test_growing_past_the_cap_before_the_job_runs_notifies_instead_of_failing(): void
    {
        $this->orders();
        Bus::fake([ExportXlsxJob::class]);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();
        $this->click(Livewire::test(OrdersTable::class));
        /** @var ExportXlsxJob $job */
        $job = Bus::dispatched(ExportXlsxJob::class)->first();

        config(['filament-xlsx-export.queue.hard_cap' => 1]);
        $job->handle();

        $data = $this->notification();
        $this->assertSame(__('filament-xlsx-export::xlsx-export.too_many_rows_title'), $data['title']);
        $this->assertSame([], Storage::disk('local')->files('filament-xlsx-exports'));
    }

    public function test_a_panel_without_database_notifications_still_writes_the_file_and_logs(): void
    {
        $this->orders();
        Log::spy();
        Filament::getPanel('admin')->databaseNotifications(false);
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        $this->click(Livewire::test(OrdersTable::class), ['name']);

        $this->assertCount(0, auth()->user()->notifications()->get());
        $this->assertCount(3, $this->storedBook()->column(0));
        Log::shouldHaveReceived('info')->withArgs(static fn (string $message): bool => str_contains($message, 'no database notifications'))->once();
    }

    /**
     * As close to a worker as a test gets: the job goes through the database queue, then every
     * piece of request state is dropped before `queue:work` picks it up.
     */
    private function runWorkerOnFreshState(): void
    {
        auth()->forgetGuards();
        Filament::setCurrentPanel(null);
        session()->flush();
        app()->setLocale('en');
        // The request instance stays: a console worker has one (an empty one), and Laravel's guards need it.
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app['auth']->shouldUse('web');

        // Not --once: Filament's database notification is itself queued behind the export job.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
        \Illuminate\Support\Facades\Schema::create('jobs', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_a_real_worker_builds_the_filtered_searched_file_from_a_clean_process_state(): void
    {
        $this->orders();
        $this->makeOrder('Alpine', ['amount' => '1.00', 'status' => 'new']);
        $this->useDatabaseQueue();
        $owner = auth()->user();
        OrdersTable::$header = static fn () => XlsxExportAction::make()->queue();

        $this->click(
            Livewire::test(OrdersTable::class)->filterTable('status', 'new')->searchTable('Al')->sortTable('amount', 'desc'),
            ['name', 'amount'],
        );
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count());
        $this->assertSame([], Storage::disk('local')->files('filament-xlsx-exports'));

        $this->runWorkerOnFreshState();

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count());
        $this->assertSame(['Alpine'], array_slice($this->storedBook()->column(0), 0, 1));
        $this->assertCount(1, $this->storedBook()->column(0));
        $rows = $owner->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertSame(__('filament-xlsx-export::xlsx-export.ready_title'), $rows->first()->data['title']);
    }

    public function test_a_real_worker_builds_the_bulk_selection(): void
    {
        $this->orders();
        $this->useDatabaseQueue();
        $owner = auth()->user();
        OrdersTable::$bulk = static fn () => XlsxExportBulkAction::make()->queue();
        $ids = Order::query()->whereIn('name', ['Alpha', 'Charlie'])->pluck('id')->all();

        Livewire::test(OrdersTable::class)
            ->selectTableRecords($ids)
            ->callAction(TestAction::make('xlsxExportBulk')->table()->bulk(), ['columns' => ['name']]);

        $this->runWorkerOnFreshState();

        $this->assertEqualsCanonicalizing(['Alpha', 'Charlie'], $this->storedBook()->column(0));
        $this->assertCount(1, $owner->notifications()->get());
    }
}
