<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TaskPriority extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'weight',
        'urgency',
        'is_critical',
        'match_terms',
        'sort_order',
    ];

    protected static ?Collection $cache = null;

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'urgency' => 'integer',
            'is_critical' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public static function flushCache(): void
    {
        static::$cache = null;
    }

    /**
     * @return Collection<int, self>
     */
    public static function allCached(): Collection
    {
        return static::$cache ??= static::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return array<string, int>
     */
    public static function weights(): array
    {
        return static::allCached()
            ->mapWithKeys(fn (self $task) => [$task->slug => (int) $task->weight])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public static function urgencies(): array
    {
        return static::allCached()
            ->mapWithKeys(fn (self $task) => [$task->slug => (int) $task->urgency])
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function criticalSlugs(): array
    {
        return static::allCached()
            ->filter(fn (self $task) => $task->is_critical)
            ->pluck('slug')
            ->all();
    }

    public static function labelFor(string $slug): ?string
    {
        $task = static::allCached()->firstWhere('slug', $slug);

        return $task?->name;
    }

    /**
     * Keyword lists in the order a destination is checked.
     *
     * @return array<string, list<string>>
     */
    public static function matchers(): array
    {
        $matchers = [];

        foreach (static::allCached() as $task) {
            $terms = $task->terms();
            if ($terms !== []) {
                $matchers[$task->slug] = $terms;
            }
        }

        return $matchers;
    }

    /**
     * @return list<string>
     */
    public function terms(): array
    {
        return static::splitTerms((string) $this->match_terms);
    }

    public static function normalizeTerms(?string $raw): ?string
    {
        $terms = static::splitTerms((string) $raw);

        return $terms === [] ? null : implode(', ', $terms);
    }

    /**
     * @return list<string>
     */
    public static function splitTerms(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', strtolower($raw)) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $terms, true)) {
                $terms[] = $part;
            }
        }

        return $terms;
    }
}
