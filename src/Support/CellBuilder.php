<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Support;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Turns a typed value into an OpenSpout cell and shares one Style object per number format.
 */
final class CellBuilder
{
    /** @var array<string, Style> */
    private array $styles = [];

    public function make(CellValue $cell, bool $bold = false): Cell
    {
        $style = $this->style($cell->format, $bold);
        $value = $cell->value;

        return match (true) {
            $value === null || $value === '' => new EmptyCell(null, $style),
            is_bool($value) => new BooleanCell($value, $style),
            is_int($value), is_float($value) => new NumericCell($value, $style),
            $value instanceof DateTimeInterface => new DateTimeCell($value, $style),
            // Explicit StringCell: Cell::fromValue() turns a string starting with "=" into a
            // formula, which would let a record value run as one when the file is opened.
            default => new StringCell($value, $style),
        };
    }

    private function style(?string $format, bool $bold): ?Style
    {
        if ($format === null && !$bold) {
            return null;
        }

        $key = ($bold ? 'b' : 'n').'|'.$format;

        if (!isset($this->styles[$key])) {
            $style = new Style;

            if ($format !== null) {
                $style->setFormat($format);
            }

            if ($bold) {
                $style->setFontBold();
            }

            $this->styles[$key] = $style;
        }

        return $this->styles[$key];
    }
}
