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
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
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
    public function make(ExportColumn $export, Model $record, array $params = []): CellValue
    {
        $column = $export->column;
        $format = $export->format;
        $state = null;

        if ($column !== null) {
            $column->record($record);
            $column->clearCachedState();
            $state = $column->getState();
        }

        if (($valueClosure = $format->getValue()) instanceof Closure) {
            $state = app()->call($valueClosure, [...$params, 'record' => $record, 'state' => $state, 'column' => $column]);
        } elseif ($format->isFormatted() && $column instanceof TextColumn) {
            $state = $this->text($column->formatState($state));
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
     * The same typing for a value that already arrived as text, e.g. a CSV cell of Filament's
     * own exporter. Only columns that declare a type are converted.
     */
    public function fromText(ExportColumn $export, ?string $text): CellValue
    {
        if ($text === null || $text === '') {
            return CellValue::empty();
        }

        $type = $export->format->getType();

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
