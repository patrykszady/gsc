<?php

namespace App\Support\Seo\Reports;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\SimpleCache\CacheInterface;

/**
 * Bridges Laravel's cache repository to PSR-16 for the kit's `cache`
 * capability (ContentDecayReport / SchemaAuditReport's alert-dedup window —
 * see docs/REPORTS-PORTING.md, "The cache capability: no logger in the
 * kit"). Laravel's own Illuminate\Cache\Repository does not implement
 * Psr\SimpleCache\CacheInterface, so this is a thin pass-through to the
 * default store — only has()/set() are actually exercised by the two
 * reports that declare this capability, but the full interface is
 * implemented for correctness.
 */
final class LaravelSimpleCache implements CacheInterface
{
    public function __construct(private readonly CacheRepository $store) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->store->forever($key, $value);
        }

        return $this->store->put($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function clear(): bool
    {
        return $this->store->flush();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->store->has($key);
    }
}
