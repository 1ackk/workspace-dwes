<?php

namespace App\Models;

use App\Enums\TariffKind;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $resource_id
 * @property string $name
 * @property TariffKind $kind
 * @property string|null $price_per_hour
 * @property string|null $surcharge_percent
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['resource_id', 'name', 'kind', 'price_per_hour', 'surcharge_percent', 'starts_at', 'ends_at', 'valid_from', 'valid_until', 'active'])]
class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TariffKind::class,
            'price_per_hour' => 'decimal:2',
            'surcharge_percent' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'active' => 'boolean',
        ];
    }

    /**
     * Sin recurso, la tarifa es una regla general del coworking.
     *
     * @return BelongsTo<\App\Models\Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
