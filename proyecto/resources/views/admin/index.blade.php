<x-layouts::app :title="__('Administración')">
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-semibold">{{ __('Administración') }}</h1>

        <p class="text-gray-700">{{ __('Página provisional para el rol administrador.') }}</p>

        <a href="{{ route('manager.resources.index') }}" class="font-medium underline">{{ __('Recursos') }}</a>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">{{ __('Mantenimiento') }}</h2>

            @session('status')
                <p class="text-sm text-green-700">{{ $value }}</p>
            @endsession

            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.maintenance.run', 'release-unpaid') }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Liberar reservas sin pagar') }}</button>
                </form>

                <form method="POST" action="{{ route('admin.maintenance.run', 'complete-past') }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('Completar reservas terminadas') }}</button>
                </form>
            </div>
        </section>
    </div>
</x-layouts::app>
