<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Actions;

use Asignua\FilamentXlsxExport\Concerns\ExportsTableToXlsx;
use Filament\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The selected rows as an .xlsx. "Select all" across pages is honoured: the selection is turned
 * into a query (selected keys, or everything but the deselected ones), never into a loaded
 * collection.
 */
class XlsxExportBulkAction extends BulkAction
{
    use ExportsTableToXlsx;

    public static function getDefaultName(): ?string
    {
        return 'xlsxExportBulk';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-xlsx-export::xlsx-export.export_selected'));
        $this->modalHeading(__('filament-xlsx-export::xlsx-export.export_selected_heading'));
        $this->setUpXlsxExport();
        $this->deselectRecordsAfterCompletion();

        $this->action(fn (HasTable $livewire, array $data): ?StreamedResponse => $this->exportQuery(
            $livewire,
            $livewire->getSelectedTableRecordsQuery(),
            $data,
        ));
    }
}
