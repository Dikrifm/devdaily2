<?php

namespace App\Repositories\Concrete;

use App\Entities\Admin;
use App\Models\AdminModel;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Contracts\CacheInterface;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin Repository Concrete Implementation
 * 
 * Data Orchestrator Layer (Layer 3): Persistence Abstraction & Cache Manager for Admin entity.
 * Implements Admin-specific operations with transaction safety and caching strategies.
 * 
 * @extends \App\Repositories\BaseRepository<Admin>
 */
final class AdminRepository extends \App\Repositories\BaseRepository implements AdminRepositoryInterface
{
    /**
     * Constructor with dependency injection
     * 
     * @param AdminModel $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        AdminModel $model,
        CacheInterface $cache,
        ConnectionInterface $db
    ) {
        parent::__construct($model, $cache, $db, Admin::class, 'admins');
    }

    /**
     * {@inheritDoc}
     */
    public function findByUsername(string $username): ?Admin
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByUsername', 'username' => $username]);
        
        return $this->remember($cacheKey, function () use ($username) {
            return $this->getModel()->findByUsername($username);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findByEmail(string $email): ?Admin
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByEmail', 'email' => $email]);
        
        return $this->remember($cacheKey, function () use ($email) {
            return $this->getModel()->findByEmail($email);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findByUsernameOrEmail(string $identifier): ?Admin
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByUsernameOrEmail', 'identifier' => $identifier]);
        
        return $this->remember($cacheKey, function () use ($identifier) {
            return $this->getModel()->findByUsernameOrEmail($identifier);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function verifyCredentials(string $identifier, string $password): ?Admin
    {
        // No caching for credential verification (security)
        return $this->getModel()->verifyCredentials($identifier, $password);
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveById(int|string $id): ?Admin
    {
        $cacheKey = $this->getEntityCacheKey($id) . ':active';
        
        return $this->remember($cacheKey, function () use ($id) {
            return $this->getModel()->findActiveById($id);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findSuperAdmins(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findSuperAdmins']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findSuperAdmins();
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findRegularAdmins(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findRegularAdmins']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findRegularAdmins();
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function countSuperAdmins(): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'countSuperAdmins']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->countSuperAdmins();
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function isLastSuperAdmin(int $adminId): bool
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'isLastSuperAdmin', 'adminId' => $adminId]);
        
        return $this->remember($cacheKey, function () use ($adminId) {
            return $this->getModel()->isLastSuperAdmin($adminId);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveAdmins(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findActiveAdmins']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findActiveAdmins();
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findInactiveAdmins(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findInactiveAdmins']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findInactiveAdmins();
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findLockedAdmins(int $maxAttempts = 5): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findLockedAdmins', 'maxAttempts' => $maxAttempts]);
        
        return $this->remember($cacheKey, function () use ($maxAttempts) {
            return $this->getModel()->findLockedAdmins($maxAttempts);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findInactiveForDays(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findInactiveForDays', 'days' => $days]);
        
        return $this->remember($cacheKey, function () use ($days) {
            return $this->getModel()->findInactiveForDays($days);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findAdminsNeedingPasswordRehash(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findAdminsNeedingPasswordRehash']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findAdminsNeedingPasswordRehash();
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function searchAdmins(string $searchTerm, int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'searchAdmins',
            'searchTerm' => $searchTerm,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->remember($cacheKey, function () use ($searchTerm, $limit, $offset) {
            return $this->getModel()->searchAdmins($searchTerm, $limit, $offset);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginateWithFilters',
            'filters' => $filters,
            'perPage' => $perPage,
            'page' => $page
        ]);
        
        return $this->remember($cacheKey, function () use ($filters, $perPage, $page) {
            return $this->getModel()->paginateWithFilters($filters, $perPage, $page);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function recordSuccessfulLogin(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $success = $this->getModel()->recordSuccessfulLogin($adminId);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
                // Invalidate admin lists cache
                $this->queueCacheInvalidation($this->cachePrefix . ':*list*');
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailedLoginAttempt(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $success = $this->getModel()->recordFailedLoginAttempt($adminId);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function resetLoginAttempts(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $success = $this->getModel()->resetLoginAttempts($adminId);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function updatePassword(int $adminId, string $newPasswordHash): bool
    {
        return $this->transaction(function () use ($adminId, $newPasswordHash) {
            $success = $this->getModel()->updatePassword($adminId, $newPasswordHash);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function activateAccount(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $success = $this->getModel()->activateAccount($adminId);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
                // Invalidate admin lists cache
                $this->queueCacheInvalidation($this->cachePrefix . ':*list*');
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function deactivateAccount(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $success = $this->getModel()->deactivateAccount($adminId);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
                // Invalidate admin lists cache
                $this->queueCacheInvalidation($this->cachePrefix . ':*list*');
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function updateRole(int $adminId, string $newRole): bool
    {
        return $this->transaction(function () use ($adminId, $newRole) {
            $success = $this->getModel()->updateRole($adminId, $newRole);
            
            if ($success) {
                // Invalidate cache for this admin
                $this->queueCacheInvalidation($this->getEntityCacheKey($adminId));
                // Invalidate role-specific lists
                $this->queueCacheInvalidation($this->cachePrefix . ':role:*');
            }
            
            return $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getStatistics']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->getStatistics();
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityTimeline(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getActivityTimeline', 'days' => $days]);
        
        return $this->remember($cacheKey, function () use ($days) {
            return $this->getModel()->getActivityTimeline($days);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function usernameExists(string $username, int|string|null $excludeId = null): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'usernameExists',
            'username' => $username,
            'excludeId' => $excludeId
        ]);
        
        return $this->remember($cacheKey, function () use ($username, $excludeId) {
            return $this->getModel()->usernameExists($username, $excludeId);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function emailExists(string $email, int|string|null $excludeId = null): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'emailExists',
            'email' => $email,
            'excludeId' => $excludeId
        ]);
        
        return $this->remember($cacheKey, function () use ($email, $excludeId) {
            return $this->getModel()->emailExists($email, $excludeId);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $ids): int
    {
        return $this->transaction(function () use ($ids) {
            $affected = $this->getModel()->bulkArchive($ids);
            
            if ($affected > 0) {
                // Invalidate cache for each entity
                foreach ($ids as $id) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                }
                
                // Invalidate query caches
                $this->queueCacheInvalidation($this->cachePrefix . ':*list*');
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $ids): int
    {
        return $this->transaction(function () use ($ids) {
            $affected = $this->getModel()->bulkRestore($ids);
            
            if ($affected > 0) {
                // Invalidate cache for each entity
                foreach ($ids as $id) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                }
                
                // Invalidate query caches
                $this->queueCacheInvalidation($this->cachePrefix . ':*list*');
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function initializeSystemAdmin(): array
    {
        // No caching for initialization
        return $this->getModel()->initializeSystemAdmin();
    }

    /**
     * {@inheritDoc}
     */
    public function createSample(array $overrides = []): Admin
    {
        // No caching for sample creation
        return $this->getModel()->createSample($overrides);
    }

    /**
     * Get AdminModel instance
     * 
     * @return AdminModel
     */
    private function getModel(): AdminModel
    {
        return $this->model;
    }

    /**
     * Apply Admin-specific criteria to query builder
     * 
     * @param BaseBuilder $builder
     * @param array<string, mixed> $criteria
     * @return void
     */
    protected function applyCriteria(BaseBuilder $builder, array $criteria): void
    {
        foreach ($criteria as $field => $value) {
            switch ($field) {
                case 'role':
                    if (is_array($value)) {
                        $builder->whereIn('role', $value);
                    } else {
                        $builder->where('role', $value);
                    }
                    break;
                    
                case 'active':
                    $builder->where('active', $value);
                    break;
                    
                case 'search':
                    if (is_string($value) && !empty($value)) {
                        $builder->groupStart()
                            ->like('username', $value)
                            ->orLike('email', $value)
                            ->orLike('name', $value)
                            ->groupEnd();
                    }
                    break;
                    
                case 'created_after':
                    $builder->where('created_at >=', $value);
                    break;
                    
                case 'created_before':
                    $builder->where('created_at <=', $value);
                    break;
                    
                case 'last_login_after':
                    $builder->where('last_login >=', $value);
                    break;
                    
                case 'last_login_before':
                    $builder->where('last_login <=', $value);
                    break;
                    
                default:
                    // Use parent's default handling
                    parent::applyCriteria($builder, [$field => $value]);
                    break;
            }
        }
    }
}