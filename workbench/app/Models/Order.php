<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Enums\OrderStatus;

/**
 * @property int $id
 * @property string $name
 * @property string $amount
 * @property int $quantity
 * @property OrderStatus $status
 * @property bool $paid
 * @property string|null $zip
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => OrderStatus::class,
            'paid' => 'boolean',
            'shipped_at' => 'date',
            'placed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function shout(): Attribute
    {
        return Attribute::get(fn (): string => strtoupper($this->name));
    }
}
