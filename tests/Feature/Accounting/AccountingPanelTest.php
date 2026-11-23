<?php

use App\Enums\BookingStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Accounting\InvoiceController;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-12-01 09:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RoleSeeder::class);

    $this->accountant = userWithRole(RoleName::Accountant);
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

function billableInNovember(string $email, string $total = '25.00'): User
{
    $user = User::factory()->create(['email' => $email]);
    Booking::factory()->for($user, 'user')->create([
        'status' => BookingStatus::Completed,
        'expires_at' => null,
        'date' => '2026-11-15',
        'total' => $total,
    ]);

    return $user;
}

describe('list', function () {
    test('the accountant lists the latest invoices with their data', function () {
        $user = User::factory()->create(['email' => 'ana@workspace.test']);
        Invoice::factory()->for($user, 'user')->overdue()->create([
            'number' => 'FAC-2026-000001',
            'amount' => '45.50',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);

        $this->actingAs($this->accountant)
            ->get(route('accounting.index'))
            ->assertOk()
            ->assertSee('FAC-2026-000001')
            ->assertSee('ana@workspace.test')
            ->assertSee('01/10/2026 – 31/10/2026')
            ->assertSee('45,50 €')
            ->assertSee('Pendiente')
            ->assertSee('26/11/2026')
            ->assertSee(route('accounting.invoices.generate'))
            ->assertSee('name="_token"', false)
            ->assertSee('value="2026-11"', false);
    });

    test('shows the user of an invoice even if soft deleted', function () {
        $gone = User::factory()->create(['email' => 'baja@workspace.test']);
        Invoice::factory()->for($gone, 'user')->create(['number' => 'FAC-2026-000002']);
        $gone->delete();

        $this->actingAs($this->accountant)
            ->get(route('accounting.index'))
            ->assertOk()
            ->assertSee('baja@workspace.test')
            ->assertSee('dado de baja');
    });

    test('the list is paginated, newest first, without N+1 queries', function () {
        collect(range(1, 17))->each(fn (int $n) => Invoice::factory()->create(['number' => sprintf('FAC-2026-%06d', $n)]));

        Model::preventLazyLoading();

        $invoices = $this->actingAs($this->accountant)
            ->get(route('accounting.index'))
            ->assertOk()
            ->viewData('invoices');

        expect($invoices->total())->toBe(17)
            ->and($invoices->count())->toBe(15)
            ->and($invoices->first()->number)->toBe('FAC-2026-000017');
    });

    test('shows an empty state', function () {
        $this->actingAs($this->accountant)
            ->get(route('accounting.index'))
            ->assertOk()
            ->assertSee('Todavía no hay facturas.');
    });
});

describe('generate', function () {
    test('the accountant generates last month and sees the result', function () {
        billableInNovember('ana@workspace.test', '25.00');
        billableInNovember('luis@workspace.test', '10.50');

        $this->actingAs($this->accountant)
            ->post(route('accounting.invoices.generate'), ['month' => '2026-11'])
            ->assertRedirect(route('accounting.index'))
            ->assertSessionHas('status', 'Facturas generadas en 11/2026: 2. Reservas facturadas: 2. Importe total: 35.50 €.');

        expect(Invoice::count())->toBe(2);

        $this->actingAs($this->accountant)
            ->get(route('accounting.index'))
            ->assertSee('Facturas generadas en 11/2026: 2.')
            ->assertSee('FAC-2026-000002');
    });

    test('a second time says there is nothing to bill', function () {
        billableInNovember('ana@workspace.test');

        $this->actingAs($this->accountant)->post(route('accounting.invoices.generate'), ['month' => '2026-11']);

        $this->actingAs($this->accountant)
            ->post(route('accounting.invoices.generate'), ['month' => '2026-11'])
            ->assertSessionHas('status', 'No hay reservas que facturar en 11/2026.');

        expect(Invoice::count())->toBe(1);
    });

    test('rejects an invalid or unfinished month in Spanish and runs nothing', function (string $month, string $message) {
        Artisan::spy();

        $this->actingAs($this->accountant)
            ->from(route('accounting.index'))
            ->post(route('accounting.invoices.generate'), ['month' => $month])
            ->assertRedirect(route('accounting.index'))
            ->assertSessionHasErrors(['month' => $message]);

        Artisan::shouldNotHaveReceived('call');
    })->with([
        'vacío' => ['', 'El mes es obligatorio.'],
        'mes en curso' => ['2026-12', 'Solo se puede facturar un mes ya terminado.'],
        'mes futuro' => ['2027-03', 'Solo se puede facturar un mes ya terminado.'],
        'formato' => ['2026-13', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
        'texto' => ['noviembre', 'El mes debe tener el formato AAAA-MM, por ejemplo 2026-10.'],
    ]);

    test('the command and its options cannot be injected from the request', function () {
        Artisan::shouldReceive('call')->once()->with('invoices:generate', ['month' => '2026-11'])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("No hay reservas que facturar en 11/2026.\n");

        $this->actingAs($this->accountant)
            ->post(route('accounting.invoices.generate'), [
                'month' => '2026-11',
                'command' => 'db:wipe',
                'user' => '1',
                '--user' => '1',
                'dry-run' => '1',
                '--dry-run' => '1',
            ])
            ->assertRedirect(route('accounting.index'))
            ->assertSessionHas('status', 'No hay reservas que facturar en 11/2026.');

        expect(InvoiceController::COMMAND)->toBe('invoices:generate');
    });

    test('a command that fails is shown as an error', function () {
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Algo ha ido mal.');

        $this->actingAs($this->accountant)
            ->post(route('accounting.invoices.generate'), ['month' => '2026-11'])
            ->assertSessionHas('error', 'Algo ha ido mal.')
            ->assertSessionMissing('status');
    });

    test('the flash message is escaped', function () {
        $this->actingAs($this->accountant)
            ->withSession(['status' => '<script>alert(1)</script>'])
            ->get(route('accounting.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });
});

describe('access', function () {
    test('other roles receive a 403 and nothing is generated', function (RoleName $role) {
        billableInNovember('ana@workspace.test');
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('accounting.index'))->assertForbidden();
        $this->actingAs($user)->post(route('accounting.invoices.generate'), ['month' => '2026-11'])->assertForbidden();

        expect(Invoice::count())->toBe(0);
    })->with([
        'usuario' => RoleName::Member,
        'recepción' => RoleName::Reception,
        'responsable' => RoleName::SpaceManager,
        'administrador' => RoleName::Admin,
    ]);

    test('a guest goes to the login', function () {
        $this->get(route('accounting.index'))->assertRedirect(route('login'));
        $this->post(route('accounting.invoices.generate'), ['month' => '2026-11'])->assertRedirect(route('login'));
    });

    test('an accountant without the permissions gets a 403 from the policy', function () {
        $this->accountant->syncPermissions([]);
        $this->accountant->roles->first()->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->accountant->fresh())->get(route('accounting.index'))->assertForbidden();
        $this->actingAs($this->accountant->fresh())->post(route('accounting.invoices.generate'), ['month' => '2026-11'])->assertForbidden();
    });
});
