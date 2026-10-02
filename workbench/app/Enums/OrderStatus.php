<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case New = 'new';
    case Shipped = 'shipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Brand new',
            self::Shipped => 'On its way',
        };
    }
}
