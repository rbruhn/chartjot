<?php

namespace App\Support;

use App\Exceptions\DemoIsReadOnly;
use Illuminate\Support\Facades\Auth;

/**
 * The read-only demo account (#115). Everything stays visible and clickable, but nothing can be created, edited
 * or deleted while it's signed in: every write query is refused (see AppServiceProvider), and code that stores a
 * file before writing to the database calls ensureWritable() first so no file is left behind.
 */
class Demo
{
    /** Tables the framework writes on every request, for any user (sessions, cache, rate limits). */
    private const ALWAYS_WRITABLE = ['sessions', 'cache', 'cache_locks'];

    private static bool $allowingWrites = false;

    /**
     * Runs $callback with writes allowed. Only for writes the framework makes on the demo's behalf that must
     * still work, like rotating the remember token when it signs out.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allowingWrites(callable $callback): mixed
    {
        self::$allowingWrites = true;

        try {
            return $callback();
        } finally {
            self::$allowingWrites = false;
        }
    }

    public static function isSignedIn(): bool
    {
        return Auth::hasResolvedGuards() && (bool) Auth::guard('web')->user()?->isDemo();
    }

    /** @throws DemoIsReadOnly */
    public static function ensureWritable(): void
    {
        if (! self::$allowingWrites && self::isSignedIn()) {
            throw new DemoIsReadOnly;
        }
    }

    /**
     * Refuses a write to the demo account itself, whoever is signed in, unless inside allowingWrites().
     *
     * @throws DemoIsReadOnly
     */
    public static function ensureWritableUnlessAllowed(): void
    {
        if (! self::$allowingWrites) {
            throw new DemoIsReadOnly;
        }
    }

    /**
     * Refuses a write query while the demo account is signed in.
     *
     * @throws DemoIsReadOnly
     */
    public static function guardQuery(string $query): void
    {
        if (! preg_match('/^\s*(insert|update|delete|replace)\b/i', $query)) {
            return;
        }

        $table = preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|replace\s+into|update|delete\s+from)\s+[`"\[]?(\w+)/i', $query, $m)
            ? strtolower($m[1])
            : null;
        if (in_array($table, self::ALWAYS_WRITABLE, true)) {
            return;
        }

        self::ensureWritable();
    }
}
