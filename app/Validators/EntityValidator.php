<?php

namespace App\Validators;

use CodeIgniter\Database\BaseConnection;

/**
 * Validator untuk validasi keberadaan entity di database
 */
class EntityValidator
{
    protected $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Check if entity exists in database
     *
     * @param mixed $value
     * @param string $tableField
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function exists($value, string $tableField, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        // Format: table.field atau table.field,where_column,where_value
        $parts = explode(',', $tableField);
        $tableFieldPart = array_shift($parts);

        list($table, $field) = explode('.', $tableFieldPart);

        $builder = $this->db->table($table)
            ->where($field, $value)
            ->where('deleted_at IS NULL');

        // Tambahkan kondisi tambahan jika ada
        if (count($parts) >= 2) {
            $builder->where($parts[0], $parts[1]);
        }

        $exists = $builder->countAllResults() > 0;

        if (!$exists) {
            $error = "The selected {$field} does not exist";
            return false;
        }

        return true;
    }

    /**
     * Check if entity does NOT exist in database
     *
     * @param mixed $value
     * @param string $tableField
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function not_exists($value, string $tableField, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        list($table, $field) = explode('.', $tableField);

        $exists = $this->db->table($table)
            ->where($field, $value)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;

        if ($exists) {
            $error = "The selected {$field} already exists";
            return false;
        }

        return true;
    }

    /**
     * Check if entity exists and is active
     *
     * @param mixed $value
     * @param string $tableField
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function exists_and_active($value, string $tableField, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        list($table, $field) = explode('.', $tableField);

        $exists = $this->db->table($table)
            ->where($field, $value)
            ->where('active', 1)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;

        if (!$exists) {
            $error = "The selected {$field} does not exist or is not active";
            return false;
        }

        return true;
    }

    /**
     * Check if entity belongs to another entity
     *
     * @param mixed $value
     * @param string $params
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function belongs_to($value, string $params, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        // Format: table.field,parent_table,parent_field,parent_value
        $parts = explode(',', $params);

        if (count($parts) < 4) {
            $error = "Invalid belongs_to parameters";
            return false;
        }

        list($tableField, $parentTable, $parentField, $parentValue) = $parts;
        list($table, $field) = explode('.', $tableField);

        // Cek jika parent entity ada
        $parentExists = $this->db->table($parentTable)
            ->where($parentField, $parentValue)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;

        if (!$parentExists) {
            $error = "Parent entity does not exist";
            return false;
        }

        // Cek jika child entity ada dan terhubung ke parent
        $childExists = $this->db->table($table)
            ->where($field, $value)
            ->where($parentField, $parentValue)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;

        if (!$childExists) {
            $error = "Entity does not belong to the specified parent";
            return false;
        }

        return true;
    }

    /**
     * Check if entity has no dependent records
     *
     * @param mixed $value
     * @param string $params
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function no_dependencies($value, string $params, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        // Format: table.field,dependent_table,dependent_field
        $parts = explode(',', $params);

        if (count($parts) < 3) {
            $error = "Invalid no_dependencies parameters";
            return false;
        }

        list($tableField, $dependentTable, $dependentField) = $parts;
        list($table, $field) = explode('.', $tableField);

        // Cek jika ada dependent records
        $hasDependencies = $this->db->table($dependentTable)
            ->where($dependentField, $value)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;

        if ($hasDependencies) {
            $error = "Cannot delete entity because it has dependent records";
            return false;
        }

        return true;
    }

    /**
     * Check if entity is unique within scope
     *
     * @param mixed $value
     * @param string $params
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function unique_in_scope($value, string $params, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        // Format: table.field,scope_field,scope_value[,exclude_id]
        $parts = explode(',', $params);

        if (count($parts) < 3) {
            $error = "Invalid unique_in_scope parameters";
            return false;
        }

        list($tableField, $scopeField, $scopeValue) = $parts;
        $excludeId = $parts[3] ?? null;

        list($table, $field) = explode('.', $tableField);

        $builder = $this->db->table($table)
            ->where($field, $value)
            ->where($scopeField, $scopeValue)
            ->where('deleted_at IS NULL');

        // Exclude current record jika ada
        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        $exists = $builder->countAllResults() > 0;

        if ($exists) {
            $error = "The {$field} must be unique within the specified scope";
            return false;
        }

        return true;
    }
}
