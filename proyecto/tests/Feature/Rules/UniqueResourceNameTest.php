<?php

use App\Models\Resource;
use App\Models\Space;
use App\Rules\UniqueResourceName;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->space = Space::factory()->create();
    $this->resource = Resource::factory()->for($this->space)->create(['name' => 'Sala Azul']);
});

function validateResourceName(mixed $name, UniqueResourceName $rule): Illuminate\Validation\Validator
{
    return Validator::make(['name' => $name], ['name' => [$rule]]);
}

test('accepts a free name', function () {
    expect(validateResourceName('Sala Verde', new UniqueResourceName($this->space->id))->passes())->toBeTrue();
});

test('rejects a name used in the space with a translatable message', function () {
    $validator = validateResourceName('Sala Azul', new UniqueResourceName($this->space->id));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('name'))->toBe(__('Ya existe un recurso con este nombre en el espacio.'));
});

test('uses the translation of the message', function () {
    app('translator')->setLoaded(['*' => ['*' => [app()->getLocale() => [
        'Ya existe un recurso con este nombre en el espacio.' => 'This name is taken.',
    ]]]]);

    $validator = validateResourceName('Sala Azul', new UniqueResourceName($this->space->id));

    expect($validator->errors()->first('name'))->toBe('This name is taken.');
});

test('does not distinguish case', function () {
    expect(validateResourceName('sala AZUL', new UniqueResourceName($this->space->id))->fails())->toBeTrue();
});

test('accepts a name used in another space', function () {
    $otherSpace = Space::factory()->create();

    expect(validateResourceName('Sala Azul', new UniqueResourceName($otherSpace->id))->passes())->toBeTrue();
});

test('ignores the resource being edited', function () {
    expect(validateResourceName('Sala Azul', new UniqueResourceName($this->space->id, $this->resource))->passes())->toBeTrue();
});

test('still rejects the name of another resource when editing', function () {
    $other = Resource::factory()->for($this->space)->create(['name' => 'Sala Verde']);

    expect(validateResourceName('Sala Azul', new UniqueResourceName($this->space->id, $other))->fails())->toBeTrue();
});

test('does nothing with a value that is not text', function () {
    expect(validateResourceName(['Sala Azul'], new UniqueResourceName($this->space->id))->passes())->toBeTrue();
});
