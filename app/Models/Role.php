<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Role extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'weight',
        'percentage',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'percentage' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    public static function labelFor(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return 'Unknown';
        }

        return static::cached()[$slug]->name ?? ucfirst(str_replace('-', ' ', $slug));
    }

    public static function defaultSlug(): ?string
    {
        $roles = static::cached();

        foreach ($roles as $role) {
            if ($role->is_default) {
                return $role->slug;
            }
        }

        return array_key_first($roles);
    }

    public static function flushCache(): void
    {
        static::$cache = null;
    }

    /**
     * @return array<string, self>
     */
    protected static function cached(): array
    {
        if (static::$cache === null) {
            static::$cache = static::query()->orderByDesc('percentage')->get()->keyBy('slug')->all();
        }

        return static::$cache;
    }

    public static function slugFromName(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'role';
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Turn role weights into percentages that add up to 100.
     * When $requested is set, that role keeps the entered percentage and the others share what remains.
     */
    public static function syncPercentages(?self $saved = null, ?int $requested = null): void
    {
        $roles = static::query()->orderBy('id')->get();

        if ($roles->isEmpty()) {
            return;
        }

        if ($roles->count() === 1) {
            $roles->first()->update(['percentage' => 100, 'weight' => 100]);

            return;
        }

        $shares = [];

        if ($saved !== null && $requested !== null) {
            $requested = max(0, min(100, $requested));
            $others = $roles->where('id', '!=', $saved->id)->values();
            $remaining = 100 - $requested;
            $basis = (int) $others->sum(fn (self $role) => max(0, (int) $role->percentage));
            if ($basis <= 0) {
                $basis = (int) $others->sum(fn (self $role) => max(1, (int) $role->weight));
            }

            $shares[$saved->id] = $requested;
            $shares += static::splitInteger($others, $remaining, function (self $role) use ($basis) {
                $part = (int) $role->percentage > 0 ? (int) $role->percentage : max(1, (int) $role->weight);

                return $part / $basis;
            });
        } else {
            $basis = (int) $roles->sum(fn (self $role) => max(1, (int) ($role->percentage > 0 ? $role->percentage : $role->weight)));
            $shares = static::splitInteger($roles, 100, function (self $role) use ($basis) {
                $part = (int) $role->percentage > 0 ? (int) $role->percentage : max(1, (int) $role->weight);

                return $part / $basis;
            });
        }

        foreach ($roles as $role) {
            $percentage = $shares[$role->id] ?? 0;
            $role->update([
                'percentage' => $percentage,
                'weight' => $percentage,
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, self>  $roles
     * @return array<int, int>
     */
    protected static function splitInteger($roles, int $total, callable $fractionOfOne): array
    {
        $shares = [];
        $fractions = [];
        $assigned = 0;

        foreach ($roles as $role) {
            $exact = max(0, $fractionOfOne($role)) * $total;
            $floor = (int) floor($exact);
            $shares[$role->id] = $floor;
            $fractions[$role->id] = $exact - $floor;
            $assigned += $floor;
        }

        $left = $total - $assigned;
        arsort($fractions);

        foreach (array_keys($fractions) as $id) {
            if ($left <= 0) {
                break;
            }

            $shares[$id]++;
            $left--;
        }

        return $shares;
    }

    /** @var array<string, self>|null */
    protected static ?array $cache = null;
}
