@props([
    'name',
    'label' => null,
    'value' => '1',
    'checked' => false,
    'id' => null,
])

@php
    $id ??= $name;
    // Un checkbox desmarcado no se envía: si hubo un envío fallido, manda lo que llegó en él.
    $isChecked = old() ? (bool) old($name) : (bool) $checked;
@endphp

<div class="flex flex-col gap-1">
    <div class="flex items-center gap-2">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="checkbox"
            value="{{ $value }}"
            @checked($isChecked)
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
            {{ $attributes->merge(['class' => 'size-4 rounded border-gray-300']) }}
        >

        @if ($label)
            <x-form.label :for="$id" :value="$label" />
        @endif
    </div>

    @error($name)
        <p id="{{ $id }}-error" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
