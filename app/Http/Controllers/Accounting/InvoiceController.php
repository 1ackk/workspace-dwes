<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GenerateInvoicesRequest;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Panel de contabilidad. El grupo de rutas solo deja entrar al contable; InvoicePolicy decide qué
 * puede hacer dentro (viewAny para el listado, create para generar).
 */
class InvoiceController extends Controller
{
    /** El comando es fijo: nunca viene de la petición. */
    public const string COMMAND = 'invoices:generate';

    public function index(): View
    {
        Gate::authorize('viewAny', Invoice::class);

        // Una factura sobrevive a su usuario: se carga también si está borrado lógicamente.
        $invoices = Invoice::query()
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('id')
            ->paginate(15);

        return view('accounting.index', [
            'invoices' => $invoices,
            'lastMonth' => today()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /**
     * Lanza invoices:generate con el mes ya validado como único argumento.
     */
    public function store(GenerateInvoicesRequest $request): RedirectResponse
    {
        $exitCode = Artisan::call(self::COMMAND, ['month' => $request->validated('month')]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            return to_route('accounting.index')->with('error', $output);
        }

        return to_route('accounting.index')->with('status', $output);
    }
}
