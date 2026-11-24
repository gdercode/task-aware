<?php

namespace Tests\Unit;

use App\Models\Flow;
use App\Models\Role;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\ImportanceEngineService;
use App\Services\TrafficDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TaskAwareAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('database')->forget('bandwidth.allocation_state');
        Cache::store('database')->forget('bandwidth.study_phase');
    }

    public function test_priority_is_the_sum_of_role_task_urgency_and_failure_risk(): void
    {
        Role::create([
            'slug' => 'guest',
            'name' => 'Guest',
            'weight' => 0,
            'percentage' => 0,
            'is_default' => false,
        ]);

        $engine = app(ImportanceEngineService::class);
        $weights = $engine->weights('student', 'REAL_TIME', $engine->urgencyFor('REAL_TIME'));
        $realtime = $engine->calculate('student', 'REAL_TIME', $engine->urgencyFor('REAL_TIME'));
        $bulk = $engine->calculate('student', 'BULK', $engine->urgencyFor('BULK'));

        $this->assertSame(
            $weights['role'] + $weights['task'] + $weights['urgency'] + $weights['failure'],
            $weights['priority']
        );
        $this->assertSame($weights['priority'], $realtime);
        $this->assertSame(5, $weights['failure']);
        $this->assertGreaterThan($bulk, $realtime);
        $this->assertSame(5, $engine->urgencyFor('REAL_TIME'));
        $this->assertSame(1, $engine->urgencyFor('BULK'));
        $this->assertSame(0, $engine->calculate('guest', 'REAL_TIME', 5));
    }

    public function test_congestion_is_utilization_against_the_threshold(): void
    {
        $engine = app(ImportanceEngineService::class);

        $this->assertTrue($engine->assessCongestion(10000, 8000, 1)['congested']);
        $this->assertSame(80, $engine->assessCongestion(10000, 8000, 1)['utilization_percent']);
        $this->assertFalse($engine->assessCongestion(10000, 1000, 2)['congested']);
    }

    public function test_congestion_response_waits_then_restores_gradually(): void
    {
        $engine = app(ImportanceEngineService::class);

        $first = $engine->advanceAllocationState(90);
        $this->assertSame(1, $first['streak']);
        $this->assertSame('normal', $first['phase']);
        $this->assertEqualsWithDelta(0.0, $first['pressure'], 0.001);

        $second = $engine->advanceAllocationState(90);
        $this->assertSame('protecting', $second['phase']);
        $this->assertEqualsWithDelta(1.0, $second['pressure'], 0.001);

        $adjusted = $engine->applyCongestionResponse(
            [1 => 37, 2 => 51],
            [1 => ['task' => 'REAL_TIME'], 2 => ['task' => 'BULK']],
            $second['pressure'],
        );
        $this->assertGreaterThan($adjusted[2], $adjusted[1]);
        $this->assertGreaterThan(0, $adjusted[2]);

        $restore = $engine->advanceAllocationState(10);
        $this->assertSame(0, $restore['streak']);
        $this->assertSame('restoring', $restore['phase']);
        $this->assertGreaterThan(0, $restore['pressure']);
        $this->assertLessThan(1, $restore['pressure']);
    }

    public function test_sustained_congestion_raises_critical_tasks_then_restores(): void
    {
        [$student, $dean] = $this->pair('REAL_TIME', 'BULK');

        $calm = $this->allocate($student, $dean, 100, 100);
        $this->assertFalse($calm['congested']);
        $this->assertSame('normal', $calm['allocation_phase']);
        $this->assertGreaterThan($this->share($calm, $student), $this->share($calm, $dean));

        $pending = $this->allocate($student, $dean, 4000, 4000);
        $this->assertTrue($pending['congested']);
        $this->assertSame('normal', $pending['allocation_phase']);
        $this->assertGreaterThan($this->share($pending, $student), $this->share($pending, $dean));

        $busy = $this->allocate($student, $dean, 4000, 4000);
        $this->assertSame('protecting', $busy['allocation_phase']);
        $this->assertGreaterThan($this->share($busy, $dean), $this->share($busy, $student));
        $this->assertGreaterThan(0, $this->share($busy, $dean));
        $this->assertSame(10000, $this->share($busy, $student) + $this->share($busy, $dean));

        $calmRatio = $this->share($calm, $student) / max(1, $this->share($calm, $dean));
        $busyRatio = $this->share($busy, $student) / max(1, $this->share($busy, $dean));
        $this->assertGreaterThan($calmRatio, $busyRatio);

        $restoring = $this->allocate($student, $dean, 100, 100);
        $this->assertSame('restoring', $restoring['allocation_phase']);
        $this->assertFalse($restoring['congested']);
        $this->assertGreaterThan($this->share($calm, $student), $this->share($restoring, $student));
        $this->assertSame(10000, $this->share($restoring, $student) + $this->share($restoring, $dean));
    }

    public function test_baseline_gives_every_active_device_the_same_share(): void
    {
        [$student, $dean] = $this->pair('REAL_TIME', 'BULK');
        app(ImportanceEngineService::class)->setStudyPhase('baseline');

        $allocation = $this->allocate($student, $dean, 4000, 4000);

        $this->assertSame('baseline', $allocation['study_phase']);
        $this->assertSame('baseline', $allocation['allocation_phase']);
        $this->assertEqualsWithDelta($this->share($allocation, $student), $this->share($allocation, $dean), 1);
        $this->assertSame(10000, $this->share($allocation, $student) + $this->share($allocation, $dean));
    }

    public function test_share_is_the_priority_fraction_of_the_pool(): void
    {
        $dean = $this->makeUser('dean', '10.0.0.20', 'REAL_TIME');
        $lecturer = $this->makeUser('lecturer', '10.0.0.21', 'LEARNING');
        $pool = 41102;

        $allocation = app(AllocationPreviewService::class)->build($pool, [
            $dean->ip_address => true,
            $lecturer->ip_address => true,
        ], [
            $dean->id => ['download_kbps' => 1500, 'upload_kbps' => 0, 'total_kbps' => 1500],
            $lecturer->id => ['download_kbps' => 16202, 'upload_kbps' => 0, 'total_kbps' => 16202],
        ], [
            $dean->ip_address => true,
            $lecturer->ip_address => true,
        ]);

        $this->assertFalse($allocation['congested']);
        $this->assertGreaterThan($this->share($allocation, $lecturer), $this->share($allocation, $dean));
        $this->assertSame($pool, $this->share($allocation, $dean) + $this->share($allocation, $lecturer));
    }

    public function test_same_task_still_favors_higher_institutional_role(): void
    {
        [$student, $dean] = $this->pair('REAL_TIME', 'REAL_TIME');
        $calm = $this->allocate($student, $dean, 100, 100);

        $this->assertFalse($calm['congested']);
        $this->assertGreaterThan($this->share($calm, $student), $this->share($calm, $dean));
    }

    public function test_zero_role_is_blocked_even_when_online(): void
    {
        Role::create([
            'slug' => 'guest',
            'name' => 'Guest',
            'weight' => 0,
            'percentage' => 0,
            'is_default' => false,
        ]);

        $guest = $this->makeUser('guest', '10.0.0.9', 'REAL_TIME');
        $allocation = app(AllocationPreviewService::class)->build(10000, [
            $guest->ip_address => true,
        ], [
            $guest->id => ['download_kbps' => 5000, 'upload_kbps' => 0, 'total_kbps' => 5000],
        ], [
            $guest->ip_address => true,
        ]);

        $this->assertSame(0, $this->share($allocation, $guest));
    }

    public function test_traffic_classes_match_institutional_activities(): void
    {
        $detector = app(TrafficDetectionService::class);

        $this->assertSame('REAL_TIME', $detector->classify('zoom.us:443'));
        $this->assertSame('LEARNING', $detector->classify('moodle.university.edu:443'));
        $this->assertSame('COLLABORATION', $detector->classify('docs.google.com:443'));
        $this->assertSame('STREAMING', $detector->classify('youtube.com:443'));
        $this->assertSame('DATA_TRANSFER', $detector->classify('files.example.edu:443', 1000, true));
        $this->assertSame('BULK', $detector->classify('files.example.edu:443', 60_000_000));
    }

    public function test_pool_uses_the_heavier_direction_only(): void
    {
        $mikrotik = app(\App\Services\MikrotikService::class);

        $this->assertSame(18_000, $mikrotik->directionalKbps(18_000_000, 1_500_000));
        $this->assertSame(18_000, $mikrotik->directionalKbps(1_500_000, 18_000_000));
    }

    public function test_dashboard_and_roles_pages_render(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Task-Aware Bandwidth')
            ->assertSee('U(t)')
            ->assertSee('Adaptive Phase')
            ->assertSee('Baseline Phase');
        $this->get('/roles')->assertOk()->assertSee('institutional importance');
        $this->get('/tasks')->assertOk()->assertSee('Failure risk');
    }

    public function test_task_page_shows_destinations_and_saved_priority_changes_the_share(): void
    {
        $video = $this->makeUser('student', '10.0.0.30', 'REAL_TIME');
        $learning = $this->makeUser('student', '10.0.0.31', 'LEARNING');
        Flow::where('user_id', $video->id)->update(['destination' => 'zoom.us:443']);
        Flow::where('user_id', $learning->id)->update(['destination' => 'moodle.university.edu:443']);

        $this->get('/tasks')
            ->assertOk()
            ->assertSee('Video conferencing')
            ->assertSee('zoom.us:443')
            ->assertSee('moodle.university.edu:443');

        $before = $this->allocatePair($video, $learning, 100, 100);
        $this->assertGreaterThan($this->share($before, $learning), $this->share($before, $video));

        $learningTask = \App\Models\TaskPriority::query()->where('slug', 'LEARNING')->firstOrFail();
        $videoTask = \App\Models\TaskPriority::query()->where('slug', 'REAL_TIME')->firstOrFail();

        $this->put('/tasks', [
            'tasks' => [
                $learningTask->id => [
                    'name' => 'Online learning',
                    'weight' => 40,
                    'urgency' => 5,
                    'is_critical' => '1',
                    'match_terms' => 'moodle, canvas',
                ],
                $videoTask->id => [
                    'name' => 'Video conferencing',
                    'weight' => 1,
                    'urgency' => 1,
                    'match_terms' => 'zoom, teams',
                ],
            ],
        ])->assertRedirect(route('tasks.index'));

        $learningTask->refresh();
        $this->assertSame(40, $learningTask->weight);
        $this->assertSame('LEARNING', app(TrafficDetectionService::class)->classify('moodle.university.edu:443'));
        $this->assertSame('NORMAL', app(TrafficDetectionService::class)->classify('whereby.com:443'));

        $after = $this->allocatePair($video, $learning, 100, 100);
        $this->assertGreaterThan($this->share($after, $video), $this->share($after, $learning));
    }

    public function test_router_priority_follows_importance_score(): void
    {
        $priorities = app(ImportanceEngineService::class)->queuePriorities([
            1 => 900,
            2 => 100,
        ]);

        $this->assertLessThan($priorities[2], $priorities[1]);
    }

    public function test_live_snapshot_is_saved_in_the_database(): void
    {
        \Illuminate\Support\Facades\Cache::store('database')->flush();

        app(\App\Services\AllocationSnapshotService::class)->publishOffline();

        $this->assertNotNull(\Illuminate\Support\Facades\Cache::store('database')->get('bandwidth.live_snapshot'));
    }

    /**
     * @return array{0: User, 1: User}
     */
    protected function pair(string $studentTask, string $deanTask): array
    {
        return [
            $this->makeUser('student', '10.0.0.22', $studentTask),
            $this->makeUser('dean', '10.0.0.20', $deanTask),
        ];
    }

    protected function makeUser(string $role, string $ip, string $task): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'ip_address' => $ip,
            'activity_status' => 'active',
            'current_task_type' => $task,
        ]);

        Flow::create([
            'user_id' => $user->id,
            'task_type' => $task,
            'classification' => $task,
            'priority' => app(ImportanceEngineService::class)->taskPriority($task),
            'is_active' => true,
            'source_ip' => $ip,
            'destination' => $task === 'BULK' ? 'files.example.edu:443' : 'zoom.us:443',
            'bytes' => 1000,
            'urgency_weight' => app(ImportanceEngineService::class)->urgencyFor($task),
            'importance_score' => 1,
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function allocatePair(User $first, User $second, int $firstKbps, int $secondKbps): array
    {
        return app(AllocationPreviewService::class)->build(10000, [
            $first->ip_address => true,
            $second->ip_address => true,
        ], [
            $first->id => ['download_kbps' => $firstKbps, 'upload_kbps' => 0, 'total_kbps' => $firstKbps],
            $second->id => ['download_kbps' => $secondKbps, 'upload_kbps' => 0, 'total_kbps' => $secondKbps],
        ], [
            $first->ip_address => true,
            $second->ip_address => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function allocate(User $student, User $dean, int $studentKbps, int $deanKbps): array
    {
        return $this->allocatePair($student, $dean, $studentKbps, $deanKbps);
    }

    /**
     * @param  array<string, mixed>  $allocation
     */
    protected function share(array $allocation, User $user): int
    {
        $row = collect($allocation['users'])->first(fn ($row) => $row->user->id === $user->id);

        return (int) ($row->share_kbps ?? 0);
    }
}
