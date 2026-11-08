@props([
    'name',
    'label' => null,
    'value' => null,
    'id' => null,
])

@php
    $id ??= $name;
    $value = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <x-form.label :for="$id" :value="$label" />
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="date"
        value="{{ old($name, $value) }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        {{ $attributes->merge(['class' => 'rounded-md border border-gray-300 px-3 py-2 text-sm aria-invalid:border-red-600']) }}
    >

    @error($name)
        <p id="{{ $id }}-error" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
