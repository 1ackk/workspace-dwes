<?php

namespace App\Models;

use App\Enums\ResourceType;
use App\Services\Occupancy\ResourceOccupancy;
use Carbon\CarbonInterface;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
     * Resultado del scope occupancyByPeriod. Va declarada para que Eloquent no la trate como
     * un atributo: no se guarda en la base de datos ni sale en toArray().
     */
    public ?ResourceOccupancy $occupancy = null;

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
     * Las filas de la pivote como modelo, para trabajar con las franjas del recurso.
     *
     * @return HasMany<BookingResource, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(BookingResource::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forSpace(Builder $query, int $spaceId): void
    {
        $query->where('space_id', $spaceId);
    }

    /**
     * Recursos sin ninguna reserva bloqueante que se solape con la franja [$start, $end).
     *
     * Dos franjas se solapan si inicio_A < fin_B y fin_A > inicio_B, de modo que una reserva
     * que termina justo cuando empieza otra no se solapa con ella.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query, CarbonInterface $start, CarbonInterface $end, ?int $ignoreBookingId = null): void
    {
        $query->whereDoesntHave('bookings', function (Builder $bookings) use ($start, $end, $ignoreBookingId): void {
            Booking::blocking($bookings);

            $bookings->where('booking_resource.start_at', '<', $end)
                ->where('booking_resource.end_at', '>', $start)
                ->when($ignoreBookingId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreBookingId));
        });
    }

    /**
     * Ocupación de cada recurso en el periodo semiabierto [$from, $to): deja el resultado en
     * la propiedad $occupancy de cada modelo devuelto por get().
     *
     * El recorte de franjas no se puede escribir en SQL portable, así que el scope carga solo
     * lo necesario y calcula en PHP. Son tres consultas, haya los recursos que haya.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function occupancyByPeriod(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        if ($to->lessThanOrEqualTo($from)) {
            throw new InvalidArgumentException('El final del periodo debe ser posterior a su inicio.');
        }

        $from = $from->copy()->setTimezone(config('app.timezone'));
        $to = $to->copy()->setTimezone(config('app.timezone'));

        $query->with([
            'space',
            'slots' => fn (HasMany $slots) => $slots
                ->where('start_at', '<', $to)
                ->where('end_at', '>', $from)
                ->whereHas('booking', fn (Builder $bookings) => Booking::blocking($bookings)),
        ])->afterQuery(function (iterable $results) use ($from, $to): void {
            // pluck() y cursor() también pasan por aquí, pero sin modelos o sin eager loading.
            foreach ($results as $resource) {
                if ($resource instanceof self && $resource->relationLoaded('slots')) {
                    $resource->occupancy = ResourceOccupancy::of($resource, $from, $to);
                }
            }
        });
    }
}
