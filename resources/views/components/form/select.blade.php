@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => null,
    'placeholder' => __('Selecciona una opción'),
    'id' => null,
])

@php
    $id ??= $name;
    $selected = (string) old($name, $value);
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <x-form.label :for="$id" :value="$label" />
    @endif

    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        {{ $attributes->merge(['class' => 'rounded-md border border-gray-300 px-3 py-2 text-sm aria-invalid:border-red-600']) }}
    >
        {{-- Para quitar la opción vacía: :placeholder="false" --}}
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $optionValue === $selected)>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @error($name)
        <p id="{{ $id }}-error" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
