<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Asignua\FilamentXlsxExport\ColumnFormat;
use Asignua\FilamentXlsxExport\ExportColumn;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use ReflectionFunction;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Reads one record through one column and keeps the type: numbers stay numbers, dates become
 * Excel dates, booleans stay booleans, enums become their label, everything else is text.
 */
final class CellFactory
{
    /** Excel stores 15 significant digits; a longer "number" (an IBAN, a card) must stay text. */
    private const int MAX_NUMERIC_DIGITS = 15;

    /** Excel refuses a string cell above this. */
    private const int MAX_TEXT_LENGTH = 32767;

    /**
     * @param array<string, mixed> $params extra named arguments for value closures (`data`, `livewire`)
     */
    public function make(ExportColumn $export, Model $record, array $params = [], int $index = 0): CellValue
    {
        $column = $export->column;
        $format = $export->format;
        $state = null;

        if ($column !== null) {
            $column->record($record);
            // rowIndex() and any state closure typed `stdClass $rowLoop` read the loop object. The loop
            // stays truthful (counted from the file's first row). Only Filament's own rowIndex() adds
            // `perPage * (page - 1)` of the table page the user is on, so only that column gets the
            // offset taken back out of its loop.
            $loopIndex = $this->isRowIndexColumn($column) ? $index - $this->pageOffset($params['livewire'] ?? null) : $index;
            $column->rowLoop((object) [
                'index' => $loopIndex,
                'iteration' => $loopIndex + 1,
                'first' => $index === 0,
                'last' => false,
                'count' => null,
                'remaining' => null,
                'depth' => 1,
                'parent' => null,
            ]);
            $column->clearCachedState();
            $state = $column->getState();
        }

        if (($valueClosure = $format->getValue()) instanceof Closure) {
            $state = app()->call($valueClosure, [...$params, 'record' => $record, 'state' => $state, 'column' => $column]);
        } elseif ($format->isFormatted() && $column instanceof TextColumn) {
            $state = $this->formatState($column, $state);

            // The display string is text: parsing it back would read '05/01/2026' as 1 May.
            if ($format->getType() === ColumnFormat::AUTO) {
                return new CellValue($state === null ? null : $this->clip($state), $format->getNumberFormat());
            }
        } elseif ($column instanceof SelectColumn && is_scalar($state) && $state !== '') {
            // The table shows the option label, not the stored key.
            $options = $column->getOptions();
            $state = $options[(string) $state] ?? $state;
        }

        if ($format->getType() === ColumnFormat::TEXT) {
            $text = $this->text($state);

            return new CellValue($text === null ? null : $this->clip($text), $format->getNumberFormat());
        }

        $value = $this->normalize($state);

        if (is_string($value) && $value !== '') {
            $value = $this->coerce($value, $export);
        }

        if (is_int($value) || is_float($value)) {
            $value = $this->divide($value, $format);
        }

        if ($value instanceof DateTimeInterface) {
            $value = $this->zone($value, $column);

            if ($format->getType() === ColumnFormat::TIME || ($format->getType() === ColumnFormat::AUTO && $column instanceof TextColumn && $column->isTime())) {
                $time = CarbonImmutable::instance($value);
                $value = ($time->hour * 3600 + $time->minute * 60 + $time->second) / 86400;
            }
        }

        if (is_string($value)) {
            $value = $this->clip($value);
        }

        return new CellValue($value, $this->numberFormat($export, $value));
    }

    /**
     * Whether the column's state is the closure TextColumn::rowIndex() installs (a closure scoped to
     * TextColumn itself; a user's own state closure is scoped to their class).
     */
    private function isRowIndexColumn(object $column): bool
    {
        if (!$column instanceof TextColumn) {
            return false;
        }

        $state = Closure::bind(fn (): mixed => $this->getStateUsing, $column, TextColumn::class)();

        return $state instanceof Closure
            && (new ReflectionFunction($state))->getClosureScopeClass()?->getName() === TextColumn::class;
    }

    /**
     * The rows Filament's rowIndex() adds for the page the table is on.
     */
    private function pageOffset(mixed $livewire): int
    {
        if (!$livewire instanceof HasTable) {
            return 0;
        }

        $perPage = $livewire->getTableRecordsPerPage();

        return is_numeric($perPage) ? (int) $perPage * max(0, (int) $livewire->getTablePage() - 1) : 0;
    }

    /**
     * Filament formats a list state item by item; formatStateUsing() closures and limit() expect a
     * single value.
     */
    private function formatState(TextColumn $column, mixed $state): ?string
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (is_array($state)) {
            return $this->text(array_map(static fn (mixed $item): mixed => $column->formatState($item), $state));
        }

        return $this->text($column->formatState($state));
    }

    /**
     * The same typing for a value that already arrived as text, e.g. a CSV cell of Filament's
     * own exporter. Only columns that declare a type are converted.
     */
    public function fromText(ExportColumn $export, ?string $text): CellValue
    {
        $type = $export->format->getType();

        // Core's getFormattedState() is `?string`, so a false state arrives as ''.
        if ($text === '' && $type === ColumnFormat::BOOLEAN) {
            return new CellValue(false);
        }

        if ($text === null || $text === '') {
            return CellValue::empty();
        }

        if ($type === ColumnFormat::AUTO || $type === ColumnFormat::TEXT) {
            return new CellValue($this->clip($text), $export->format->getNumberFormat());
        }

        $value = $this->coerce($text, $export);

        if (is_int($value) || is_float($value)) {
            $value = $this->divide($value, $export->format);
        }

        if ($value instanceof DateTimeInterface && $type === ColumnFormat::TIME) {
            $time = CarbonImmutable::instance($value);
            $value = ($time->hour * 3600 + $time->minute * 60 + $time->second) / 86400;
        }

        return new CellValue($value, $this->numberFormat($export, $value));
    }

    private function numberFormat(ExportColumn $export, bool|int|float|string|DateTimeInterface|null $value): ?string
    {
        $format = $export->format;
        $column = $export->column;

        if ($format->getNumberFormat() !== null && !is_string($value)) {
            return $format->getNumberFormat();
        }

        $type = $format->getType();
        $formats = $this->formats();

        if ($value instanceof DateTimeInterface) {
            if ($type === ColumnFormat::DATE || ($type === ColumnFormat::AUTO && $column instanceof TextColumn && $column->isDate() && !$column->isDateTime())) {
                return $formats['date'];
            }

            return (new CellValue($value))->hasTimePart() || $type === ColumnFormat::DATE_TIME ? $formats['date_time'] : $formats['date'];
        }

        if ($type === ColumnFormat::TIME || ($type === ColumnFormat::AUTO && $column instanceof TextColumn && $column->isTime())) {
            return is_float($value) ? $formats['time'] : null;
        }

        if (($value !== null && !is_bool($value) && !is_string($value)) && $column instanceof TextColumn && $column->isMoney()) {
            return $formats['money'];
        }

        return null;
    }

    /**
     * @return array{date: string, date_time: string, time: string, money: string}
     */
    private function formats(): array
    {
        return [
            'date' => (string) config('filament-xlsx-export.formats.date', 'yyyy-mm-dd'),
            'date_time' => (string) config('filament-xlsx-export.formats.date_time', 'yyyy-mm-dd hh:mm'),
            'time' => (string) config('filament-xlsx-export.formats.time', 'hh:mm'),
            'money' => (string) config('filament-xlsx-export.formats.money', '#,##0.00'),
        ];
    }

    private function normalize(mixed $state): bool|int|float|string|DateTimeInterface|null
    {
        if ($state === null) {
            return null;
        }

        if (is_bool($state) || is_int($state) || is_float($state) || is_string($state) || $state instanceof DateTimeInterface) {
            return $state;
        }

        return $this->text($state);
    }

    /**
     * Anything that is not a plain scalar, as text. Lists are joined, enums give their label.
     */
    private function text(mixed $state): ?string
    {
        if ($state === null) {
            return null;
        }

        if (is_bool($state)) {
            return $state ? '1' : '0';
        }

        if (is_scalar($state)) {
            return (string) $state;
        }

        if ($state instanceof DateTimeInterface) {
            return Date::instance($state)->toDateTimeString();
        }

        if ($state instanceof HasLabel) {
            $label = $state->getLabel();

            return $label === null ? null : $this->text($label);
        }

        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        if ($state instanceof UnitEnum) {
            return $state->name;
        }

        if ($state instanceof Htmlable) {
            return trim(html_entity_decode(strip_tags($state->toHtml())));
        }

        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (is_array($state)) {
            return implode(', ', array_filter(
                array_map(fn (mixed $item): ?string => $this->text($item), $state),
                static fn (?string $item): bool => $item !== null && $item !== '',
            ));
        }

        if ($state instanceof Stringable) {
            return (string) $state;
        }

        return null;
    }

    /**
     * A string from a typed column: a date string from a `date()` column, a decimal from the
     * database (`decimal` casts give strings) in a numeric or money column.
     */
    private function coerce(string $value, ExportColumn $export): bool|int|float|string|DateTimeInterface
    {
        $column = $export->column;
        $type = $export->format->getType();

        $temporal = in_array($type, [ColumnFormat::DATE, ColumnFormat::DATE_TIME, ColumnFormat::TIME], true)
            || ($type === ColumnFormat::AUTO && $column instanceof TextColumn && ($column->isDate() || $column->isDateTime() || $column->isTime()));

        if ($temporal) {
            try {
                return Date::parse($value);
            } catch (Throwable) {
                return $value;
            }
        }

        if ($type === ColumnFormat::BOOLEAN) {
            return match (mb_strtolower(trim($value))) {
                '1', 'true', 'yes' => true,
                '0', 'false', 'no' => false,
                default => $value,
            };
        }

        $numeric = $type === ColumnFormat::NUMBER
            || ($type === ColumnFormat::AUTO && $column instanceof TextColumn && ($column->isNumeric() || $column->isMoney()));

        if ($numeric && is_numeric($value) && strlen(ltrim($value, '-+0.')) <= self::MAX_NUMERIC_DIGITS) {
            return $value + 0;
        }

        return $value;
    }

    private function divide(int|float $value, ColumnFormat $format): int|float
    {
        $divisor = $format->getDivideBy();

        return $divisor === null || $divisor == 0 ? $value : $value / $divisor;
    }

    private function zone(DateTimeInterface $value, mixed $column): DateTimeInterface
    {
        $timezone = $column instanceof TextColumn ? $column->getTimezone() : config('app.timezone');

        return CarbonImmutable::instance($value)->setTimezone(is_string($timezone) ? $timezone : 'UTC');
    }

    private function clip(string $value): string
    {
        return mb_strlen($value) > self::MAX_TEXT_LENGTH ? mb_substr($value, 0, self::MAX_TEXT_LENGTH) : $value;
    }
}
