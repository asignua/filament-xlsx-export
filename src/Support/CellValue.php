<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What goes into a cell: the typed value and the Excel number format to show it with.
 */
final readonly class CellValue
{
    public function __construct(
        public bool|int|float|string|DateTimeInterface|null $value,
        public ?string $format = null,
    ) {}

    public static function empty(): self
    {
        return new self(null);
    }

    public function hasTimePart(): bool
    {
        return $this->value instanceof DateTimeInterface
            && CarbonImmutable::instance($this->value)->format('H:i:s') !== '00:00:00';
    }
}
