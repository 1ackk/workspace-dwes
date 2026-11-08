<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    // En una petición real lo comparte el middleware ShareErrorsFromSession.
    $this->withViewErrors([]);
});

function flashOldInput(array $input): void
{
    request()->setLaravelSession(app('session')->driver());
    session()->flashInput($input);
}

describe('layout', function () {
    test('renders the title and the content', function () {
        $this->withoutVite();

        $this->blade('<x-layouts::app title="Reservas"><p>Contenido</p></x-layouts::app>')
            ->assertSee('<title>Reservas - '.config('app.name').'</title>', false)
            ->assertSee('<p>Contenido</p>', false);
    });

    test('uses only the app name when there is no title', function () {
        $this->withoutVite();

        $this->blade('<x-layouts::app>Contenido</x-layouts::app>')
            ->assertSee('<title>'.config('app.name').'</title>', false);
    });
});

describe('label', function () {
    test('renders the text linked to its field', function () {
        $this->blade('<x-form.label for="email" value="Correo" />')
            ->assertSee('for="email"', false)
            ->assertSee('Correo');
    });

    test('accepts the text as a slot', function () {
        $this->blade('<x-form.label for="email">Correo</x-form.label>')
            ->assertSee('Correo');
    });

    test('does not show the error by default', function () {
        $this->withViewErrors(['email' => 'El correo es obligatorio.']);

        $this->blade('<x-form.label for="email" value="Correo" />')
            ->assertDontSee('El correo es obligatorio.');
    });

    test('shows the error with error-for when it exists', function () {
        $this->withViewErrors(['email' => 'El correo es obligatorio.']);

        $this->blade('<x-form.label for="email" value="Correo" error-for="email" />')
            ->assertSee('El correo es obligatorio.');
    });

    test('shows no error with error-for when the field is valid', function () {
        $this->blade('<x-form.label for="email" value="Correo" error-for="email" />')
            ->assertDontSee('text-red-600', false);
    });
});

describe('input', function () {
    test('renders the label linked to the field', function () {
        $this->blade('<x-form.input name="email" label="Correo" />')
            ->assertSee('for="email"', false)
            ->assertSee('id="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee('Correo');
    });

    test('uses a custom id for the label and the field', function () {
        $this->blade('<x-form.input name="email" id="contact-email" label="Correo" />')
            ->assertSee('for="contact-email"', false)
            ->assertSee('id="contact-email"', false);
    });

    test('shows the given value', function () {
        $this->blade('<x-form.input name="email" value="ana@workspace.test" />')
            ->assertSee('value="ana@workspace.test"', false);
    });

    test('prefers the previous value over the given one', function () {
        flashOldInput(['email' => 'previo@workspace.test']);

        $this->blade('<x-form.input name="email" value="ana@workspace.test" />')
            ->assertSee('value="previo@workspace.test"', false)
            ->assertDontSee('value="ana@workspace.test"', false);
    });

    test('shows the error when it exists', function () {
        $this->withViewErrors(['email' => 'El correo es obligatorio.']);

        $this->blade('<x-form.input name="email" />')
            ->assertSee('El correo es obligatorio.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="email-error"', false)
            ->assertSee('id="email-error"', false);
    });

    test('shows no error when the field is valid', function () {
        $this->withViewErrors(['name' => 'El nombre es obligatorio.']);

        $this->blade('<x-form.input name="email" />')
            ->assertDontSee('El nombre es obligatorio.')
            ->assertDontSee('aria-invalid="true"', false);
    });

    test('passes extra attributes to the field', function () {
        $this->blade('<x-form.input name="email" type="email" required class="w-full" />')
            ->assertSee('type="email"', false)
            ->assertSee('required', false)
            ->assertSee('w-full', false);
    });
});

describe('select', function () {
    beforeEach(function () {
        $this->template = '<x-form.select name="space" label="Espacio" :options="$options" :value="$value" />';
        $this->data = ['options' => [1 => 'Centro', 2 => 'Norte'], 'value' => null];
    });

    test('renders the label linked to the field', function () {
        $this->blade($this->template, $this->data)
            ->assertSee('for="space"', false)
            ->assertSee('id="space"', false)
            ->assertSee('Espacio');
    });

    test('renders its options with a placeholder', function () {
        $this->blade($this->template, $this->data)
            ->assertSeeInOrder([__('Selecciona una opción'), 'Centro', 'Norte'])
            ->assertSee('<option value="1" >Centro</option>', false)
            ->assertDontSee('selected', false);
    });

    test('can render without a placeholder', function () {
        $this->blade('<x-form.select name="space" :options="[1 => \'Centro\']" :placeholder="false" />')
            ->assertDontSee(__('Selecciona una opción'));
    });

    test('selects the given value', function () {
        $this->blade($this->template, [...$this->data, 'value' => 2])
            ->assertSee('<option value="2" selected>Norte</option>', false)
            ->assertDontSee('<option value="1" selected>', false);
    });

    test('prefers the previous value over the given one', function () {
        flashOldInput(['space' => '1']);

        $this->blade($this->template, [...$this->data, 'value' => 2])
            ->assertSee('<option value="1" selected>Centro</option>', false)
            ->assertDontSee('<option value="2" selected>', false);
    });

    test('shows the error when it exists', function () {
        $this->withViewErrors(['space' => 'El espacio es obligatorio.']);

        $this->blade($this->template, $this->data)
            ->assertSee('El espacio es obligatorio.')
            ->assertSee('aria-invalid="true"', false);
    });

    test('shows no error when the field is valid', function () {
        $this->blade($this->template, $this->data)
            ->assertDontSee('aria-invalid="true"', false)
            ->assertDontSee('space-error', false);
    });
});

describe('date', function () {
    test('renders a date input with its label', function () {
        $this->blade('<x-form.date name="start_on" label="Inicio" />')
            ->assertSee('type="date"', false)
            ->assertSee('for="start_on"', false)
            ->assertSee('id="start_on"', false)
            ->assertSee('Inicio');
    });

    test('formats a date object for the input', function () {
        $this->blade('<x-form.date name="start_on" :value="$date" />', ['date' => Carbon::parse('2026-11-08 10:30')])
            ->assertSee('value="2026-11-08"', false);
    });

    test('prefers the previous value over the given one', function () {
        flashOldInput(['start_on' => '2026-12-01']);

        $this->blade('<x-form.date name="start_on" value="2026-11-08" />')
            ->assertSee('value="2026-12-01"', false)
            ->assertDontSee('value="2026-11-08"', false);
    });

    test('shows the error when it exists', function () {
        $this->withViewErrors(['start_on' => 'La fecha no es válida.']);

        $this->blade('<x-form.date name="start_on" />')
            ->assertSee('La fecha no es válida.')
            ->assertSee('aria-invalid="true"', false);
    });

    test('shows no error when the field is valid', function () {
        $this->blade('<x-form.date name="start_on" />')
            ->assertDontSee('aria-invalid="true"', false)
            ->assertDontSee('start_on-error', false);
    });
});

describe('checkbox', function () {
    test('renders the label linked to the field', function () {
        $this->blade('<x-form.checkbox name="active" label="Activo" />')
            ->assertSee('type="checkbox"', false)
            ->assertSee('for="active"', false)
            ->assertSee('id="active"', false)
            ->assertSee('Activo');
    });

    test('is unchecked by default', function () {
        $this->blade('<x-form.checkbox name="active" />')
            ->assertDontSee('checked', false);
    });

    test('can be checked initially', function () {
        $this->blade('<x-form.checkbox name="active" :checked="true" />')
            ->assertSee('checked', false);
    });

    test('stays checked when it was sent in the previous request', function () {
        flashOldInput(['active' => '1']);

        $this->blade('<x-form.checkbox name="active" />')
            ->assertSee('checked', false);
    });

    test('stays unchecked when the previous request did not send it', function () {
        flashOldInput(['email' => 'ana@workspace.test']);

        $this->blade('<x-form.checkbox name="active" :checked="true" />')
            ->assertDontSee('checked', false);
    });

    test('shows the error when it exists', function () {
        $this->withViewErrors(['active' => 'Debes aceptar.']);

        $this->blade('<x-form.checkbox name="active" />')
            ->assertSee('Debes aceptar.')
            ->assertSee('aria-invalid="true"', false);
    });

    test('shows no error when the field is valid', function () {
        $this->blade('<x-form.checkbox name="active" />')
            ->assertDontSee('aria-invalid="true"', false)
            ->assertDontSee('active-error', false);
    });
});
