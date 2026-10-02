<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Tests\Feature;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\Concerns\ExportsTypedXlsx;
use Asignua\FilamentXlsxExport\Tests\Support\Workbook;
use Asignua\FilamentXlsxExport\Tests\TestCase;
use OpenSpout\Writer\XLSX\Writer;

class TypedExporterTest extends TestCase
{
    /**
     * Stands in for a Filament `Exporter` subclass: the trait only touches `$columnMap` and
     * `$export`, which core's constructor promotes.
     */
    private function exporter(): object
    {
        return new class
        {
            use ExportsTypedXlsx;

            /** @var array<string, string> */
            protected array $columnMap = ['id' => 'ID', 'total' => 'Total', 'placed' => 'Placed', 'paid' => 'Paid', 'zip' => 'Zip', '2024' => '2024'];

            protected object $export;

            public function __construct()
            {
                $this->export = (object) ['total_rows' => 2];
            }

            public function xlsxColumnFormats(): array
            {
                return [
                    'id' => ColumnFormat::make()->number(),
                    'total' => ColumnFormat::make()->money('$')->width(18),
                    'placed' => ColumnFormat::make()->dateTime(),
                    'paid' => ColumnFormat::make()->boolean(),
                    'zip' => ColumnFormat::make()->text(),
                ];
            }
        };
    }

    public function test_csv_strings_become_typed_cells_in_the_workbook_core_writes(): void
    {
        $exporter = $this->exporter();
        $path = tempnam(sys_get_temp_dir(), 'typed-');
        $this->assertNotFalse($path);

        $writer = new Writer;
        $writer->openToFile($path);
        $exporter->configureXlsxWriterAfterOpen($writer);

        // Exactly the sequence of core's CreateXlsxFile job.
        $writer->addRow($exporter->makeXlsxHeaderRow(['ID', 'Total', 'Placed', 'Paid', 'Zip', '2024']));
        $writer->addRow($exporter->makeXlsxRow(['7', '1234.5', '2026-03-01 13:30:00', '1', '00123', 'free text']));
        $writer->addRow($exporter->makeXlsxRow(['8', '', '', '0', '00456', '']));
        $exporter->configureXlsxWriterBeforeClose($writer);
        $writer->close();

        $book = Workbook::fromFile($path);
        @unlink($path);

        $this->assertSame('inlineStr', $book->cell(1, 5)['type']);
        $this->assertSame('2024', $book->cell(1, 5)['value']);

        $this->assertSame('n', $book->cell(2, 0)['type']);
        $this->assertSame('7', $book->cell(2, 0)['value']);
        $this->assertSame('n', $book->cell(2, 1)['type']);
        $this->assertEqualsWithDelta(1234.5, (float) $book->cell(2, 1)['value'], 0.001);
        $this->assertSame('"$" #,##0.00', $book->cell(2, 1)['format']);
        $this->assertSame('n', $book->cell(2, 2)['type']);
        $this->assertSame('yyyy-mm-dd hh:mm', $book->cell(2, 2)['format']);
        $this->assertSame('b', $book->cell(2, 3)['type']);
        $this->assertSame('1', $book->cell(2, 3)['value']);
        $this->assertSame('0', $book->cell(3, 3)['value']);
        $this->assertSame('00123', $book->cell(2, 4)['value']);
        // A column that declares nothing stays text, as before.
        $this->assertSame('inlineStr', $book->cell(2, 5)['type']);

        $this->assertNull($book->cell(3, 1)['value']);

        $this->assertSame('A2', $book->freezeCell);
        $this->assertSame('A1:F3', $book->autoFilter);
        $this->assertSame(18.0, $book->widths[2]);
    }
}
