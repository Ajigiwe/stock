<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Port of unstable_cache() + updateTag()/invalidateAllData() from the Next
 * app: every read caches under a generation key, and every successful write
 * bumps the generation, invalidating the whole read set at once — exactly
 * what DATA_CACHE_TAGS + invalidateAllData() did after each action.
 *
 * File cache (cPanel-friendly): no tag support, so the generation counter
 * stands in for tags. The 30-second TTL is kept as the safety net, mirroring
 * DATA_CACHE_REVALIDATE_SECONDS = 30.
 */
class DataCache
{
    public const SECONDS = 30;

    private const VERSION_KEY = 'mrjeff:cache-generation';

    private const KEY_PREFIX = 'mrjeff:data';

    public static function remember(string $key, Closure $callback): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);

        return Cache::remember(
            self::KEY_PREFIX.':'.$version.':'.$key,
            self::SECONDS,
            $callback
        );
    }

    /** Invalidate every cached read — call after any successful write. */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }
}
