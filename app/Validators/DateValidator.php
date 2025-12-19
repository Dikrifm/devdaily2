<?php

namespace App\Validators;

use DateTime;

/**
 * Validator untuk validasi tanggal dan waktu
 */
class DateValidator
{
    /**
     * Validates a date string with given format
     *
     * @param mixed $value
     * @param string $format
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function date_format($value, string $format, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        $date = DateTime::createFromFormat($format, $value);

        if ($date && $date->format($format) === $value) {
            return true;
        }

        $error = "Date must be in format: {$format}";
        return false;
    }

    /**
     * Validates that date is after given date
     *
     * @param mixed $value
     * @param string $minDate
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function after_date($value, string $minDate, array $data, ?string &$error = null): bool
    {
        if (empty($value) || empty($minDate)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $min = new DateTime($minDate);

            if ($date > $min) {
                return true;
            }

            $error = "Date must be after {$minDate}";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates that date is before given date
     *
     * @param mixed $value
     * @param string $maxDate
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function before_date($value, string $maxDate, array $data, ?string &$error = null): bool
    {
        if (empty($value) || empty($maxDate)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $max = new DateTime($maxDate);

            if ($date < $max) {
                return true;
            }

            $error = "Date must be before {$maxDate}";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates that date is between two dates
     *
     * @param mixed $value
     * @param string $dateRange
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function between_dates($value, string $dateRange, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        $dates = array_map('trim', explode(',', $dateRange));

        if (count($dates) !== 2) {
            $error = "Invalid date range format. Use: 'start_date,end_date'";
            return false;
        }

        try {
            $date = new DateTime($value);
            $start = new DateTime($dates[0]);
            $end = new DateTime($dates[1]);

            if ($date >= $start && $date <= $end) {
                return true;
            }

            $error = "Date must be between {$dates[0]} and {$dates[1]}";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates that date is a working day (Monday-Friday)
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function working_day($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $dayOfWeek = (int) $date->format('N'); // 1 (Monday) - 7 (Sunday)

            if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                return true;
            }

            $error = "Date must be a working day (Monday-Friday)";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates future date
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function future_date($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime();

            if ($date > $now) {
                return true;
            }

            $error = "Date must be in the future";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates past date
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function past_date($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime();

            if ($date < $now) {
                return true;
            }

            $error = "Date must be in the past";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Validates date is within last N days
     *
     * @param mixed $value
     * @param string $days
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function within_last_days($value, string $days, array $data, ?string &$error = null): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime();
            $interval = new \DateInterval("P{$days}D");
            $pastDate = $now->sub($interval);

            if ($date >= $pastDate && $date <= $now) {
                return true;
            }

            $error = "Date must be within the last {$days} days";
            return false;
        } catch (\Throwable $e) {
            $error = "Invalid date format";
            return false;
        }
    }

    /**
     * Get date formats supported by application
     *
     * @return array
     */
    public static function getSupportedFormats(): array
    {
        return [
            'Y-m-d' => 'YYYY-MM-DD',
            'd/m/Y' => 'DD/MM/YYYY',
            'm/d/Y' => 'MM/DD/YYYY',
            'Y-m-d H:i:s' => 'YYYY-MM-DD HH:MM:SS',
            'd/m/Y H:i' => 'DD/MM/YYYY HH:MM',
        ];
    }
}
