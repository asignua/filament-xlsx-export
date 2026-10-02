<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Turns a Filament table into the list of columns that can be exported.
 */
final class ColumnResolver
{
    /**
     * Every column the user may pick: the table's own columns that are not hidden for good
     * (images are skipped), then the virtual ones declared with a value closure.
     * `selected` is what the picker ticks by default: the columns the table shows right now.
     *
     * @param array<string, ColumnFormat> $formats
     *
     * @return list<ExportColumn>
     */
    public static function available(Table $table, array $formats): array
    {
        $resolved = [];

        foreach ($table->getColumns() as $name => $column) {
            $format = $formats[$name] ?? ColumnFormat::make();

            if ($format->isExcluded() || $column instanceof ImageColumn || $column->isHidden()) {
                continue;
            }

            $resolved[$name] = new ExportColumn(
                name: $name,
                label: $format->getLabel() ?? self::label($column),
                column: $column,
                format: $format,
                selected: !$column->isToggledHidden() && $format->isSelectedByDefault(),
            );
        }

        foreach ($formats as $name => $format) {
            if (isset($resolved[$name]) || $format->isExcluded() || $format->getValue() === null || $table->getColumn($name) !== null) {
                continue;
            }

            $resolved[$name] = new ExportColumn(
                name: $name,
                label: $format->getLabel() ?? $name,
                column: null,
                format: $format,
                selected: $format->isSelectedByDefault(),
            );
        }

        return array_values($resolved);
    }

    /**
     * @param list<ExportColumn> $available
     * @param list<string>|null  $names     null means "what is selected by default"
     *
     * @return list<ExportColumn>
     */
    public static function pick(array $available, ?array $names): array
    {
        return array_values(array_filter(
            $available,
            static fn (ExportColumn $column): bool => $names === null ? $column->selected : in_array($column->name, $names, true),
        ));
    }

    private static function label(Column $column): string
    {
        $label = $column->getLabel();

        return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
    }
}
