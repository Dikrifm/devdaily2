<?php

namespace App\Entities;

use App\Entities\Traits\SoftDeletableTrait;
use App\Entities\Traits\TimestampableTrait;
use DateTimeImmutable;

/**
 * Base Entity Abstract Class
 *
 * Foundation for all domain entities in the system.
 * Provides common properties (id) and traits (timestampable, soft deletable).
 * Enhanced with state validation and change tracking for immutable patterns.
 *
 * @package App\Entities
 */
abstract class BaseEntity
{
    use TimestampableTrait;
    use SoftDeletableTrait;

    /**
     * Primary key identifier
     */
    protected ?int $id = null;

    /**
     * Track changes for auditing purposes
     *
     * @var array<string, array{old: mixed, new: mixed}>|null
     */
    private ?array $changes = null;

    /**
     * Get the entity's primary key
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the entity's primary key
     * Should only be called by the repository/hydrator
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Check if entity is new (not persisted)
     */
    public function isNew(): bool
    {
        return $this->id === null;
    }

    /**
     * Check if entity exists in database
     */
    public function exists(): bool
    {
        return $this->id !== null;
    }

    /**
     * Initialize entity with creation timestamps
     * Should be called when creating new entity
     */
    public function initialize(): void
    {
        if ($this->isNew()) {
            $this->initializeTimestamps();
        }
    }

    /**
     * Update entity's updated_at timestamp
     */
    public function markAsUpdated(): void
    {
        $this->touch();
    }

    /**
     * Kita timpa method restore() dari Trait/Parent
     * agar return type-nya kompatibel dengan Child class (self/object).
     */
    public function restore(): self
    {
        // Jika Anda menggunakan soft deletes, logika restore biasanya:
        $this->deleted_at = null;

        // PENTING: Return $this agar sesuai dengan anak-anaknya
        return $this;
    }

    /**
     * Track a property change for auditing
     */
    protected function trackChange(string $property, mixed $oldValue, mixed $newValue): void
    {
        if ($oldValue === $newValue) {
            return;
        }

        if ($this->changes === null) {
            $this->changes = [];
        }

        $this->changes[$property] = [
            'old' => $oldValue,
            'new' => $newValue,
            'changed_at' => new DateTimeImmutable()
        ];
    }

    /**
     * Get all tracked changes since last reset
     *
     * @return array<string, array{old: mixed, new: mixed, changed_at: DateTimeImmutable}>
     */
    public function getChanges(): array
    {
        return $this->changes ?? [];
    }

    /**
     * Check if entity has any tracked changes
     */
    public function hasChanges(): bool
    {
        return $this->changes !== null && $this->changes !== [];
    }

    /**
     * Clear tracked changes
     * Typically called after persisting to database
     */
    public function clearChanges(): void
    {
        $this->changes = null;
    }

    /**
     * Get a summary of changes for audit logging
     *
     * @return array{count: int, properties: array<string>, summary: string}
     */
    public function getChangesSummary(): array
    {
        $changes = $this->getChanges();

        if ($changes === []) {
            return [
                'count' => 0,
                'properties' => [],
                'summary' => 'No changes'
            ];
        }

        $properties = array_keys($changes);
        $summaryParts = [];

        foreach ($changes as $property => $change) {
            $old = $this->formatChangeValue($change['old']);
            $new = $this->formatChangeValue($change['new']);
            $summaryParts[] = sprintf('%s: %s → %s', $property, $old, $new);
        }

        return [
            'count' => count($changes),
            'properties' => $properties,
            'summary' => implode('; ', $summaryParts)
        ];
    }

    /**
     * Format a value for change summary
     */
    private function formatChangeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_object($value)) {
            if ($value instanceof DateTimeImmutable) {
                return $value->format('Y-m-d H:i:s');
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return get_class($value);
        }

        if (is_array($value)) {
            return '[' . count($value) . ' items]';
        }

        $stringValue = (string) $value;

        // Truncate long values
        if (strlen($stringValue) > 50) {
            return substr($stringValue, 0, 47) . '...';
        }

        return $stringValue;
    }

    /**
     * Validate that a state transition is allowed
     * Generic method that can be overridden by child entities
     *
     * @param array $allowedTransitions Map of current state to array of allowed next states
     */
    protected function validateStateTransition(
        string|object $currentState,
        string|object $newState,
        array $allowedTransitions
    ): bool {
        $current = is_object($currentState) ? $currentState->value : $currentState;
        $new = is_object($newState) ? $newState->value : $newState;

        if ($current === $new) {
            return true; // No change is always allowed
        }

        return in_array($new, $allowedTransitions[$current] ?? [], true);
    }

    /**
     * Prepare entity for database insertion/update
     * This method can be overridden by child entities
     * to add custom pre-save logic
     *
     * @param bool $isUpdate Whether this is an update operation
     */
    public function prepareForSave(bool $isUpdate = false): void
    {
        if ($isUpdate) {
            $this->markAsUpdated();
        } else {
            $this->initialize();
        }
    }

    /**
     * Validate entity state before persistence
     * Override in child entities to add custom validation
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $errors = [];

        // Basic validation that applies to all entities
        if ($this->isDeleted() && !$this->getDeletedAt() instanceof \DateTimeImmutable) {
            $errors[] = 'Deleted entity must have deletion timestamp';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors
        ];
    }

    /**
     * Check if entity can be archived/soft-deleted
     * Override in child entities to add business rules
     */
    public function canBeArchived(): bool
    {
        return !$this->isDeleted();
    }

    /**
     * Check if entity can be restored from archive
     */
    public function canBeRestored(): bool
    {
        return $this->isDeleted();
    }

    /**
     * Get entity type for auditing purposes
     */
    public function getEntityType(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }

    /**
     * Get entity identifier for auditing
     * Combines type and ID for unique identification
     */
    public function getAuditIdentifier(): string
    {
        return sprintf('%s#%s', $this->getEntityType(), $this->id ?? 'new');
    }

    /**
     * Create a snapshot of current state for auditing
     */
    public function createSnapshot(): array
    {
        return [
            'id' => $this->getId(),
            'type' => $this->getEntityType(),
            'data' => $this->toArray(),
            'timestamp' => new DateTimeImmutable(),
            'is_deleted' => $this->isDeleted(),
            'is_new' => $this->isNew()
        ];
    }

    /**
     * Compare with another entity of same type
     *
     * @return array{equal: bool, differences: array<string, array{self: mixed, other: mixed}>}
     */
    public function compareWith(BaseEntity $other): array
    {
        if (get_class($this) !== get_class($other)) {
            throw new \InvalidArgumentException('Cannot compare entities of different types');
        }

        $thisData = $this->toArray();
        $otherData = $other->toArray();

        $differences = [];

        foreach ($thisData as $key => $value) {
            if (!array_key_exists($key, $otherData) || $otherData[$key] != $value) {
                $differences[$key] = [
                    'self' => $value,
                    'other' => $otherData[$key] ?? null
                ];
            }
        }

        // Check for keys in other that aren't in this
        foreach ($otherData as $key => $value) {
            if (!array_key_exists($key, $thisData)) {
                $differences[$key] = [
                    'self' => null,
                    'other' => $value
                ];
            }
        }

        return [
            'equal' => $differences === [],
            'differences' => $differences
        ];
    }

    /**
     * Convert entity to array representation
     * Should be implemented by child entities
     */
    abstract public function toArray(): array;

    /**
     * Create entity from array data
     * Should be implemented as static factory in child entities
     */
    abstract public static function fromArray(array $data): static;
}
