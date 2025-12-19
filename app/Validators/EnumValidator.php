<?php

namespace App\Validators;

/**
 * Validator untuk validasi nilai enum
 */
class EnumValidator
{
    /**
     * Validates that a value is a valid enum case
     *
     * @param mixed $value
     * @param string $enumClass
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function enum($value, string $enumClass, array $data, ?string &$error = null): bool
    {
        // Cek jika class enum ada
        if (!class_exists($enumClass)) {
            $error = "Enum class '{$enumClass}' does not exist";
            return false;
        }

        // Cek jika class adalah enum
        if (!enum_exists($enumClass)) {
            $error = "Class '{$enumClass}' is not an enum";
            return false;
        }

        // Handle null values - biarkan rule required menangani
        if ($value === null || $value === '') {
            return true;
        }

        try {
            // Coba untuk mendapatkan enum case
            if (method_exists($enumClass, 'tryFrom')) {
                $enumCase = $enumClass::tryFrom($value);
                return $enumCase !== null;
            }

            // Untuk enum non-backed, cek nama case
            $reflection = new \ReflectionEnum($enumClass);
            $cases = $reflection->getConstants();

            return in_array($value, $cases, true);

        } catch (\Throwable $e) {
            $error = "Invalid enum value for '{$enumClass}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Validates that a value is a valid enum array
     *
     * @param mixed $value
     * @param string $enumClass
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function enum_array($value, string $enumClass, array $data, ?string &$error = null): bool
    {
        if (!is_array($value)) {
            $error = "Value must be an array";
            return false;
        }

        foreach ($value as $item) {
            if (!$this->enum($item, $enumClass, $data, $error)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if value is not one of the excluded enum cases
     *
     * @param mixed $value
     * @param string $excludedCases
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function not_in_enum($value, string $excludedCases, array $data, ?string &$error = null): bool
    {
        $excluded = array_map('trim', explode(',', $excludedCases));

        if (in_array($value, $excluded, true)) {
            $error = "Value '{$value}' is not allowed";
            return false;
        }

        return true;
    }

    /**
     * Check if value is one of the allowed enum cases
     *
     * @param mixed $value
     * @param string $allowedCases
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function in_enum($value, string $allowedCases, array $data, ?string &$error = null): bool
    {
        $allowed = array_map('trim', explode(',', $allowedCases));

        if (!in_array($value, $allowed, true)) {
            $error = "Value '{$value}' is not allowed. Allowed values: " . implode(', ', $allowed);
            return false;
        }

        return true;
    }

    /**
     * Get all cases from an enum class
     *
     * @param string $enumClass
     * @return array
     */
    public static function getEnumCases(string $enumClass): array
    {
        if (!enum_exists($enumClass)) {
            return [];
        }

        try {
            if (method_exists($enumClass, 'cases')) {
                return array_map(fn ($case) => $case->value, $enumClass::cases());
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * Create rule message for enum validation
     *
     * @param string $enumClass
     * @return string
     */
    public static function getEnumMessage(string $enumClass): string
    {
        $cases = self::getEnumCases($enumClass);

        if (empty($cases)) {
            return "Please select a valid value";
        }

        return "Please select one of: " . implode(', ', $cases);
    }
}
