<?php

namespace App\Contracts;

/**
 * Cache Interface
 *
 * Abstraction layer for caching operations.
 * Provides consistent caching API regardless of underlying implementation.
 *
 * @package App\Contracts
 */
interface CacheInterface
{
    /**
     * Retrieve an item from the cache by key
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key);

    /**
     * Store an item in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time to live in seconds (null = forever)
     * @return bool Success status
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Delete an item from the cache
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete(string $key): bool;

    /**
     * Clear the entire cache
     *
     * @return bool Success status
     */
    public function clear(): bool;

    /**
     * Check if an item exists in the cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Retrieve an item from cache or store it if not found
     *
     * @param string $key Cache key
     * @param callable $callback Function that returns value if not cached
     * @param int|null $ttl Time to live in seconds
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null);

    /**
     * Increment the value of an item in the cache
     *
     * @param string $key Cache key
     * @param int $value Increment amount
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1);

    /**
     * Decrement the value of an item in the cache
     *
     * @param string $key Cache key
     * @param int $value Decrement amount
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1);

    /**
     * Get multiple items from cache
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of [key => value]
     */
    public function getMultiple(array $keys): array;

    /**
     * Store multiple items in cache
     *
     * @param array $items Associative array of [key => value]
     * @param int|null $ttl Time to live in seconds
     * @return bool Success status
     */
    public function setMultiple(array $items, ?int $ttl = null): bool;

    /**
     * Delete multiple items from cache
     *
     * @param array $keys Array of cache keys
     * @return bool Success status
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getStats(): array;

    /**
     * Get cache key with namespace/prefix
     *
     * @param string $key Original key
     * @return string Full cache key
     */
    public function getKey(string $key): string;

    /**
     * Set default cache TTL
     *
     * @param int $ttl Time to live in seconds
     * @return self
     */
    public function setDefaultTtl(int $ttl): self;

    /**
     * Get default cache TTL
     *
     * @return int
     */
    public function getDefaultTtl(): int;

    /**
     * Check if cache is available/healthy
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Store an item in cache forever (or until manually deleted)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @return bool Success status
     */
    public function forever(string $key, $value): bool;

    /**
     * Get item with its TTL (remaining time)
     *
     * @param string $key Cache key
     * @return array|null [value, ttl] or null if not found
     */
    public function getWithTtl(string $key): ?array;

    /**
     * Create a tag for cache operations
     *
     * @param string $tag Tag name
     * @return self
     */
    public function tag(string $tag): self;

    /**
     * Flush all cache tagged with given tags
     *
     * @param array|string $tags Tag(s) to flush
     * @return bool Success status
     */
    public function flushTag($tags): bool;

    /**
     * Cache a value with automatic key generation
     *
     * @param string $prefix Key prefix
     * @param array $context Context for key generation
     * @param callable $callback Function that returns value
     * @param int|null $ttl Time to live in seconds
     * @return mixed
     */
    public function cacheWithContext(string $prefix, array $context, callable $callback, ?int $ttl = null);

    /**
     * Get cache hit/miss statistics
     *
     * @return array [hits, misses, hit_rate]
     */
    public function getHitStats(): array;

    /**
     * Reset cache statistics
     *
     * @return void
     */
    public function resetStats(): void;
}
