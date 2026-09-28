<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()->withCount('users')->orderByDesc('weight')->orderBy('name')->get();

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        return view('roles.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);

        $role = Role::create([
            'slug' => Role::slugFromName($validated['name']),
            'name' => $validated['name'],
            'weight' => $validated['weight'],
            'is_default' => $request->boolean('is_default'),
        ]);

        $this->keepSingleDefault($role);

        Role::flushCache();

        return redirect()
            ->route('roles.index')
            ->with('success', $role->name.' added with value '.$role->weight.'.');
    }

    public function edit(Role $role): View
    {
        return view('roles.edit', compact('role'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $this->validateRole($request);

        $role->update([
            'name' => $validated['name'],
            'weight' => $validated['weight'],
            'is_default' => $request->boolean('is_default'),
        ]);

        $this->keepSingleDefault($role->fresh());

        Role::flushCache();

        return redirect()
            ->route('roles.index')
            ->with('success', $role->name.' updated. New allocations use value '.$role->weight.'.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->users()->exists()) {
            return redirect()
                ->route('roles.index')
                ->with('error', 'Move users off '.$role->name.' before deleting that role.');
        }

        if (Role::query()->count() <= 1) {
            return redirect()
                ->route('roles.index')
                ->with('error', 'Keep at least one role.');
        }

        $wasDefault = $role->is_default;
        $name = $role->name;
        $role->delete();

        if ($wasDefault) {
            $replacement = Role::query()->orderBy('weight')->first();
            $replacement?->update(['is_default' => true]);
        }

        Role::flushCache();

        return redirect()
            ->route('roles.index')
            ->with('success', $name.' removed.');
    }

    /**
     * @return array{name: string, weight: int}
     */
    protected function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
            'is_default' => ['nullable', Rule::in(['1', '0', 'on'])],
        ]);
    }

    protected function keepSingleDefault(Role $role): void
    {
        if (! $role->is_default) {
            if (! Role::query()->where('is_default', true)->exists()) {
                $role->update(['is_default' => true]);
            }

            return;
        }

        Role::query()->where('id', '!=', $role->id)->update(['is_default' => false]);
    }
}
