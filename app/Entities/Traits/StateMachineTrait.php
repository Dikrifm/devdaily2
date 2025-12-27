<?php

namespace App\Entities\Traits;

use App\Exceptions\DomainException;
use App\Exceptions\InvalidArgumentException;
use DateTimeImmutable;
use Exception;
use ReflectionClass;
use RuntimeException;

/**
 * State Machine Trait for consistent state transitions across entities
 * 
 * Provides:
 * 1. Centralized state transition rules
 * 2. Automatic validation of transitions
 * 3. Audit trail for state changes
 * 4. Hooks for before/after transitions
 * 5. Support for both enum and string states
 */
trait StateMachineTrait
{
    // Configuration properties (should be overridden in using class)
    protected static array $stateConfig = [];
    protected static string $stateProperty = 'state';
    protected static string $stateHistoryProperty = 'state_history';
    protected static bool $enableStateHistory = true;
    protected static int $maxStateHistory = 50;
    
    // Runtime properties
    private array $pendingTransitions = [];
    private array $transitionErrors = [];
    private bool $isTransitioning = false;
    private ?array $lastTransition = null;
    
    /**
     * Transition to a new state
     *
     * @param mixed $newState Target state (enum case or string)
     * @param string|null $reason Reason for the transition
     * @param array $context Additional context data
     * @param int|null $actorId ID of user/admin performing transition
     * @return bool Success status
     * @throws DomainException If transition is not allowed
     */
    public function transitionTo(
        $newState,
        ?string $reason = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        // Prevent nested transitions
        if ($this->isTransitioning) {
            throw new RuntimeException('Nested state transitions are not allowed');
        }
        
        $this->isTransitioning = true;
        $this->transitionErrors = [];
        
        try {
            $currentState = $this->getCurrentState();
            $newState = $this->normalizeState($newState);
            
            // Validate transition
            if (!$this->validateTransition($currentState, $newState, $context)) {
                $errorMessage = $this->formatTransitionError($currentState, $newState);
                throw new DomainException($errorMessage, 409, [
                    'current_state' => $currentState,
                    'target_state' => $newState,
                    'errors' => $this->transitionErrors,
                ]);
            }
            
            // Execute before transition hooks
            if (!$this->executeBeforeTransition($currentState, $newState, $context)) {
                throw new DomainException(
                    'Transition blocked by before transition hook',
                    409,
                    ['hook_result' => false]
                );
            }
            
            // Store old state for history and audit
            $oldState = $currentState;
            
            // Perform the actual state change
            $this->setStateProperty($newState);
            
            // Update timestamp if applicable
            $this->updateStateTimestamp($newState);
            
            // Record transition
            $transitionRecord = $this->createTransitionRecord(
                $oldState,
                $newState,
                $reason,
                $context,
                $actorId
            );
            
            $this->lastTransition = $transitionRecord;
            
            // Store in history if enabled
            if (static::$enableStateHistory) {
                $this->addToStateHistory($transitionRecord);
            }
            
            // Execute after transition hooks
            $this->executeAfterTransition($oldState, $newState, $context);
            
            // Clear pending transitions
            $this->pendingTransitions = [];
            
            return true;
            
        } catch (Exception $e) {
            // Re-throw domain exceptions, wrap others
            if ($e instanceof DomainException) {
                throw $e;
            }
            
            throw new RuntimeException(
                'State transition failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->isTransitioning = false;
        }
    }
    
    /**
     * Get current state
     */
    public function getCurrentState()
    {
        $property = static::$stateProperty;
        
        if (!property_exists($this, $property)) {
            throw new RuntimeException(
                sprintf('State property "%s" does not exist in %s', $property, get_class($this))
            );
        }
        
        return $this->$property;
    }
    
    /**
     * Get available next states
     *
     * @param array $context Additional context for validation
     * @return array List of allowed next states
     */
    public function getNextStates(array $context = []): array
    {
        $currentState = $this->getCurrentState();
        $config = $this->getStateConfig();
        
        if (!isset($config['transitions'])) {
            return [];
        }
        
        $allowed = [];
        
        foreach ($config['transitions'] as $transition) {
            $fromStates = (array) $transition['from'];
            
            if (in_array($currentState, $fromStates, true)) {
                // Check guard conditions if any
                if (isset($transition['guard'])) {
                    if (!$this->evaluateGuard($transition['guard'], $context)) {
                        continue;
                    }
                }
                
                $allowed[] = $transition['to'];
            }
        }
        
        return array_unique($allowed);
    }
    
    /**
     * Check if transition is allowed
     *
     * @param mixed $targetState Target state to check
     * @param array $context Additional context
     * @return bool True if transition is allowed
     */
    public function canTransitionTo($targetState, array $context = []): bool
    {
        try {
            $currentState = $this->getCurrentState();
            $targetState = $this->normalizeState($targetState);
            
            return $this->validateTransition($currentState, $targetState, $context);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get transition history
     *
     * @param int $limit Maximum number of records to return
     * @return array State transition history
     */
    public function getStateHistory(int $limit = null): array
    {
        if (!static::$enableStateHistory || !property_exists($this, static::$stateHistoryProperty)) {
            return [];
        }
        
        $property = static::$stateHistoryProperty;
        $history = $this->$property ?? [];
        
        if ($limit !== null) {
            $history = array_slice($history, -$limit);
        }
        
        return $history;
    }
    
    /**
     * Get last transition
     *
     * @return array|null Last transition record or null
     */
    public function getLastTransition(): ?array
    {
        return $this->lastTransition;
    }
    
    /**
     * Get state configuration
     */
    protected function getStateConfig(): array
    {
        if (empty(static::$stateConfig)) {
            throw new RuntimeException(
                sprintf('State configuration not defined in %s', get_class($this))
            );
        }
        
        return static::$stateConfig;
    }
    
    /**
     * Validate a transition
     */
    private function validateTransition($currentState, $targetState, array $context): bool
    {
        // Same state transition
        if ($currentState === $targetState) {
            $this->transitionErrors[] = 'Cannot transition to the same state';
            return false;
        }
        
        $config = $this->getStateConfig();
        
        // Check if target state is valid
        if (!in_array($targetState, $config['states'] ?? [], true)) {
            $this->transitionErrors[] = sprintf('Target state "%s" is not valid', $targetState);
            return false;
        }
        
        // Find applicable transition rules
        $applicableTransitions = $this->findApplicableTransitions($currentState, $targetState);
        
        if (empty($applicableTransitions)) {
            $this->transitionErrors[] = sprintf(
                'No transition rule found from "%s" to "%s"',
                $currentState,
                $targetState
            );
            return false;
        }
        
        // Check each transition rule
        foreach ($applicableTransitions as $transition) {
            // Check guard conditions
            if (isset($transition['guard'])) {
                if (!$this->evaluateGuard($transition['guard'], $context)) {
                    continue;
                }
            }
            
            // Check custom validator if any
            if (isset($transition['validate'])) {
                $validationResult = $this->executeValidator($transition['validate'], $context);
                if ($validationResult !== true) {
                    $this->transitionErrors[] = $validationResult;
                    continue;
                }
            }
            
            // This transition is valid
            return true;
        }
        
        // No valid transition found
        if (empty($this->transitionErrors)) {
            $this->transitionErrors[] = 'Transition validation failed';
        }
        
        return false;
    }
    
    /**
     * Find applicable transition rules
     */
    private function findApplicableTransitions($currentState, $targetState): array
    {
        $config = $this->getStateConfig();
        $applicable = [];
        
        foreach ($config['transitions'] ?? [] as $transition) {
            $fromStates = (array) $transition['from'];
            
            if (in_array($currentState, $fromStates, true) && $transition['to'] === $targetState) {
                $applicable[] = $transition;
            }
        }
        
        return $applicable;
    }
    
    /**
     * Evaluate guard condition
     */
    private function evaluateGuard($guard, array $context): bool
    {
        if (is_callable($guard)) {
            return $guard($this, $context);
        }
        
        if (is_string($guard) && method_exists($this, $guard)) {
            return $this->$guard($context);
        }
        
        // Assume guard is a boolean expression
        return (bool) $guard;
    }
    
    /**
     * Execute custom validator
     */
    private function executeValidator($validator, array $context)
    {
        if (is_callable($validator)) {
            return $validator($this, $context);
        }
        
        if (is_string($validator) && method_exists($this, $validator)) {
            return $this->$validator($context);
        }
        
        return true; // No validator, assume valid
    }
    
    /**
     * Execute before transition hooks
     */
    private function executeBeforeTransition($oldState, $newState, array $context): bool
    {
        $config = $this->getStateConfig();
        
        // Global before hook
        if (isset($config['hooks']['before']) && is_callable($config['hooks']['before'])) {
            $result = $config['hooks']['before']($this, $oldState, $newState, $context);
            if ($result === false) {
                return false;
            }
        }
        
        // State-specific before hook
        $hookName = 'before' . $this->formatStateName($newState);
        if (method_exists($this, $hookName)) {
            $result = $this->$hookName($oldState, $context);
            if ($result === false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Execute after transition hooks
     */
    private function executeAfterTransition($oldState, $newState, array $context): void
    {
        $config = $this->getStateConfig();
        
        // Global after hook
        if (isset($config['hooks']['after']) && is_callable($config['hooks']['after'])) {
            $config['hooks']['after']($this, $oldState, $newState, $context);
        }
        
        // State-specific after hook
        $hookName = 'after' . $this->formatStateName($newState);
        if (method_exists($this, $hookName)) {
            $this->$hookName($oldState, $context);
        }
    }
    
    /**
     * Set state property value
     */
    private function setStateProperty($state): void
    {
        $property = static::$stateProperty;
        $this->$property = $state;
    }
    
    /**
     * Update timestamp based on state
     */
    private function updateStateTimestamp($newState): void
    {
        $config = $this->getStateConfig();
        
        if (isset($config['timestamps'][$newState])) {
            $timestampProperty = $config['timestamps'][$newState];
            
            if (property_exists($this, $timestampProperty)) {
                $this->$timestampProperty = new DateTimeImmutable();
            }
        }
    }
    
    /**
     * Create transition record
     */
    private function createTransitionRecord(
        $oldState,
        $newState,
        ?string $reason,
        array $context,
        ?int $actorId
    ): array {
        return [
            'from' => $oldState,
            'to' => $newState,
            'timestamp' => new DateTimeImmutable(),
            'reason' => $reason,
            'context' => $context,
            'actor_id' => $actorId,
            'entity_type' => get_class($this),
            'entity_id' => $this->getId(),
        ];
    }
    
    /**
     * Add transition to history
     */
    private function addToStateHistory(array $transition): void
    {
        if (!property_exists($this, static::$stateHistoryProperty)) {
            // Create property if it doesn't exist
            $this->{static::$stateHistoryProperty} = [];
        }
        
        $property = static::$stateHistoryProperty;
        $history = $this->$property;
        
        // Add new transition
        $history[] = $transition;
        
        // Limit history size
        if (count($history) > static::$maxStateHistory) {
            $history = array_slice($history, -static::$maxStateHistory);
        }
        
        $this->$property = $history;
    }
    
    /**
     * Normalize state value
     */
    private function normalizeState($state)
    {
        if (is_object($state) && method_exists($state, 'value')) {
            // Enum case
            return $state->value();
        }
        
        if (is_object($state) && method_exists($state, '__toString')) {
            // Stringable object
            return (string) $state;
        }
        
        return $state;
    }
    
    /**
     * Format state name for method names
     */
    private function formatStateName($state): string
    {
        $stateString = $this->normalizeState($state);
        return str_replace('_', '', ucwords($stateString, '_'));
    }
    
    /**
     * Format transition error message
     */
    private function formatTransitionError($currentState, $targetState): string
    {
        $current = $this->normalizeState($currentState);
        $target = $this->normalizeState($targetState);
        
        return sprintf(
            'Cannot transition from "%s" to "%s". %s',
            $current,
            $target,
            implode(' ', $this->transitionErrors)
        );
    }
    
    /**
     * Get state metadata (labels, colors, icons)
     */
    public function getStateMetadata($state = null): array
    {
        if ($state === null) {
            $state = $this->getCurrentState();
        }
        
        $config = $this->getStateConfig();
        $state = $this->normalizeState($state);
        
        $metadata = [
            'value' => $state,
            'label' => $config['labels'][$state] ?? ucfirst(str_replace('_', ' ', $state)),
            'color' => $config['colors'][$state] ?? 'gray',
            'icon' => $config['icons'][$state] ?? 'fas fa-circle',
            'description' => $config['descriptions'][$state] ?? null,
        ];
        
        // Add CSS classes for Tailwind
        $colorClasses = [
            'draft' => 'bg-gray-100 text-gray-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'published' => 'bg-green-100 text-green-800',
            'active' => 'bg-blue-100 text-blue-800',
            'inactive' => 'bg-red-100 text-red-800',
            'archived' => 'bg-gray-100 text-gray-800',
            'suspended' => 'bg-orange-100 text-orange-800',
        ];
        
        $metadata['css_class'] = $config['css_classes'][$state] ?? 
                                $colorClasses[strtolower($state)] ?? 
                                'bg-gray-100 text-gray-800';
        
        return $metadata;
    }
    
    /**
     * Get all possible states with metadata
     */
    public function getAllStates(): array
    {
        $config = $this->getStateConfig();
        $states = [];
        
        foreach ($config['states'] ?? [] as $state) {
            $states[$state] = $this->getStateMetadata($state);
        }
        
        return $states;
    }
    
    /**
     * Reset to initial state
     */
    public function resetState(?string $reason = null, ?int $actorId = null): bool
    {
        $config = $this->getStateConfig();
        
        if (!isset($config['initial'])) {
            throw new RuntimeException('Initial state not defined in configuration');
        }
        
        return $this->transitionTo($config['initial'], $reason, [], $actorId);
    }
    
    /**
     * Check if entity is in a specific state
     */
    public function isInState($state): bool
    {
        $currentState = $this->getCurrentState();
        $normalizedState = $this->normalizeState($state);
        
        return $currentState === $normalizedState;
    }
    
    /**
     * Check if entity is in any of the given states
     */
    public function isInAnyState(array $states): bool
    {
        $currentState = $this->getCurrentState();
        
        foreach ($states as $state) {
            if ($currentState === $this->normalizeState($state)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get time spent in current state
     */
    public function getTimeInCurrentState(): ?\DateInterval
    {
        $history = $this->getStateHistory(1);
        
        if (empty($history)) {
            return null;
        }
        
        $lastTransition = end($history);
        $transitionTime = $lastTransition['timestamp'];
        
        if (!$transitionTime instanceof DateTimeImmutable) {
            $transitionTime = new DateTimeImmutable($transitionTime);
        }
        
        $now = new DateTimeImmutable();
        
        return $now->diff($transitionTime);
    }
    
    /**
     * Get formatted time in current state
     */
    public function getFormattedTimeInCurrentState(): string
    {
        $interval = $this->getTimeInCurrentState();
        
        if (!$interval) {
            return 'Unknown';
        }
        
        if ($interval->days > 0) {
            return $interval->days . ' day' . ($interval->days > 1 ? 's' : '');
        }
        
        if ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '');
        }
        
        if ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '');
        }
        
        return 'Just now';
    }
}

/**
 * Example configuration for a Product entity:
 * 
 * protected static array $stateConfig = [
 *     'states' => ['draft', 'pending_review', 'published', 'archived'],
 *     'initial' => 'draft',
 *     'transitions' => [
 *         ['from' => 'draft', 'to' => 'pending_review'],
 *         ['from' => 'draft', 'to' => 'published', 'guard' => 'canPublishDirectly'],
 *         ['from' => 'pending_review', 'to' => 'published'],
 *         ['from' => 'pending_review', 'to' => 'draft'],
 *         ['from' => 'published', 'to' => 'archived'],
 *         ['from' => 'archived', 'to' => 'published', 'guard' => 'isAdmin'],
 *     ],
 *     'labels' => [
 *         'draft' => 'Draft',
 *         'pending_review' => 'Pending Review',
 *         'published' => 'Published',
 *         'archived' => 'Archived',
 *     ],
 *     'colors' => [
 *         'draft' => 'gray',
 *         'pending_review' => 'yellow',
 *         'published' => 'green',
 *         'archived' => 'gray',
 *     ],
 *     'timestamps' => [
 *         'published' => 'published_at',
 *         'archived' => 'archived_at',
 *     ],
 *     'hooks' => [
 *         'before' => function($entity, $oldState, $newState, $context) {
 *             // Global before hook
 *             return true;
 *         },
 *         'after' => function($entity, $oldState, $newState, $context) {
 *             // Global after hook
 *         },
 *     ],
 * ];
 */