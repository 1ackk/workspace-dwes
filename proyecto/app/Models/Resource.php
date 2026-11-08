<?php

namespace App\Models;

use App\Enums\ResourceType;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $space_id
 * @property ResourceType $type
 * @property string $name
 * @property string|null $description
 * @property int $capacity
 * @property string $hourly_rate
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['space_id', 'type', 'name', 'description', 'capacity', 'hourly_rate', 'active'])]
class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'hourly_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * El responsable se deriva siempre del espacio (única fuente: spaces.manager_id).
     *
     * @return HasOneThrough<User, Space, $this>
     */
    public function manager(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Space::class, 'id', 'id', 'space_id', 'manager_id');
    }

    /**
     * @return HasMany<Tariff, $this>
     */
    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    /**
     * @return BelongsToMany<Booking, $this, BookingResource>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_resource')
            ->using(BookingResource::class)
            ->withPivot(['id', 'start_at', 'end_at', 'precio_final'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
