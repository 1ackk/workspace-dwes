<?php

namespace App\Http\Requests\Accounting;

use App\Models\Invoice;
use App\Rules\FinishedMonth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerateInvoicesRequest extends FormRequest
{
    /**
     * InvoicePolicy::create (permiso invoices.create). El grupo de rutas ya limita al contable.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /**
     * Solo se acepta el mes: el formulario no puede pasar --user ni --dry-run al comando.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', new FinishedMonth],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'month.required' => __('El mes es obligatorio.'),
            'month.string' => __('El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'),
        ];
    }
}
