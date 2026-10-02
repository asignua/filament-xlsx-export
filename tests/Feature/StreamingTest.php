<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use Asignua\FilamentXlsxExport\XlsxExporter;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Order;

class StreamingTest extends TestCase
{
    public function test_a_large_export_keeps_memory_flat_and_loses_no_row(): void
    {
        $rows = [];

        for ($i = 1; $i <= 6000; $i++) {
            $rows[] = ['name' => 'Order '.$i, 'amount' => $i, 'quantity' => 1, 'status' => 'new', 'paid' => 0, 'notes' => str_repeat('x', 200)];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $columns = [
            new ExportColumn('name', 'Name', null, ColumnFormat::make()->value(fn (Order $record): string => $record->name)),
            new ExportColumn('notes', 'Notes', null, ColumnFormat::make()->value(fn (Order $record): ?string => $record->notes)),
            new ExportColumn('amount', 'Amount', null, ColumnFormat::make()->value(fn (Order $record): string => $record->amount)->number()),
        ];

        $path = tempnam(sys_get_temp_dir(), 'big-');
        $this->assertNotFalse($path);

        gc_collect_cycles();
        $before = memory_get_usage();

        $exporter = XlsxExporter::make($columns, Order::query()->orderBy('id'))->chunkSize(250);
        $exporter->writeTo($path);

        $growth = memory_get_peak_usage() - $before;
        $book = Workbook::fromFile($path);
        @unlink($path);

        $this->assertSame(6000, $exporter->writtenRows());
        $this->assertCount(6001, $book->rows);
        $this->assertSame('Order 6000', $book->cell(6001, 0)['value']);
        $this->assertSame('A1:C6001', $book->autoFilter);
        // 6000 hydrated models with a 200-byte note would be well over this if they were held.
        $this->assertLessThan(24 * 1024 * 1024, $growth);
    }

    public function test_the_primary_key_breaks_ties_of_a_non_unique_sort(): void
    {
        $this->makeOrder('A', ['status' => 'new']);
        $this->makeOrder('B', ['status' => 'new']);

        $columns = [new ExportColumn('name', 'Name', null, ColumnFormat::make()->value(fn (Order $record): string => $record->name))];
        $path = tempnam(sys_get_temp_dir(), 'order-');
        $this->assertNotFalse($path);

        DB::enableQueryLog();
        XlsxExporter::make($columns, Order::query()->orderBy('status'))->chunkSize(1)->writeTo($path);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        @unlink($path);

        $this->assertMatchesRegularExpression('/order by "status" asc, "orders"\."id" asc/', $sql);

        // A query already ordered by the key gets no second copy of it.
        DB::flushQueryLog();
        DB::enableQueryLog();
        XlsxExporter::make($columns, Order::query()->orderBy('status')->orderBy('id'))->chunkSize(1)->writeTo($path);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        @unlink($path);

        $this->assertStringNotContainsString('"orders"."id" asc', $sql);
    }
}
