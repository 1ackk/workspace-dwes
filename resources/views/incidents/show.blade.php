<x-layouts::app :title="__('Incidencia')">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ $incident->title }}</h1>

        <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
            <dt class="font-medium">{{ __('Estado') }}</dt>
            <dd>{{ $incident->status->label() }}</dd>

            <dt class="font-medium">{{ __('Recurso') }}</dt>
            <dd>{{ $incident->resource->name }}</dd>

            <dt class="font-medium">{{ __('Espacio') }}</dt>
            <dd>{{ $incident->resource->space->name }}</dd>

            <dt class="font-medium">{{ __('Abierta por') }}</dt>
            <dd>{{ $incident->user?->name ?? '—' }}</dd>

            <dt class="font-medium">{{ __('Fecha') }}</dt>
            <dd>{{ $incident->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>

            @if ($incident->resolved_at)
                <dt class="font-medium">{{ __('Resuelta el') }}</dt>
                <dd>{{ $incident->resolved_at->format('d/m/Y H:i') }}</dd>
            @endif
        </dl>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">{{ __('Descripción') }}</h2>
            <p class="whitespace-pre-line text-sm text-gray-800">{{ $incident->description }}</p>
        </section>
    </div>
</x-layouts::app>
