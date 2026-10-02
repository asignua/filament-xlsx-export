<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport;

use Closure;

/**
 * Per-column overrides of the export: how the cell looks, how wide it is and where its value
 * comes from. Keyed by the table column name in `->columnFormats([...])`. A key that is not a
 * table column and carries a `value()` closure adds a virtual column at the end.
 */
final class ColumnFormat
{
    public const string AUTO = 'auto';

    public const string TEXT = 'text';

    public const string NUMBER = 'number';

    public const string DATE = 'date';

    public const string DATE_TIME = 'date_time';

    public const string TIME = 'time';

    public const string BOOLEAN = 'boolean';

    private ?string $label = null;

    private string $type = self::AUTO;

    private ?string $numberFormat = null;

    private ?float $width = null;

    private ?Closure $value = null;

    private bool $formatted = false;

    private int|float|null $divideBy = null;

    private bool $sum = false;

    private bool $excluded = false;

    private bool $selectedByDefault = true;

    public static function make(?string $label = null): self
    {
        $format = new self;
        $format->label = $label;

        return $format;
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Force the cell to be text, whatever the value looks like (zip codes, phone numbers).
     */
    public function text(): self
    {
        $this->type = self::TEXT;
        $this->numberFormat = '@';

        return $this;
    }

    /**
     * A real TRUE/FALSE cell; the strings `1`, `0`, `true`, `false`, `yes`, `no` are understood.
     */
    public function boolean(): self
    {
        $this->type = self::BOOLEAN;

        return $this;
    }

    /**
     * A number with an explicit Excel format, e.g. `'#,##0.000'`.
     */
    public function number(?string $numberFormat = null): self
    {
        $this->type = self::NUMBER;
        $this->numberFormat = $numberFormat ?? $this->numberFormat;

        return $this;
    }

    public function integer(): self
    {
        return $this->number('#,##0');
    }

    public function decimal(int $places = 2): self
    {
        return $this->number('#,##0'.($places > 0 ? '.'.str_repeat('0', $places) : ''));
    }

    /**
     * @param string|null $symbol Currency symbol shown in the cell, e.g. `'$'` or `'€'`; without it the cell is a plain two-decimal number.
     */
    public function money(?string $symbol = null, int $places = 2): self
    {
        $decimals = $places > 0 ? '.'.str_repeat('0', $places) : '';
        $pattern = '#,##0'.$decimals;

        return $this->number($symbol === null ? $pattern : '"'.str_replace('"', '', $symbol).'" '.$pattern);
    }

    public function percent(int $places = 0): self
    {
        return $this->number('0'.($places > 0 ? '.'.str_repeat('0', $places) : '').'%');
    }

    public function date(?string $numberFormat = null): self
    {
        $this->type = self::DATE;
        $this->numberFormat = $numberFormat;

        return $this;
    }

    public function dateTime(?string $numberFormat = null): self
    {
        $this->type = self::DATE_TIME;
        $this->numberFormat = $numberFormat;

        return $this;
    }

    public function time(?string $numberFormat = null): self
    {
        $this->type = self::TIME;
        $this->numberFormat = $numberFormat;

        return $this;
    }

    /**
     * Width in characters.
     */
    public function width(?float $width): self
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Take the cell value from here instead of the table column. Named arguments: `$record`,
     * `$state` (what the column would have given), `$data` (the export form) and `$livewire`.
     */
    public function value(?Closure $value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Use the text the table shows (`formatStateUsing()`, prefix, limit...) instead of the raw
     * typed value. The cell becomes text.
     */
    public function formatted(bool $condition = true): self
    {
        $this->formatted = $condition;

        return $this;
    }

    /**
     * Divide the numeric value, e.g. `100` for money stored in cents (`->money(divideBy: 100)`
     * in the table is not visible to the exporter).
     */
    public function divideBy(int|float|null $divisor): self
    {
        $this->divideBy = $divisor;

        return $this;
    }

    /**
     * Add a bold total row under the data for this column.
     */
    public function sum(bool $condition = true): self
    {
        $this->sum = $condition;

        return $this;
    }

    /**
     * Never offer this column in the export.
     */
    public function exclude(bool $condition = true): self
    {
        $this->excluded = $condition;

        return $this;
    }

    /**
     * Offer the column in the picker but leave it unticked.
     */
    public function unselected(bool $condition = true): self
    {
        $this->selectedByDefault = !$condition;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getNumberFormat(): ?string
    {
        return $this->numberFormat;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function getValue(): ?Closure
    {
        return $this->value;
    }

    public function isFormatted(): bool
    {
        return $this->formatted;
    }

    public function getDivideBy(): int|float|null
    {
        return $this->divideBy;
    }

    public function hasSum(): bool
    {
        return $this->sum;
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }

    public function isSelectedByDefault(): bool
    {
        return $this->selectedByDefault;
    }
}
