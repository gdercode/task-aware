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
        $roles = Role::query()->withCount('users')->orderByDesc('percentage')->orderBy('name')->get();

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
            'weight' => $validated['percentage'],
            'percentage' => $validated['percentage'],
            'is_default' => $request->boolean('is_default'),
        ]);

        Role::syncPercentages($role, $validated['percentage']);
        $this->keepSingleDefault($role->fresh());
        Role::flushCache();

        $role->refresh();

        return redirect()
            ->route('roles.index')
            ->with('success', $role->name.' saved. Each role percentage was recalculated and now adds up to 100%.');
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
            'is_default' => $request->boolean('is_default'),
        ]);

        Role::syncPercentages($role->fresh(), $validated['percentage']);
        $this->keepSingleDefault($role->fresh());
        Role::flushCache();

        $role->refresh();

        return redirect()
            ->route('roles.index')
            ->with('success', $role->name.' is '.$role->percentage.'%. The other roles were recalculated so the total stays 100%.');
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

        Role::syncPercentages();

        if ($wasDefault) {
            $replacement = Role::query()->orderBy('percentage')->first();
            $replacement?->update(['is_default' => true]);
        }

        Role::flushCache();

        return redirect()
            ->route('roles.index')
            ->with('success', $name.' removed.');
    }

    /**
     * @return array{name: string, percentage: int}
     */
    protected function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'integer', 'min:0', 'max:100'],
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
