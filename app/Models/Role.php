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
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
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
            static::$cache = static::query()->orderByDesc('weight')->get()->keyBy('slug')->all();
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

    /** @var array<string, self>|null */
    protected static ?array $cache = null;
}
