<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $invoice_id
 * @property Carbon $date
 * @property BookingStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $cancelled_at
 * @property string $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['user_id', 'invoice_id', 'date', 'status', 'expires_at', 'cancelled_at', 'total'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => BookingStatus::class,
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Relación n:n con franja y precio final en la tabla pivote.
     *
     * @return BelongsToMany<\App\Models\Resource, $this, BookingResource>
     */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'booking_resource')
            ->using(BookingResource::class)
            ->withPivot(['id', 'start_at', 'end_at', 'precio_final'])
            ->withTimestamps();
    }

    /**
     * Las filas de la pivote como modelo, para consultarlas y bloquearlas.
     *
     * @return HasMany<BookingResource, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(BookingResource::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
