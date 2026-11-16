<x-layouts::app :title="__('Panel')">
    <div class="flex flex-col gap-4">
        <h1 class="text-2xl font-semibold">{{ __('Panel') }}</h1>

        <p class="text-gray-700">{{ __('Has entrado como :name (:email).', ['name' => auth()->user()->name, 'email' => auth()->user()->email]) }}</p>
    </div>
</x-layouts::app>
