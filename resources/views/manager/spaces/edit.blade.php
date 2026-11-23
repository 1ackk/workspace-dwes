<x-layouts::app :title="__('Editar espacio')">
    <div class="mx-auto flex max-w-md flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ __('Editar espacio') }}</h1>

        <form method="POST" action="{{ route('manager.spaces.update', $space) }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            <x-form.input name="name" :label="__('Nombre')" :value="$space->name" required />

            <div class="flex flex-col gap-1">
                <x-form.label for="description" :value="__('Descripción')" />
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm aria-invalid:border-red-600"
                >{{ old('description', $space->description) }}</textarea>
                @error('description')
                    <p id="description-error" class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <x-form.input name="location" :label="__('Ubicación')" :value="$space->location" required />
            <x-form.input name="cancellation_notice_hours" type="number" min="0" :label="__('Antelación mínima para cancelar (horas)')" :value="$space->cancellation_notice_hours" required />

            <button type="submit" class="cursor-pointer rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Guardar cambios') }}</button>
        </form>

        <a href="{{ route('manager.spaces.index') }}" class="text-sm underline">{{ __('Volver a espacios') }}</a>
    </div>
</x-layouts::app>
