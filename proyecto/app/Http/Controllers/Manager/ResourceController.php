<?php

namespace App\Http\Controllers\Manager;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreResourceRequest;
use App\Http\Requests\Manager\UpdateResourceRequest;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ResourceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Resource::class);

        $resources = Resource::query()
            ->with('space')
            ->whereIn('space_id', $request->user()->manageableSpaces()->select('id'))
            ->orderBy('space_id')
            ->orderBy('name')
            ->paginate(15);

        return view('manager.resources.index', ['resources' => $resources]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Resource::class);

        return view('manager.resources.create', $this->formOptions($request));
    }

    public function store(StoreResourceRequest $request): RedirectResponse
    {
        Resource::create($request->validated());

        return redirect()->route('manager.resources.index')
            ->with('status', __('Recurso creado.'));
    }

    public function edit(Request $request, Resource $resource): View
    {
        Gate::authorize('update', $resource);

        return view('manager.resources.edit', [
            'resource' => $resource,
            ...$this->formOptions($request),
        ]);
    }

    public function update(UpdateResourceRequest $request, Resource $resource): RedirectResponse
    {
        $resource->update($request->validated());

        return redirect()->route('manager.resources.index')
            ->with('status', __('Recurso actualizado.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'spaces' => $request->user()->manageableSpaces()->orderBy('name')->pluck('name', 'id'),
            'types' => collect(ResourceType::cases())
                ->mapWithKeys(fn (ResourceType $type) => [$type->value => $type->label()]),
        ];
    }
}
