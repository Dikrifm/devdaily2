<?php
namespace App\Models;

use App\Entities\BaseEntity;
use CodeIgniter\Model;
use CodeIgniter\Database\BaseResult;

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
     * Find single active record by ID
     *
     * @param int|string|null $id
     * @return object|BaseEntity|null
     */   
public function findActiveById(int|string|null $id): ?BaseEntity
{
    if ($id === null) {
        return null;
    }
    
    $result = $this->where($this->table . '.' . $this->primaryKey, $id)
                ->where($this->deletedField, null)
                ->first();

    return $result instanceof BaseEntity ? $result : null;
}


    /**
     * Soft delete with Safety Latch.
     * * @param int|string|array|null $id
     * @param bool $purge
     * @return bool
     * @throws \RuntimeException If physical delete is attempted in MVP
     */
    public function delete($id = null, bool $purge = false): bool
    {
        if ($purge) {
            throw new \RuntimeException('Physical deletes are disabled in MVP. Use archive() method.');
        }

        $result = parent::delete($id, $purge);

        // Normalisasi return type CI4 yang terkadang mengembalikan BaseResult
        if ($result instanceof BaseResult) {
            return true; 
        }

        return (bool) $result;
    }

    /**
     * Archive record (alias for soft delete)
     *
     * @param int|string $id
     */
    public function archive(int|string $id): bool
    {
        $result = $this->delete($id, false);
        return $result !== false;
    }

    /**
     * Restore archived record
     *
     * @param int|string $id
     */
    public function restore(int|string $id): bool
    {
        $data = [$this->deletedField => null];
        return $this->update($id, $data);
    }

    /**
     * Update only if values are different
     * Prevents unnecessary database writes and timestamp updates
     *
     * @param int|string|null $id
     */
    public function updateIfChanged(int|string|null $id = null, array $data = []): bool
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
            if (!is_object($current) || !property_exists($current, $key) || $current->$key != $value) {
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
     * Bulk update with manual timestamp injection.
     * Note: This bypasses Model Events (Audit Logs won't trigger automatically).
     * * @param array $ids List of Primary Keys
     * @param array $data Key-Value pair of data to update
     * @return int Number of affected rows (estimated)
     */
    public function bulkUpdate(array $ids, array $data): int
    {
        if (empty($ids) || empty($data)) {
            return 0;
        }

        $builder = $this->builder();
        $builder->whereIn($this->primaryKey, $ids);
        // Karena kita bypass Model, kita wajib isi updated_at manual
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
        $result = $this->where($this->deletedField, null)->countAllResults();
        return (int) $result;
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