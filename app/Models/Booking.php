<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Limita la consulta a las reservas que ocupan su franja. Una pendiente de pago vencida
     * ya no la ocupa, aunque el comando de limpieza no haya cambiado todavía su estado.
     *
     * No es un scope: es la regla común de los scopes available y cancellable. Es pública
     * porque Resource::available, que está en otro modelo, también la necesita.
     *
     * @param  Builder<self>  $query
     */
    public static function blocking(Builder $query, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        $query->whereIn('bookings.status', BookingStatus::blocking())
            ->where(function (Builder $query) use ($now): void {
                $query->where('bookings.status', '<>', BookingStatus::PendingPayment)
                    ->orWhereNull('bookings.expires_at')
                    ->orWhere('bookings.expires_at', '>', $now);
            });
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('date', '>=', today()->toDateString());
    }

    /**
     * Reservas que aún pueden cancelarse. La reserva se cancela entera, así que todas sus
     * franjas deben cumplir la antelación mínima del espacio de su recurso.
     *
     * Los límites se calculan en PHP, uno por cada plazo distinto, porque sumar a una fecha
     * las horas guardadas en otra columna no se escribe igual en SQLite, MySQL y PostgreSQL.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function cancellable(Builder $query): void
    {
        $now = now();
        $noticeHours = Space::query()->distinct()->pluck('cancellation_notice_hours');

        self::blocking($query);

        $query->where('bookings.status', '<>', BookingStatus::Completed)
            ->whereDoesntHave('resources', function (Builder $resources) use ($noticeHours, $now): void {
                $resources->join('spaces', 'spaces.id', '=', 'resources.space_id')
                    ->where(function (Builder $tooLate) use ($noticeHours, $now): void {
                        foreach ($noticeHours as $hours) {
                            $tooLate->orWhere(function (Builder $slot) use ($hours, $now): void {
                                $slot->where('spaces.cancellation_notice_hours', $hours)
                                    ->where('booking_resource.start_at', '<', $now->copy()->addHours($hours));
                            });
                        }
                    });
            });
    }
}
