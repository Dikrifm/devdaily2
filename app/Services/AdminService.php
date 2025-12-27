<?php

namespace App\Services;

use App\Contracts\AdminInterface;
use App\DTOs\Requests\Admin\CreateAdminRequest;
use App\DTOs\Requests\Admin\UpdateAdminRequest;
use App\DTOs\Requests\Admin\ChangeAdminPasswordRequest;
use App\DTOs\Requests\Admin\ToggleAdminStatusRequest;
use App\DTOs\Responses\AdminResponse;
use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Responses\BulkActionResult;
use App\DTOs\Responses\BulkActionStatus;
use App\Entities\Admin;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Validators\AdminValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation of AdminInterface.
 * Manages admin CRUD, authentication, roles, and administrative functions.
 *
 * @package App\Services
 */
final class AdminService extends BaseService implements AdminInterface
{
    /**
     * Admin repository for data persistence
     *
     * @var AdminRepositoryInterface
     */
    private AdminRepositoryInterface $adminRepository;

    /**
     * Audit log repository for audit trails
     *
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * Admin validator for complex business rules
     *
     * @var AdminValidator
     */
    private AdminValidator $adminValidator;

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     * @param AdminRepositoryInterface $adminRepository
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param AdminValidator $adminValidator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        AdminRepositoryInterface $adminRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        AdminValidator $adminValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->adminRepository = $adminRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->adminValidator = $adminValidator;
    }

    // ==================== ADMIN CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createAdmin(CreateAdminRequest $request): AdminResponse
    {
        $this->authorize('admin.create');
        
        return $this->transaction(function () use ($request) {
            // Validate business rules
            $validationErrors = $this->validateBusinessRules($request, ['context' => 'create']);
            if (!empty($validationErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Admin creation validation failed',
                    $validationErrors
                );
            }

            // Check username and email availability
            if (!$this->isUsernameAvailable($request->username)) {
                throw new DomainException(
                    'Username already exists',
                    'USERNAME_EXISTS'
                );
            }

            if (!$this->isEmailAvailable($request->email)) {
                throw new DomainException(
                    'Email already exists',
                    'EMAIL_EXISTS'
                );
            }

            // Create admin entity
            $admin = Admin::fromArray([
                'username' => $request->username,
                'email' => $request->email,
                'name' => $request->name,
                'role' => $request->role ?? 'admin',
                'active' => $request->active ?? true,
            ]);

            // Set password with hash
            $admin->setPasswordWithHash($request->password);

            // Save to repository
            $savedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:*');
            $this->queueCacheOperation('admin:list:*');

            // Audit log
            $this->audit(
                'admin.created',
                'admin',
                $savedAdmin->getId(),
                null,
                $savedAdmin->toArray(),
                [
                    'created_by' => $this->getCurrentAdminId(),
                    'request_data' => $request->toArray()
                ]
            );

            return AdminResponse::fromEntity($savedAdmin);
        }, 'admin_create');
    }

    /**
     * {@inheritDoc}
     */
    public function getAdmin(int $adminId): AdminResponse
    {
        $this->authorize('admin.view');

        return $this->withCaching(
            'admin:' . $adminId,
            function () use ($adminId) {
                $admin = $this->getEntity($this->adminRepository, $adminId);
                
                // Check if current admin can view this admin
                if ($this->getCurrentAdminId() !== $adminId && 
                    $admin->getRole() === 'super_admin' && 
                    $this->getCurrentAdminId() !== 1) {
                    throw new AuthorizationException(
                        'Cannot view super admin details',
                        'VIEW_SUPER_ADMIN_FORBIDDEN'
                    );
                }

                return AdminResponse::fromEntity($admin);
            },
            300 // 5 minutes cache
        );
    }

    /**
     * {@inheritDoc}
     */
    public function updateAdmin(UpdateAdminRequest $request): AdminResponse
    {
        $this->authorize('admin.update');

        return $this->transaction(function () use ($request) {
            $admin = $this->getEntity($this->adminRepository, $request->id);
            
            // Check permissions for super admin updates
            if ($admin->getRole() === 'super_admin' && 
                $this->getCurrentAdminId() !== 1 && 
                $this->getCurrentAdminId() !== $admin->getId()) {
                throw new AuthorizationException(
                    'Cannot update super admin',
                    'UPDATE_SUPER_ADMIN_FORBIDDEN'
                );
            }

            // Validate business rules
            $validationErrors = $this->validateBusinessRules($request, [
                'context' => 'update',
                'existing_admin' => $admin
            ]);
            
            if (!empty($validationErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Admin update validation failed',
                    $validationErrors
                );
            }

            // Store old values for audit
            $oldValues = $admin->toArray();

            // Update fields
            if ($request->has('username') && $request->username !== $admin->getUsername()) {
                if (!$this->isUsernameAvailable($request->username, $admin->getId())) {
                    throw new DomainException(
                        'Username already exists',
                        'USERNAME_EXISTS'
                    );
                }
                $admin->setUsername($request->username);
            }

            if ($request->has('email') && $request->email !== $admin->getEmail()) {
                if (!$this->isEmailAvailable($request->email, $admin->getId())) {
                    throw new DomainException(
                        'Email already exists',
                        'EMAIL_EXISTS'
                    );
                }
                $admin->setEmail($request->email);
            }

            if ($request->has('name')) {
                $admin->setName($request->name);
            }

            if ($request->has('role') && $request->role !== $admin->getRole()) {
                // Validate role change
                $canChangeRole = $this->validateRoleChange($admin, $request->role);
                if (!$canChangeRole['can_change']) {
                    throw new DomainException(
                        implode(', ', $canChangeRole['reasons']),
                        'ROLE_CHANGE_FORBIDDEN'
                    );
                }
                $admin->setRole($request->role);
            }

            if ($request->has('active')) {
                $admin->setActive($request->active);
            }

            // Save changes
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $admin->getId());
            $this->queueCacheOperation('admin:list:*');

            // Audit log
            $this->audit(
                'admin.updated',
                'admin',
                $admin->getId(),
                $oldValues,
                $updatedAdmin->toArray(),
                [
                    'updated_by' => $this->getCurrentAdminId(),
                    'request_data' => $request->toArray()
                ]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_update');
    }

    /**
     * {@inheritDoc}
     */
    public function changePassword(ChangeAdminPasswordRequest $request): AdminResponse
    {
        return $this->transaction(function () use ($request) {
            $admin = $this->getEntity($this->adminRepository, $request->adminId);
            
            // Check permissions
            if ($this->getCurrentAdminId() !== $admin->getId() && 
                !$this->checkPermission($this->getCurrentAdminId(), 'admin.update.password')) {
                throw new AuthorizationException(
                    'Not authorized to change password for this admin',
                    'PASSWORD_CHANGE_FORBIDDEN'
                );
            }

            // Verify current password if provided
            if ($request->currentPassword && !$admin->verifyPassword($request->currentPassword)) {
                throw new ValidationException(
                    'Current password is incorrect',
                    'INVALID_CURRENT_PASSWORD'
                );
            }

            // Set new password
            $admin->setPasswordWithHash($request->newPassword);

            // Save to repository
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $admin->getId());

            // Audit log
            $this->audit(
                'admin.password_changed',
                'admin',
                $admin->getId(),
                null,
                ['password_changed_at' => Time::now()->toDateTimeString()],
                [
                    'changed_by' => $this->getCurrentAdminId(),
                    'is_self_service' => $this->getCurrentAdminId() === $admin->getId()
                ]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_change_password');
    }

    /**
     * {@inheritDoc}
     */
    public function toggleStatus(ToggleAdminStatusRequest $request): AdminResponse
    {
        $this->authorize('admin.update.status');

        return $this->transaction(function () use ($request) {
            $admin = $this->getEntity($this->adminRepository, $request->adminId);
            
            // Prevent self-deactivation
            if ($this->getCurrentAdminId() === $admin->getId() && !$request->active) {
                throw new DomainException(
                    'Cannot deactivate your own account',
                    'SELF_DEACTIVATION_FORBIDDEN'
                );
            }

            // Prevent deactivating last super admin
            if ($admin->getRole() === 'super_admin' && !$request->active) {
                if ($this->isLastSuperAdmin($admin->getId())) {
                    throw new DomainException(
                        'Cannot deactivate last super admin',
                        'LAST_SUPER_ADMIN_FORBIDDEN'
                    );
                }
            }

            $oldStatus = $admin->isActive();
            $admin->setActive($request->active);

            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $admin->getId());
            $this->queueCacheOperation('admin:list:*');

            // Audit log
            $this->audit(
                $request->active ? 'admin.activated' : 'admin.deactivated',
                'admin',
                $admin->getId(),
                ['active' => $oldStatus],
                ['active' => $request->active],
                [
                    'changed_by' => $this->getCurrentAdminId(),
                    'reason' => $request->reason
                ]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_toggle_status');
    }

    /**
     * {@inheritDoc}
     */
    public function archiveAdmin(int $adminId, ?string $reason = null): AdminResponse
    {
        $this->authorize('admin.archive');

        return $this->transaction(function () use ($adminId, $reason) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Prevent self-archiving
            if ($this->getCurrentAdminId() === $admin->getId()) {
                throw new DomainException(
                    'Cannot archive your own account',
                    'SELF_ARCHIVE_FORBIDDEN'
                );
            }

            // Validate can be archived
            $validation = $this->validateCanArchive($adminId);
            if (!$validation['can_archive']) {
                throw new DomainException(
                    implode(', ', $validation['reasons']),
                    'ARCHIVE_VALIDATION_FAILED'
                );
            }

            // Archive admin
            $admin->deactivate();
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('admin:list:*');
            $this->queueCacheOperation('admin:active:*');

            // Audit log
            $this->audit(
                'admin.archived',
                'admin',
                $adminId,
                null,
                $updatedAdmin->toArray(),
                [
                    'archived_by' => $this->getCurrentAdminId(),
                    'reason' => $reason
                ]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_archive');
    }

    /**
     * {@inheritDoc}
     */
    public function restoreAdmin(int $adminId): AdminResponse
    {
        $this->authorize('admin.restore');

        return $this->transaction(function () use ($adminId) {
            $admin = $this->getEntity($this->adminRepository, $adminId, false);
            
            if ($admin === null) {
                throw NotFoundException::forEntity('Admin', $adminId);
            }

            // Restore admin
            $admin->activate();
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('admin:list:*');
            $this->queueCacheOperation('admin:archived:*');

            // Audit log
            $this->audit(
                'admin.restored',
                'admin',
                $adminId,
                null,
                $updatedAdmin->toArray(),
                ['restored_by' => $this->getCurrentAdminId()]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_restore');
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAdmin(int $adminId, ?string $reason = null): bool
    {
        $this->authorize('admin.delete');

        return $this->transaction(function () use ($adminId, $reason) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Prevent self-deletion
            if ($this->getCurrentAdminId() === $admin->getId()) {
                throw new DomainException(
                    'Cannot delete your own account',
                    'SELF_DELETION_FORBIDDEN'
                );
            }

            // Validate can be deleted
            $validation = $this->validateCanDelete($adminId);
            if (!$validation['can_delete']) {
                throw new DomainException(
                    implode(', ', $validation['reasons']),
                    'DELETE_VALIDATION_FAILED'
                );
            }

            // Hard delete
            $success = $this->adminRepository->forceDelete($adminId);

            if ($success) {
                // Clear cache
                $this->queueCacheOperation('admin:*');
                $this->queueCacheOperation('admin:list:*');

                // Audit log
                $this->audit(
                    'admin.deleted',
                    'admin',
                    $adminId,
                    $admin->toArray(),
                    null,
                    [
                        'deleted_by' => $this->getCurrentAdminId(),
                        'reason' => $reason,
                        'hard_delete' => true
                    ]
                );
            }

            return $success;
        }, 'admin_delete');
    }

    // ==================== ADMIN LISTING & SEARCH ====================

    /**
     * {@inheritDoc}
     */
    public function listAdmins(PaginationQuery $pagination, array $filters = []): array
    {
        $this->authorize('admin.view.list');

        $cacheKey = $this->getServiceCacheKey('list_admins', [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'filters' => $filters
        ]);

        return $this->withCaching($cacheKey, function () use ($pagination, $filters) {
            $result = $this->adminRepository->paginateWithFilters($filters, $pagination->perPage, $pagination->page);
            
            $adminResponses = array_map(function ($admin) {
                return AdminResponse::fromEntity($admin);
            }, $result['items'] ?? []);

            return [
                'items' => $adminResponses,
                'pagination' => [
                    'total' => $result['total'] ?? 0,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil(($result['total'] ?? 0) / $pagination->perPage)
                ]
            ];
        }, 180); // 3 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function searchAdmins(string $searchTerm, PaginationQuery $pagination): array
    {
        $this->authorize('admin.view.list');

        $cacheKey = $this->getServiceCacheKey('search_admins', [
            'term' => $searchTerm,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($searchTerm, $pagination) {
            $admins = $this->adminRepository->searchAdmins($searchTerm, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $adminResponses = array_map(function ($admin) {
                return AdminResponse::fromEntity($admin);
            }, $admins);

            // Note: searchAdmins doesn't return total count in repository
            // For pagination, we might need a separate count method
            $total = $this->adminRepository->count(['search' => $searchTerm]);

            return [
                'items' => $adminResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 120); // 2 minutes cache for search results
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveAdmins(): array
    {
        $this->authorize('admin.view.list');

        return $this->withCaching(
            'active_admins',
            function () {
                $admins = $this->adminRepository->findActiveAdmins();
                return array_map(function ($admin) {
                    return AdminResponse::fromEntity($admin);
                }, $admins);
            },
            300
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getSuperAdmins(): array
    {
        $this->authorize('admin.view.super');

        return $this->withCaching(
            'super_admins',
            function () {
                $admins = $this->adminRepository->findSuperAdmins();
                return array_map(function ($admin) {
                    return AdminResponse::fromEntity($admin);
                }, $admins);
            },
            600
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getInactiveAdmins(): array
    {
        $this->authorize('admin.view.list');

        return $this->withCaching(
            'inactive_admins',
            function () {
                $admins = $this->adminRepository->findInactiveAdmins();
                return array_map(function ($admin) {
                    return AdminResponse::fromEntity($admin);
                }, $admins);
            },
            300
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getArchivedAdmins(PaginationQuery $pagination): array
    {
        $this->authorize('admin.view.archived');

        $cacheKey = $this->getServiceCacheKey('archived_admins', [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($pagination) {
            // Assuming findArchived method exists in repository
            $admins = $this->adminRepository->findAll(['active' => false, 'deleted_at IS NOT NULL' => null]);
            
            // Paginate manually
            $total = count($admins);
            $offset = ($pagination->page - 1) * $pagination->perPage;
            $paginatedAdmins = array_slice($admins, $offset, $pagination->perPage);

            $adminResponses = array_map(function ($admin) {
                return AdminResponse::fromEntity($admin);
            }, $paginatedAdmins);

            return [
                'items' => $adminResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    // ==================== ADMIN AUTHENTICATION & SESSION ====================

    /**
     * {@inheritDoc}
     */
    public function recordLogin(int $adminId, array $sessionData): AdminResponse
    {
        return $this->transaction(function () use ($adminId, $sessionData) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->recordLogin();
            $admin->resetLoginAttempts();
            
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('admin:locked:*');

            // Audit log
            $this->audit(
                'admin.logged_in',
                'admin',
                $adminId,
                null,
                [
                    'last_login' => Time::now()->toDateTimeString(),
                    'login_attempts' => 0
                ],
                array_merge($sessionData, ['login_type' => 'success'])
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_record_login');
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailedLogin(int $adminId): AdminResponse
    {
        return $this->transaction(function () use ($adminId) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->recordFailedLogin();
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);

            // Audit log
            $this->audit(
                'admin.login_failed',
                'admin',
                $adminId,
                null,
                ['login_attempts' => $admin->getLoginAttempts()],
                ['login_type' => 'failed']
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_record_failed_login');
    }

    /**
     * {@inheritDoc}
     */
    public function resetLoginAttempts(int $adminId): AdminResponse
    {
        $this->authorize('admin.manage');

        return $this->transaction(function () use ($adminId) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->resetLoginAttempts();
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('admin:locked:*');

            // Audit log
            $this->audit(
                'admin.login_attempts_reset',
                'admin',
                $adminId,
                null,
                ['login_attempts' => 0],
                ['reset_by' => $this->getCurrentAdminId()]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_reset_login_attempts');
    }

    /**
     * {@inheritDoc}
     */
    public function isAccountLocked(int $adminId, int $maxAttempts = 5): bool
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        return $admin->isLocked($maxAttempts);
    }

    /**
     * {@inheritDoc}
     */
    public function updateLastLogin(int $adminId): AdminResponse
    {
        return $this->transaction(function () use ($adminId) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->setLastLogin(Time::now());
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_update_last_login');
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $adminIds, ?string $reason = null): BulkActionResult
    {
        $this->authorize('admin.bulk.archive');

        $result = new BulkActionResult();
        $result->total = count($adminIds);
        $result->operation = 'archive';
        
        return $this->batchOperation($adminIds, function ($adminId) use ($reason) {
            try {
                $this->archiveAdmin($adminId, $reason);
                return BulkActionStatus::SUCCESS;
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    'Failed to archive admin %d: %s',
                    $adminId,
                    $e->getMessage()
                ));
                return BulkActionStatus::FAILED;
            }
        }, 50, function ($adminId, $index, $total) use ($result) {
            $result->processed++;
            
            if ($this->getEntity($this->adminRepository, $adminId)->getRole() === 'super_admin') {
                $result->skipped++;
                $result->details[] = [
                    'id' => $adminId,
                    'status' => BulkActionStatus::SKIPPED,
                    'reason' => 'Cannot archive super admin'
                ];
                return BulkActionStatus::SKIPPED;
            }
            
            return BulkActionStatus::PROCESSING;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $adminIds): BulkActionResult
    {
        $this->authorize('admin.bulk.restore');

        $result = new BulkActionResult();
        $result->total = count($adminIds);
        $result->operation = 'restore';
        
        return $this->batchOperation($adminIds, function ($adminId) {
            try {
                $this->restoreAdmin($adminId);
                return BulkActionStatus::SUCCESS;
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    'Failed to restore admin %d: %s',
                    $adminId,
                    $e->getMessage()
                ));
                return BulkActionStatus::FAILED;
            }
        }, 50);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkActivate(array $adminIds): BulkActionResult
    {
        $this->authorize('admin.bulk.activate');

        $result = new BulkActionResult();
        $result->total = count($adminIds);
        $result->operation = 'activate';
        
        return $this->batchOperation($adminIds, function ($adminId) {
            try {
                $request = new ToggleAdminStatusRequest();
                $request->adminId = $adminId;
                $request->active = true;
                
                $this->toggleStatus($request);
                return BulkActionStatus::SUCCESS;
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    'Failed to activate admin %d: %s',
                    $adminId,
                    $e->getMessage()
                ));
                return BulkActionStatus::FAILED;
            }
        }, 50);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDeactivate(array $adminIds, ?string $reason = null): BulkActionResult
    {
        $this->authorize('admin.bulk.deactivate');

        $result = new BulkActionResult();
        $result->total = count($adminIds);
        $result->operation = 'deactivate';
        
        return $this->batchOperation($adminIds, function ($adminId) use ($reason) {
            try {
                $request = new ToggleAdminStatusRequest();
                $request->adminId = $adminId;
                $request->active = false;
                $request->reason = $reason;
                
                $this->toggleStatus($request);
                return BulkActionStatus::SUCCESS;
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    'Failed to deactivate admin %d: %s',
                    $adminId,
                    $e->getMessage()
                ));
                return BulkActionStatus::FAILED;
            }
        }, 50, function ($adminId, $index, $total) use ($result) {
            $result->processed++;
            
            // Check if this is the last super admin
            if ($this->getEntity($this->adminRepository, $adminId)->getRole() === 'super_admin') {
                if ($this->isLastSuperAdmin($adminId)) {
                    $result->skipped++;
                    $result->details[] = [
                        'id' => $adminId,
                        'status' => BulkActionStatus::SKIPPED,
                        'reason' => 'Cannot deactivate last super admin'
                    ];
                    return BulkActionStatus::SKIPPED;
                }
            }
            
            return BulkActionStatus::PROCESSING;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkChangeRole(array $adminIds, string $newRole, ?string $reason = null): BulkActionResult
    {
        $this->authorize('admin.bulk.change_role');

        $result = new BulkActionResult();
        $result->total = count($adminIds);
        $result->operation = 'change_role';
        
        return $this->batchOperation($adminIds, function ($adminId) use ($newRole, $reason) {
            try {
                $this->changeRole($adminId, $newRole, $reason);
                return BulkActionStatus::SUCCESS;
            } catch (\Throwable $e) {
                log_message('error', sprintf(
                    'Failed to change role for admin %d: %s',
                    $adminId,
                    $e->getMessage()
                ));
                return BulkActionStatus::FAILED;
            }
        }, 50);
    }

    // ==================== ADMIN ROLE & PERMISSION MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function changeRole(int $adminId, string $newRole, ?string $reason = null): AdminResponse
    {
        $this->authorize('admin.change_role');

        return $this->transaction(function () use ($adminId, $newRole, $reason) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Validate role change
            $canChangeRole = $this->validateRoleChange($admin, $newRole);
            if (!$canChangeRole['can_change']) {
                throw new DomainException(
                    implode(', ', $canChangeRole['reasons']),
                    'ROLE_CHANGE_FORBIDDEN'
                );
            }

            $oldRole = $admin->getRole();
            $admin->setRole($newRole);
            
            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('admin:super_admins');
            $this->queueCacheOperation('admin:regular_admins');

            // Audit log
            $this->audit(
                'admin.role_changed',
                'admin',
                $adminId,
                ['role' => $oldRole],
                ['role' => $newRole],
                [
                    'changed_by' => $this->getCurrentAdminId(),
                    'reason' => $reason
                ]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_change_role');
    }

    /**
     * {@inheritDoc}
     */
    public function promoteToSuperAdmin(int $adminId, ?string $reason = null): AdminResponse
    {
        return $this->changeRole($adminId, 'super_admin', $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function demoteToAdmin(int $adminId, ?string $reason = null): AdminResponse
    {
        return $this->changeRole($adminId, 'admin', $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function isSuperAdmin(int $adminId): bool
    {
        $admin = $this->getEntity($this->adminRepository, $adminId, false);
        return $admin !== null && $admin->getRole() === 'super_admin';
    }

    /**
     * {@inheritDoc}
     */
    public function isLastSuperAdmin(int $adminId): bool
    {
        return $this->adminRepository->isLastSuperAdmin($adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function getPermissions(int $adminId): array
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        // Basic permission map based on role
        $permissionMap = [
            'super_admin' => [
                'admin.create' => true,
                'admin.update' => true,
                'admin.delete' => true,
                'admin.archive' => true,
                'admin.restore' => true,
                'admin.view.list' => true,
                'admin.view.super' => true,
                'admin.view.archived' => true,
                'admin.change_role' => true,
                'admin.manage' => true,
                'admin.bulk.archive' => true,
                'admin.bulk.restore' => true,
                'admin.bulk.activate' => true,
                'admin.bulk.deactivate' => true,
                'admin.bulk.change_role' => true,
                'product.create' => true,
                'product.update' => true,
                'product.delete' => true,
                'product.publish' => true,
                'product.verify' => true,
                'audit.view' => true,
                'system.manage' => true,
            ],
            'admin' => [
                'admin.view.list' => true,
                'product.create' => true,
                'product.update' => true,
                'product.publish' => true,
                'audit.view' => true,
            ]
        ];

        return $permissionMap[$admin->getRole()] ?? [];
    }

    // ==================== ADMIN VALIDATION & BUSINESS RULES ====================

    /**
     * {@inheritDoc}
     */
    public function isUsernameAvailable(string $username, ?int $excludeAdminId = null): bool
    {
        return $this->adminRepository->usernameExists($username, $excludeAdminId) === false;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmailAvailable(string $email, ?int $excludeAdminId = null): bool
    {
        return $this->adminRepository->emailExists($email, $excludeAdminId) === false;
    }

    /**
     * {@inheritDoc}
     */
    public function validateCanArchive(int $adminId): array
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $result = [
            'can_archive' => true,
            'reasons' => [],
            'warnings' => []
        ];

        // Cannot archive self
        if ($this->getCurrentAdminId() === $adminId) {
            $result['can_archive'] = false;
            $result['reasons'][] = 'Cannot archive your own account';
        }

        // Cannot archive super admin (unless last one)
        if ($admin->getRole() === 'super_admin') {
            if ($this->isLastSuperAdmin($adminId)) {
                $result['can_archive'] = false;
                $result['reasons'][] = 'Cannot archive last super admin';
            } else {
                $result['warnings'][] = 'Archiving a super admin may affect system administration';
            }
        }

        // Check if admin is currently active
        if (!$admin->isActive()) {
            $result['warnings'][] = 'Admin is already inactive';
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function validateCanDelete(int $adminId): array
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $result = [
            'can_delete' => true,
            'reasons' => [],
            'warnings' => []
        ];

        // Cannot delete self
        if ($this->getCurrentAdminId() === $adminId) {
            $result['can_delete'] = false;
            $result['reasons'][] = 'Cannot delete your own account';
        }

        // Cannot delete super admin
        if ($admin->getRole() === 'super_admin') {
            $result['can_delete'] = false;
            $result['reasons'][] = 'Cannot delete super admin account';
        }

        // Check if admin has recent activity
        if ($admin->isRecentlyActive()) {
            $result['warnings'][] = 'Admin has been active recently';
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function validateCanDemote(int $adminId): array
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $result = [
            'can_demote' => true,
            'reasons' => [],
            'warnings' => []
        ];

        // Cannot demote self
        if ($this->getCurrentAdminId() === $adminId) {
            $result['can_demote'] = false;
            $result['reasons'][] = 'Cannot demote your own account';
        }

        // Check if admin is super admin
        if ($admin->getRole() !== 'super_admin') {
            $result['can_demote'] = false;
            $result['reasons'][] = 'Admin is not a super admin';
        }

        // Cannot demote last super admin
        if ($this->isLastSuperAdmin($adminId)) {
            $result['can_demote'] = false;
            $result['reasons'][] = 'Cannot demote last super admin';
        }

        return $result;
    }

    // ==================== ADMIN STATISTICS & REPORTING ====================

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $this->authorize('admin.view.statistics');

        return $this->withCaching(
            'admin_statistics',
            function () {
                $stats = $this->adminRepository->getStatistics();
                
                return [
                    'total' => $stats['total'] ?? 0,
                    'active' => $stats['active'] ?? 0,
                    'inactive' => $stats['inactive'] ?? 0,
                    'super_admins' => $stats['super_admins'] ?? 0,
                    'regular_admins' => $stats['regular_admins'] ?? 0,
                    'archived' => $stats['archived'] ?? 0,
                    'locked' => $this->adminRepository->count(['login_attempts >=' => 5]) ?? 0,
                    'need_password_rehash' => $this->adminRepository->findAdminsNeedingPasswordRehash() ? count($this->adminRepository->findAdminsNeedingPasswordRehash()) : 0
                ];
            },
            600 // 10 minutes cache
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityTimeline(int $days = 30, ?int $adminId = null): array
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('activity_timeline', [
            'days' => $days,
            'admin_id' => $adminId
        ]);

        return $this->withCaching($cacheKey, function () use ($days, $adminId) {
            if ($adminId !== null) {
                // Get activity for specific admin
                $logs = $this->auditLogRepository->findByAdminId($adminId, 1000, 0);
            } else {
                // Get system-wide activity
                $logs = $this->auditLogRepository->findRecent($days * 24, 1000, 0);
            }

            $timeline = [];
            foreach ($logs as $log) {
                $date = $log->getPerformedAt()->format('Y-m-d');
                
                if (!isset($timeline[$date])) {
                    $timeline[$date] = [
                        'date' => $date,
                        'logins' => 0,
                        'actions' => 0,
                        'admin_id' => $adminId,
                        'admin_name' => $adminId ? $this->getEntity($this->adminRepository, $adminId)->getName() : null
                    ];
                }

                if ($log->getActionType() === 'admin.logged_in') {
                    $timeline[$date]['logins']++;
                }
                
                $timeline[$date]['actions']++;
            }

            return array_values($timeline);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceMetrics(int $adminId, int $days = 30): array
    {
        $this->authorize('admin.view.metrics');

        $cacheKey = $this->getServiceCacheKey('performance_metrics', [
            'admin_id' => $adminId,
            'days' => $days
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $days) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Get admin's audit logs
            $logs = $this->auditLogRepository->findByAdminId($adminId, 1000, 0);
            
            // Filter by date range
            $startDate = (new Time("-$days days"))->toDateTimeString();
            $recentLogs = array_filter($logs, function ($log) use ($startDate) {
                return $log->getPerformedAt() >= new \DateTime($startDate);
            });

            // Count actions by type
            $actionCounts = [];
            foreach ($recentLogs as $log) {
                $actionType = $log->getActionType();
                $actionCounts[$actionType] = ($actionCounts[$actionType] ?? 0) + 1;
            }

            arsort($actionCounts);
            $commonActions = array_slice($actionCounts, 0, 5, true);

            $loginCount = count(array_filter($recentLogs, function ($log) {
                return $log->getActionType() === 'admin.logged_in';
            }));

            $actionCount = count($recentLogs);
            $avgActionsPerDay = $days > 0 ? $actionCount / $days : 0;

            return [
                'login_count' => $loginCount,
                'action_count' => $actionCount,
                'avg_actions_per_day' => round($avgActionsPerDay, 2),
                'last_active' => $admin->getFormattedLastLogin(),
                'common_actions' => $commonActions
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getAdminsNeedingPasswordRehash(PaginationQuery $pagination): array
    {
        $this->authorize('admin.view.list');

        $cacheKey = $this->getServiceCacheKey('admins_needing_rehash', [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($pagination) {
            $admins = $this->adminRepository->findAdminsNeedingPasswordRehash();
            
            // Manual pagination
            $total = count($admins);
            $offset = ($pagination->page - 1) * $pagination->perPage;
            $paginatedAdmins = array_slice($admins, $offset, $pagination->perPage);

            $adminResponses = array_map(function ($admin) {
                return AdminResponse::fromEntity($admin);
            }, $paginatedAdmins);

            return [
                'items' => $adminResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    // ==================== SYSTEM ADMIN MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function initializeSystemAdmin(array $adminData): AdminResponse
    {
        // Only allow system admin initialization if no admins exist
        if ($this->systemAdminExists()) {
            throw new DomainException(
                'System admin already exists',
                'SYSTEM_ADMIN_EXISTS'
            );
        }

        return $this->transaction(function () use ($adminData) {
            // Create system admin with super admin role
            $admin = Admin::fromArray([
                'username' => $adminData['username'] ?? 'system',
                'email' => $adminData['email'] ?? 'system@localhost',
                'name' => $adminData['name'] ?? 'System Administrator',
                'role' => 'super_admin',
                'active' => true,
            ]);

            // Set password
            $admin->setPasswordWithHash($adminData['password'] ?? 'admin123');

            // Save to repository
            $savedAdmin = $this->adminRepository->save($admin);

            // Clear all cache
            $this->queueCacheOperation('admin:*');

            // Audit log (system action)
            $this->audit(
                'system.admin_initialized',
                'admin',
                $savedAdmin->getId(),
                null,
                $savedAdmin->toArray(),
                ['system_init' => true]
            );

            return AdminResponse::fromEntity($savedAdmin);
        }, 'system_admin_initialize');
    }

    /**
     * {@inheritDoc}
     */
    public function systemAdminExists(): bool
    {
        return $this->adminRepository->count() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemAdmin(): AdminResponse
    {
        // System admin is the first created admin (usually ID 1)
        $admin = $this->getEntity($this->adminRepository, 1);
        
        if ($admin->getRole() !== 'super_admin') {
            throw new DomainException(
                'System admin not found or not a super admin',
                'SYSTEM_ADMIN_NOT_FOUND'
            );
        }

        return AdminResponse::fromEntity($admin);
    }

    // ==================== ADMIN PROFILE & SETTINGS ====================

    /**
     * {@inheritDoc}
     */
    public function updateProfile(int $adminId, array $profileData): AdminResponse
    {
        // Only allow self-update or admin with permission
        if ($this->getCurrentAdminId() !== $adminId && 
            !$this->checkPermission($this->getCurrentAdminId(), 'admin.update')) {
            throw new AuthorizationException(
                'Not authorized to update this profile',
                'PROFILE_UPDATE_FORBIDDEN'
            );
        }

        return $this->transaction(function () use ($adminId, $profileData) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $oldValues = $admin->toArray();

            // Update allowed fields
            if (isset($profileData['name'])) {
                $admin->setName($profileData['name']);
            }

            if (isset($profileData['email'])) {
                if (!$this->isEmailAvailable($profileData['email'], $adminId)) {
                    throw new DomainException(
                        'Email already exists',
                        'EMAIL_EXISTS'
                    );
                }
                $admin->setEmail($profileData['email']);
            }

            $updatedAdmin = $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);

            // Audit log
            $this->audit(
                'admin.profile_updated',
                'admin',
                $adminId,
                $oldValues,
                $updatedAdmin->toArray(),
                ['updated_by' => $this->getCurrentAdminId()]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_update_profile');
    }

    /**
     * {@inheritDoc}
     */
    public function verifyPassword(int $adminId, string $password): bool
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        return $admin->verifyPassword($password);
    }

    /**
     * {@inheritDoc}
     */
    public function needsPasswordRehash(int $adminId): bool
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        return $admin->passwordNeedsRehash();
    }

    /**
     * {@inheritDoc}
     */
    public function forcePasswordChange(int $adminId, bool $force = true): AdminResponse
    {
        $this->authorize('admin.manage');

        return $this->transaction(function () use ($adminId, $force) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Implement force password change logic
            // This could set a flag or expiry date
            // For now, we'll just audit the action
            
            $updatedAdmin = $this->adminRepository->save($admin);

            // Audit log
            $this->audit(
                'admin.password_change_forced',
                'admin',
                $adminId,
                null,
                ['force_password_change' => $force],
                ['forced_by' => $this->getCurrentAdminId()]
            );

            return AdminResponse::fromEntity($updatedAdmin);
        }, 'admin_force_password_change');
    }

    // ==================== ABSTRACT METHOD IMPLEMENTATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        // Delegate to AdminValidator
        return $this->adminValidator->validate($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'AdminService';
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Validate role change business rules
     *
     * @param Admin $admin
     * @param string $newRole
     * @return array
     */
    private function validateRoleChange(Admin $admin, string $newRole): array
    {
        $result = [
            'can_change' => true,
            'reasons' => []
        ];

        $currentRole = $admin->getRole();
        
        // No change needed
        if ($currentRole === $newRole) {
            $result['can_change'] = false;
            $result['reasons'][] = 'Role is already ' . $newRole;
            return $result;
        }

        // Prevent changing system admin's role
        if ($admin->getId() === 1 && $currentRole === 'super_admin') {
            $result['can_change'] = false;
            $result['reasons'][] = 'Cannot change system admin role';
            return $result;
        }

        // Demotion from super admin to admin
        if ($currentRole === 'super_admin' && $newRole === 'admin') {
            // Check if this is the last super admin
            if ($this->isLastSuperAdmin($admin->getId())) {
                $result['can_change'] = false;
                $result['reasons'][] = 'Cannot demote last super admin';
            }
        }

        // Only super admins can promote to super admin
        if ($newRole === 'super_admin' && $currentRole !== 'super_admin') {
            $currentAdmin = $this->getEntity($this->adminRepository, $this->getCurrentAdminId(), false);
            if ($currentAdmin === null || $currentAdmin->getRole() !== 'super_admin') {
                $result['can_change'] = false;
                $result['reasons'][] = 'Only super admins can promote to super admin';
            }
        }

        return $result;
    }
}