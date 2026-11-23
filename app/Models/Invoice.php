<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $rectifies_invoice_id
 * @property string $number
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $amount
 * @property InvoiceStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $last_reminded_at Último aviso de factura vencida.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $balance Solo si la consulta lo añade con balanceQuery() (p. ej. el scope overdueInvoices).
 */
#[Fillable(['user_id', 'rectifies_invoice_id', 'number', 'period_start', 'period_end', 'amount', 'status', 'issued_at', 'due_at'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'last_reminded_at' => 'datetime',
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
     * Invoice 1:N Booking.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Factura anulada que esta factura rectifica.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function rectifies(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifies_invoice_id');
    }

    /**
     * Factura rectificativa que sustituye a esta.
     *
     * @return HasOne<Invoice, $this>
     */
    public function rectifiedBy(): HasOne
    {
        return $this->hasOne(self::class, 'rectifies_invoice_id');
    }

    /**
     * Subconsulta del saldo pendiente de la factura de la consulta exterior (invoices.id):
     * reservas facturables menos pagos completados. ROUND evita que el error de coma flotante
     * de SQLite deje céntimos fantasma.
     *
     * No es un scope: es el cálculo común del scope overdueInvoices y del detalle de una
     * factura, que la añade con selectSub (como Booking::blocking en los scopes de reservas).
     *
     * @return Builder<Booking>
     */
    public static function balanceQuery(?CarbonInterface $now = null): Builder
    {
        $balance = Booking::query()
            ->selectRaw(
                'ROUND(COALESCE(SUM(bookings.total), 0) - (
                    SELECT COALESCE(SUM(payments.amount), 0) FROM payments
                    WHERE payments.invoice_id = invoices.id AND payments.status = ?
                ), 2)',
                [PaymentStatus::Completed->value],
            )
            ->whereColumn('bookings.invoice_id', 'invoices.id');
        Booking::blocking($balance, $now);

        return $balance;
    }

    /**
     * Facturas pendientes, vencidas antes de la fecha de referencia y con saldo por cobrar.
     * Añade la columna calculada balance con balanceQuery().
     *
     * La fecha de referencia solo mueve el corte de vencimiento: el saldo es siempre el actual.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdueInvoices(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $now = now();
        $referenceDate = ($asOf ?? $now)->copy()->setTimezone(config('app.timezone'))->toDateString();

        $balance = self::balanceQuery($now);

        $query->select('invoices.*')
            ->selectSub($balance, 'balance')
            ->withCasts(['balance' => 'decimal:2'])
            ->where('invoices.status', InvoiceStatus::Pending)
            ->where('invoices.due_at', '<', $referenceDate)
            ->where(new Expression('0'), '<', $balance);
    }
}
