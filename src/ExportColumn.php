<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport;

use Filament\Tables\Columns\Column;

/**
 * One column of the file: a Filament table column (or a virtual one) plus its format.
 */
final readonly class ExportColumn
{
    public function __construct(
        public string $name,
        public string $label,
        public ?Column $column,
        public ColumnFormat $format,
        public bool $selected = true,
    ) {}
}
