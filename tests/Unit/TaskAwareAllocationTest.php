<?php

namespace Tests\Unit;

use App\Models\Flow;
use App\Models\Role;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\ImportanceEngineService;
use App\Services\TrafficDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAwareAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_importance_uses_role_task_and_urgency(): void
    {
        Role::create([
            'slug' => 'guest',
            'name' => 'Guest',
            'weight' => 0,
            'percentage' => 0,
            'is_default' => false,
        ]);

        $engine = app(ImportanceEngineService::class);

        $realtime = $engine->calculate('student', 'REAL_TIME', $engine->urgencyFor('REAL_TIME'), true);
        $bulk = $engine->calculate('student', 'BULK', $engine->urgencyFor('BULK'), true);

        $this->assertGreaterThan($bulk, $realtime);
        $this->assertSame(5, $engine->urgencyFor('REAL_TIME'));
        $this->assertSame(1, $engine->urgencyFor('BULK'));
        $this->assertSame(0, $engine->calculate('guest', 'REAL_TIME', 5, true));
    }

    public function test_congestion_requires_competition_and_high_demand(): void
    {
        $engine = app(ImportanceEngineService::class);

        $this->assertFalse($engine->assessCongestion(10000, 10000, 1)['congested']);
        $this->assertTrue($engine->assessCongestion(10000, 8000, 2)['congested']);
        $this->assertFalse($engine->assessCongestion(10000, 1000, 2)['congested']);
    }

    public function test_congested_critical_task_outranks_higher_role_bulk_download(): void
    {
        [$student, $dean] = $this->pair('REAL_TIME', 'BULK');

        $calm = $this->allocate($student, $dean, 100, 100);
        $busy = $this->allocate($student, $dean, 4000, 4000);

        $this->assertFalse($calm['congested']);
        $this->assertTrue($busy['congested']);
        $this->assertGreaterThan($this->share($busy, $dean), $this->share($busy, $student));
        $this->assertLessThanOrEqual(1000, $this->share($busy, $dean));
        $this->assertGreaterThanOrEqual(512, $this->share($busy, $student));

        $calmRatio = $this->share($calm, $student) / max(1, $this->share($calm, $dean));
        $busyRatio = $this->share($busy, $student) / max(1, $this->share($busy, $dean));
        $this->assertGreaterThan($calmRatio, $busyRatio);
        $this->assertSame(10000, $this->share($busy, $student) + $this->share($busy, $dean));
    }

    public function test_active_downloads_keep_enough_queue_to_reach_their_share(): void
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

        $this->assertGreaterThan($this->share($allocation, $lecturer), $this->share($allocation, $dean));
        $this->assertGreaterThan(1500, $this->share($allocation, $dean));
        $this->assertGreaterThan(16202, $this->share($allocation, $lecturer));
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
        $this->get('/')->assertOk()->assertSee('Task-Aware Bandwidth');
        $this->get('/roles')->assertOk()->assertSee('institutional importance');
    }

    public function test_router_priority_follows_importance_score(): void
    {
        $priorities = app(ImportanceEngineService::class)->queuePriorities([
            1 => 900,
            2 => 100,
        ]);

        $this->assertLessThan($priorities[2], $priorities[1]);
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
    protected function allocate(User $student, User $dean, int $studentKbps, int $deanKbps): array
    {
        return app(AllocationPreviewService::class)->build(10000, [
            $student->ip_address => true,
            $dean->ip_address => true,
        ], [
            $student->id => ['download_kbps' => $studentKbps, 'upload_kbps' => 0, 'total_kbps' => $studentKbps],
            $dean->id => ['download_kbps' => $deanKbps, 'upload_kbps' => 0, 'total_kbps' => $deanKbps],
        ], [
            $student->ip_address => true,
            $dean->ip_address => true,
        ]);
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
