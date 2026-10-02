<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Actions;

use Asignua\FilamentXlsxExport\Concerns\ExportsTableToXlsx;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download what is on the screen" as an .xlsx: the table's current filters, search and sort,
 * typed cells, a column picker. Works as a header action of a list page and in `headerActions()`
 * of a table.
 */
class XlsxExportAction extends Action
{
    use ExportsTableToXlsx;

    public static function getDefaultName(): ?string
    {
        return 'xlsxExport';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-xlsx-export::xlsx-export.export'));
        $this->modalHeading(__('filament-xlsx-export::xlsx-export.export_heading'));
        $this->setUpXlsxExport();

        $this->action(function (HasTable $livewire, array $data): ?StreamedResponse {
            $query = $livewire->getFilteredSortedTableQuery();

            return $query === null ? null : $this->exportQuery($livewire, $query, $data);
        });
    }
}
