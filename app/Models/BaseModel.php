<?php

namespace App\Models;

use App\Entities\BaseEntity;
use CodeIgniter\Model;

/**
 * Base Model Abstract Class
 *
 * Foundation for all models in the system with MVP approach.
 * Provides common configuration and simple caching mechanism.
 *
 * @package App\Models
 */
abstract class BaseModel extends Model
{
    /**
     * Default cache TTL in seconds (60 minutes)
     *
     * @var int
     */
    protected const DEFAULT_CACHE_TTL = 3600;

    /**
     * Whether to use soft deletes
     *
     * @var bool
     */
    protected $useSoftDeletes = true;

    /**
     * Whether to use timestamps
     *
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * Field for created_at timestamp
     *
     * @var string
     */
    protected $createdField = 'created_at';

    /**
     * Field for updated_at timestamp
     *
     * @var string
     */
    protected $updatedField = 'updated_at';

    /**
     * Field for deleted_at timestamp
     *
     * @var string
     */
    protected $deletedField = 'deleted_at';

    /**
     * Validation rules for insert/update
     * To be defined in child models
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * Validation messages
     *
     * @var array
     */
    protected $validationMessages = [];

    /**
     * Whether to skip validation
     *
     * @var bool
     */
    protected $skipValidation = false;

    /**
     * Get cache service instance
     *
     * @return \CodeIgniter\Cache\CacheInterface
     */
    protected function getCache()
    {
        return \Config\Services::cache();
    }

    /**
     * Execute callback with cache support
     * Simple MVP caching - no tagging, no complex invalidation
     *
     * @param string $cacheKey Unique cache key
     * @param callable $callback Function that returns data
     * @param int|null $ttl Cache TTL in seconds (null = use default)
     * @return mixed
     */
    protected function cached(string $cacheKey, callable $callback, ?int $ttl = null)
    {
        $cache = $this->getCache();
        $ttl = $ttl ?? static::DEFAULT_CACHE_TTL;

        // Try to get from cache first
        $data = $cache->get($cacheKey);

        if ($data !== null) {
            return $data;
        }

        // Execute callback if not in cache
        $data = $callback();

        // Save to cache
        $cache->save($cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * Clear cache by key
     */
    protected function clearCache(string $cacheKey): bool
    {
        return $this->getCache()->delete($cacheKey);
    }

    /**
     * Generate cache key with prefix
     */
    protected function cacheKey(string $suffix): string
    {
        return $this->table . '_' . $suffix;
    }

    /**
     * Kita override method hantu ini agar fleksibel.
     * Menerima input apapun (int limit, bool stats, atau array columns)
     * agar anak-anaknya tidak error.
     */


    /**
     * Find single active record by ID
     *
     * @param int|string|null $id
     * @return object|BaseEntity|null
     */
    public function findActiveById($id)
    {
        if ($id === null) {
            return null;
        }

        return $this->where($this->table . '.' . $this->primaryKey, $id)
                    ->where($this->deletedField)
                    ->first();
    }

    /**
     * Soft delete with validation
     *
     * @param int|string|null $id
     * @return bool|BaseEntity
     */
    public function delete($id = null, bool $purge = false)
    {
        // For MVP, we only allow soft deletes
        if ($purge) {
            throw new \RuntimeException('Physical deletes are disabled in MVP. Use archive() method.');
        }

        return parent::delete($id);
    }

    /**
     * Archive record (alias for soft delete)
     *
     * @param int|string $id
     */
    public function archive($id): bool
    {
        $result = $this->delete($id, false);
        return $result !== false;
    }

    /**
     * Restore archived record
     *
     * @param int|string $id
     */
    public function restore($id): bool
    {
        $data = [$this->deletedField => null];
        return $this->update($id, $data);
    }

    /**
     * Get paginated results with simple pagination
     */
    public function paginateSimple(int $perPage = 20, ?int $page = null): array
    {
        $page = $page ?? 1;
        $offset = ($page - 1) * $perPage;

        $total = $this->countAllResults();
        $results = $this->limit($perPage, $offset)->findAll();

        return [
            'data' => $results,
            'pager' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total,
            ]
        ];
    }

    /**
     * Update only if values are different
     * Prevents unnecessary database writes and timestamp updates
     *
     * @param int|string|null $id
     */
    public function updateIfChanged($id = null, array $data = []): bool
    {
        if ($id === null) {
            return false;
        }

        // Get current record
        $current = $this->find($id);
        if (!$current) {
            return false;
        }

        // Filter out unchanged values
        $changedData = [];
        foreach ($data as $key => $value) {
            if (!property_exists($current, $key) || $current->$key != $value) {
                $changedData[$key] = $value;
            }
        }

        // If nothing changed, return true (no update needed)
        if ($changedData === []) {
            return true;
        }

        return $this->update($id, $changedData);
    }

    /**
     * Bulk update with simple validation
     *
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $ids, array $data): int
    {
        if ($ids === [] || $data === []) {
            return 0;
        }

        $builder = $this->builder();
        $builder->whereIn($this->primaryKey, $ids);

        // Add updated_at timestamp
        if ($this->useTimestamps && !isset($data[$this->updatedField])) {
            $data[$this->updatedField] = date('Y-m-d H:i:s');
        }

        return $builder->update($data) ? count($ids) : 0;
    }



    /**
     * Get count of active records
     */
    public function countActive(): int
    {
        return $this->where($this->deletedField)->countAllResults();
    }

    /**
     * Validate data against model rules
     * Simple wrapper for CI4 validation
     *
     * @return array [bool $valid, array $errors]
     */
    public function validateData(array $data): array
    {
        if ($this->skipValidation || $this->validationRules === []) {
            return [true, []];
        }

        $validation = \Config\Services::validation();
        $validation->setRules($this->validationRules, $this->validationMessages);

        $isValid = $validation->run($data);
        $errors = $isValid ? [] : $validation->getErrors();

        return [$isValid, $errors];
    }
}
