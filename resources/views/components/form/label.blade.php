@props([
    'for',
    'value' => null,
    'errorFor' => null,
])

<label for="{{ $for }}" {{ $attributes->merge(['class' => 'text-sm font-medium text-gray-700']) }}>{{ $value ?? $slot }}</label>

{{-- Los campos ya muestran su error; error-for es solo para un label usado suelto. --}}
@if ($errorFor)
    @error($errorFor)
        <p id="{{ $for }}-error" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
@endif
