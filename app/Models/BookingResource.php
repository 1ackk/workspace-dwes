<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivote propia de la relación n:n entre Booking y Resource.
 *
 * No usa SoftDeletes: un Pivot no es un modelo ordinario y el histórico
 * lo conserva la Booking (que sí se borra de forma lógica).
 *
 * @property int $id
 * @property int $booking_id
 * @property int $resource_id
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property string $precio_final
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BookingResource extends Pivot
{
    protected $table = 'booking_resource';

    public $incrementing = true;

    public $timestamps = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'precio_final' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<\App\Models\Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
