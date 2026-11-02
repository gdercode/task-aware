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

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tasks' => ['required', 'array'],
            'tasks.*.name' => ['required', 'string', 'max:80'],
            'tasks.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            'tasks.*.urgency' => ['required', 'integer', 'min:1', 'max:5'],
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
                'is_critical' => $request->boolean('tasks.'.$id.'.is_critical'),
                'match_terms' => TaskPriority::normalizeTerms($row['match_terms'] ?? null),
            ]);
        }

        TaskPriority::flushCache();

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Task priorities saved. A higher priority gets a larger share. Use Apply changes on the dashboard to send the new allocation to the router.');
    }
}
