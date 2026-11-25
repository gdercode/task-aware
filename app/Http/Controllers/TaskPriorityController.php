<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\TaskPriority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskPriorityController extends Controller
{
    public function index(): View
    {
        $tasks = TaskPriority::query()->orderBy('sort_order')->orderBy('id')->get();
        $flows = Flow::query()
            ->with('user')
            ->where('is_active', true)
            ->latest('updated_at')
            ->limit(100)
            ->get();

        return view('tasks.index', compact('tasks', 'flows'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
            'urgency' => ['required', 'integer', 'min:1', 'max:5'],
            'failure_risk' => ['required', 'integer', 'min:1', 'max:5'],
            'match_terms' => ['required', 'string', 'max:1000'],
        ]);

        $terms = TaskPriority::normalizeTerms($validated['match_terms']);
        if ($terms === null) {
            return back()->withInput()->withErrors([
                'match_terms' => 'Enter at least one word the destination must contain.',
            ]);
        }

        $task = TaskPriority::create([
            'slug' => TaskPriority::slugFromName($validated['name']),
            'name' => $validated['name'],
            'weight' => $validated['weight'],
            'urgency' => $validated['urgency'],
            'failure_risk' => $validated['failure_risk'],
            'is_critical' => $request->boolean('is_critical'),
            'match_terms' => $terms,
            'sort_order' => ((int) TaskPriority::query()->max('sort_order')) + 1,
        ]);

        TaskPriority::flushCache();

        return redirect()
            ->route('tasks.index')
            ->with('success', $task->name.' added. It is checked after the tasks above it. Restart php artisan bandwidth:run so the allocator uses it.');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tasks' => ['required', 'array'],
            'tasks.*.name' => ['required', 'string', 'max:80'],
            'tasks.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            'tasks.*.urgency' => ['required', 'integer', 'min:1', 'max:5'],
            'tasks.*.failure_risk' => ['nullable', 'integer', 'min:1', 'max:5'],
            'tasks.*.match_terms' => ['nullable', 'string', 'max:1000'],
        ]);

        $existing = TaskPriority::query()->get()->keyBy('id');

        foreach ($validated['tasks'] as $id => $row) {
            $task = $existing->get((int) $id);
            if (! $task) {
                continue;
            }

            $task->update([
                'name' => $row['name'],
                'weight' => $row['weight'],
                'urgency' => $row['urgency'],
                'failure_risk' => $row['failure_risk'] ?? $task->failure_risk,
                'is_critical' => $request->boolean('tasks.'.$id.'.is_critical'),
                'match_terms' => TaskPriority::normalizeTerms($row['match_terms'] ?? null),
            ]);
        }

        TaskPriority::flushCache();

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Task priorities saved. Restart php artisan bandwidth:run so the allocator uses them.');
    }
}
