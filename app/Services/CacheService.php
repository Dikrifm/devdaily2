<?php

namespace App\Services;

use App\Contracts\CacheInterface;
use CodeIgniter\Cache\CacheInterface as CodeIgniterCache;
use RuntimeException;

/**
 * Implementasi Layanan Cache
 * * Implementasi konkret dari CacheInterface menggunakan Cache CodeIgniter 4.
 * Menyediakan fitur tambahan seperti tagging, statistik, dan caching berbasis konteks.
 * * @package App\Services
 */
class CacheService implements CacheInterface
{
    /**
     * Instance cache CodeIgniter
     * * @var CodeIgniterCache
     */
    private CodeIgniterCache $cache;

    /**
     * TTL default dalam detik (60 menit)
     * * @var int
     */
    private int $defaultTtl = 3600;

    /**
     * Namespace/prefix cache untuk aplikasi ini
     * * @var string
     */
    private string $namespace = 'devdaily_';

    /**
     * Statistik cache untuk pemantauan
     * * @var array
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
        'clears' => 0,
        'tag_flushes' => 0,
    ];

    /**
     * Tag saat ini untuk operasi tag
     * * @var string|null
     */
    private ?string $currentTag = null;

    /**
     * Konstruktor CacheService
     * * @param CodeIgniterCache $cache Instance cache CodeIgniter
     * @param string $namespace Namespace/prefix cache
     * @param int $defaultTtl TTL default dalam detik
     */
    public function __construct(
        CodeIgniterCache $cache,
        string $namespace = 'devdaily_',
        int $defaultTtl = 3600
    ) {
        $this->cache = $cache;
        $this->namespace = $namespace;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Mengambil item dari cache berdasarkan key
     * * @param string $key Key cache
     * @return mixed Nilai cache atau null jika tidak ditemukan
     */
    public function get(string $key)
    {
        $fullKey = $this->getKey($key);
        $value = $this->cache->get($fullKey);

        if ($value !== null) {
            $this->stats['hits']++;
            return $value;
        }

        $this->stats['misses']++;
        return null;
    }

    /**
     * Menyimpan item ke dalam cache
     * * @param string $key Key cache
     * @param mixed $value Nilai yang akan disimpan
     * @param int|null $ttl Waktu hidup dalam detik (null = selamanya)
     * @return bool Status keberhasilan
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $fullKey = $this->getKey($key);
        $ttl = $ttl ?? $this->defaultTtl;

        $success = $this->cache->save($fullKey, $value, $ttl);

        if ($success) {
            $this->stats['writes']++;

            // Simpan key dalam indeks tag jika tag diatur
            if ($this->currentTag !== null) {
                $this->addKeyToTagIndex($fullKey);
            }
        }

        return $success;
    }

    /**
     * Menghapus item dari cache
     * * @param string $key Key cache
     * @return bool Status keberhasilan
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->getKey($key);
        $success = $this->cache->delete($fullKey);

        if ($success) {
            $this->stats['deletes']++;

            // Hapus dari indeks tag jika ada
            $this->removeKeyFromTagIndices($fullKey);
        }

        return $success;
    }

    /**
     * Membersihkan seluruh cache
     * * @return bool Status keberhasilan
     */
    public function clear(): bool
    {
        // Catatan: Ini membersihkan SEMUA cache dengan handler yang sama, bukan hanya namespace kita
        $success = $this->cache->clean();

        if ($success) {
            $this->stats['clears']++;
            $this->clearTagIndices();
        }

        return $success;
    }

    /**
     * Memeriksa apakah item ada di dalam cache
     * * @param string $key Key cache
     * @return bool
     */
    public function has(string $key): bool
    {
        $fullKey = $this->getKey($key);
        $value = $this->cache->get($fullKey);

        if ($value !== null) {
            $this->stats['hits']++;
            return true;
        }

        $this->stats['misses']++;
        return false;
    }

    /**
     * Mengambil item dari cache atau menyimpannya jika tidak ditemukan
     * * @param string $key Key cache
     * @param callable $callback Fungsi yang mengembalikan nilai jika tidak ada di cache
     * @param int|null $ttl Waktu hidup dalam detik
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Menambahkan nilai item di dalam cache
     * * @param string $key Key cache
     * @param int $value Jumlah penambahan
     * @return int|bool Nilai baru atau false jika gagal
     */
    public function increment(string $key, int $value = 1)
    {
        $fullKey = $this->getKey($key);

        // Cache CI4 tidak memiliki increment, jadi kita implementasikan secara manual
        $current = $this->cache->get($fullKey);

        if ($current === null) {
            $newValue = $value;
        } else {
            if (!is_numeric($current)) {
                throw new RuntimeException('Cannot increment non-numeric cache value');
            }
            $newValue = $current + $value;
        }

        $success = $this->cache->save($fullKey, $newValue, $this->defaultTtl);

        if ($success) {
            $this->stats['writes']++;
            return $newValue;
        }

        return false;
    }

    /**
     * Mengurangi nilai item di dalam cache
     * * @param string $key Key cache
     * @param int $value Jumlah pengurangan
     * @return int|bool Nilai baru atau false jika gagal
     */
    public function decrement(string $key, int $value = 1)
    {
        return $this->increment($key, -$value);
    }

    /**
     * Mendapatkan beberapa item dari cache sekaligus
     * * @param array $keys Array dari key cache
     * @return array Array asosiatif [key => value]
     */
    public function getMultiple(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * Menyimpan beberapa item ke dalam cache
     * * @param array $items Array asosiatif [key => value]
     * @param int|null $ttl Waktu hidup dalam detik
     * @return bool Status keberhasilan
     */
    public function setMultiple(array $items, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($items as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Menghapus beberapa item dari cache
     * * @param array $keys Array dari key cache
     * @return bool Status keberhasilan
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Mendapatkan statistik cache
     * * @return array Statistik cache
     */
    public function getStats(): array
    {
        $totalOperations = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $totalOperations > 0 ? ($this->stats['hits'] / $totalOperations) * 100 : 0;

        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'writes' => $this->stats['writes'],
            'deletes' => $this->stats['deletes'],
            'clears' => $this->stats['clears'],
            'tag_flushes' => $this->stats['tag_flushes'],
            'total_operations' => $totalOperations,
            'hit_rate' => round($hitRate, 2),
            'namespace' => $this->namespace,
            'default_ttl' => $this->defaultTtl,
            'handler' => get_class($this->cache),
        ];
    }

    /**
     * Mendapatkan key cache dengan namespace/prefix
     * * @param string $key Key asli
     * @return string Key cache lengkap
     */
    public function getKey(string $key): string
    {
        return $this->namespace . $key;
    }

    /**
     * Mengatur TTL default cache
     * * @param int $ttl Waktu hidup dalam detik
     * @return self
     */
    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }

    /**
     * Mendapatkan TTL default cache
     * * @return int
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Memeriksa apakah cache tersedia/sehat
     * * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            // Tes tulis dan baca
            $testKey = $this->getKey('health_check');
            $testValue = 'ok_' . time();

            $this->cache->save($testKey, $testValue, 5);
            $retrieved = $this->cache->get($testKey);

            return $retrieved === $testValue;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Menyimpan item di cache selamanya (atau sampai dihapus secara manual)
     * * @param string $key Key cache
     * @param mixed $value Nilai yang akan disimpan
     * @return bool Status keberhasilan
     */
    public function forever(string $key, $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * Mendapatkan item beserta TTL-nya (sisa waktu)
     * * @param string $key Key cache
     * @return array|null [value, ttl] atau null jika tidak ditemukan
     */
    public function getWithTtl(string $key): ?array
    {
        // Cache CI4 tidak mengekspos TTL, jadi kita simulasikan dengan metadata
        $fullKey = $this->getKey($key);
        $value = $this->cache->get($fullKey);

        if ($value === null) {
            return null;
        }

        // Untuk file cache CI4, kita tidak bisa mendapatkan TTL
        // Di produksi dengan Redis/Memcached, Anda akan mengimplementasikannya secara berbeda
        return [
            'value' => $value,
            'ttl' => -1, // Tidak diketahui
        ];
    }

    /**
     * Membuat tag untuk operasi cache
     * * @param string $tag Nama tag
     * @return self
     */
    public function tag(string $tag): self
    {
        $this->currentTag = $tag;
        return $this;
    }

    /**
     * Bersihkan semua cache yang ditandai dengan tag yang diberikan
     * * @param array|string $tags Tag yang akan dibersihkan
     * @return bool Status keberhasilan
     */
    public function flushTag($tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $success = true;

        foreach ($tags as $tag) {
            $tagKey = $this->getTagIndexKey($tag);
            $taggedKeys = $this->cache->get($tagKey);

            if (is_array($taggedKeys)) {
                foreach ($taggedKeys as $key) {
                    if (!$this->cache->delete($key)) {
                        $success = false;
                    }
                }

                // Bersihkan indeks tag
                $this->cache->delete($tagKey);
            }

            $this->stats['tag_flushes']++;
        }

        $this->currentTag = null;
        return $success;
    }

    /**
     * Cache nilai dengan pembuatan key otomatis
     * * @param string $prefix Prefix key
     * @param array $context Konteks untuk pembuatan key
     * @param callable $callback Fungsi yang mengembalikan nilai
     * @param int|null $ttl Waktu hidup dalam detik
     * @return mixed
     */
    public function cacheWithContext(string $prefix, array $context, callable $callback, ?int $ttl = null)
    {
        $key = $this->generateContextKey($prefix, $context);
        return $this->remember($key, $callback, $ttl);
    }

    /**
     * Mendapatkan statistik hit/miss cache
     * * @return array [hits, misses, hit_rate]
     */
    public function getHitStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;

        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'hit_rate' => round($hitRate, 2),
        ];
    }

    /**
     * Reset statistik cache
     * * @return void
     */
    public function resetStats(): void
    {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => 0,
            'clears' => 0,
            'tag_flushes' => 0,
        ];
    }

    /**
     * Mendapatkan handler cache yang mendasarinya
     * * @return CodeIgniterCache
     */
    public function getHandler(): CodeIgniterCache
    {
        return $this->cache;
    }

    /**
     * Menghasilkan key cache dari konteks
     * * @param string $prefix
     * @param array $context
     * @return string
     */
    private function generateContextKey(string $prefix, array $context): string
    {
        ksort($context); // Pastikan urutan konsisten
        $hash = md5(json_encode($context));

        return $prefix . '_' . $hash;
    }

    /**
     * Mendapatkan key indeks tag
     * * @param string $tag
     * @return string
     */
    private function getTagIndexKey(string $tag): string
    {
        return $this->namespace . 'tag_' . $tag;
    }

    /**
     * Menambahkan key ke indeks tag saat ini
     * * @param string $key
     * @return void
     */
    private function addKeyToTagIndex(string $key): void
    {
        if ($this->currentTag === null) {
            return;
        }

        $tagKey = $this->getTagIndexKey($this->currentTag);
        $taggedKeys = $this->cache->get($tagKey) ?: [];

        if (!in_array($key, $taggedKeys, true)) {
            $taggedKeys[] = $key;
            $this->cache->save($tagKey, $taggedKeys, null); // Selamanya sampai flush tag
        }
    }

    /**
     * Menghapus key dari semua indeks tag
     * * @param string $key
     * @return void
     */
    private function removeKeyFromTagIndices(string $key): void
    {
        // Catatan: Ini tidak efisien untuk banyak tag
        // Untuk MVP, kita buat sederhana
        // Di produksi, Anda perlu memelihara indeks terbalik
    }

    /**
     * Membersihkan semua indeks tag
     * * @return void
     */
    private function clearTagIndices(): void
    {
        // Catatan: Ini sederhana - di produksi Anda perlu melacak semua tag
    }

    /**
     * Metode factory untuk membuat instance dengan konfigurasi
     * * @param array $config Array konfigurasi
     * @return static
     */
    public static function create(array $config = []): self
    {
        $cache = \Config\Services::cache();
        $namespace = $config['namespace'] ?? 'devdaily_';
        $defaultTtl = $config['default_ttl'] ?? 3600;

        return new self($cache, $namespace, $defaultTtl);
    }
}
