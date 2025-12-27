<?php

namespace App\Services;

use App\DTOs\Requests\Admin\CreateAdminRequestDTO;
use App\DTOs\Requests\Admin\UpdateAdminRequestDTO;
use App\DTOs\Requests\Admin\ChangePasswordRequestDTO;
use App\DTOs\Requests\Admin\LoginRequestDTO;
use App\DTOs\Requests\Admin\BulkAdminActionRequestDTO;
use App\DTOs\Requests\Admin\AdminSearchRequestDTO;
use App\DTOs\Responses\AdminResponseDTO;
use App\DTOs\Responses\LoginResponseDTO;
use App\DTOs\Responses\BulkActionResultDTO;
use App\DTOs\Responses\AdminListResponseDTO;
use App\DTOs\Responses\AdminStatisticsResponseDTO;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Services\TransactionService;
use App\Services\AuthService;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;

/**
 * Admin Service - Business Orchestrator Layer for Administrator Operations
 * 
 * Responsibilities:
 * 1. Orchestrate business logic for admin management
 * 2. Manage database transactions for atomic operations
 * 3. Coordinate between repositories and validators
 * 4. Transform DTOs to Entities and vice versa
 * 5. Enforce business rules and validations
 * 
 * @package App\Services
 */
final class AdminService
{
    /**
     * Admin repository for data orchestration
     * 
     * @var AdminRepositoryInterface
     */
    private AdminRepositoryInterface $adminRepository;

    /**
     * Audit log repository for tracking
     * 
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * Transaction service for managing database transactions
     * 
     * @var TransactionService
     */
    private TransactionService $transactionService;

    /**
     * Auth service for authentication operations
     * 
     * @var AuthService
     */
    private AuthService $authService;

    /**
     * Current authenticated admin ID (set during authentication)
     * 
     * @var int|null
     */
    private ?int $currentAdminId = null;

    /**
     * Constructor with dependency injection
     * 
     * @param AdminRepositoryInterface $adminRepository
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param TransactionService $transactionService
     * @param AuthService $authService
     */
    public function __construct(
        AdminRepositoryInterface $adminRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        TransactionService $transactionService,
        AuthService $authService
    ) {
        $this->adminRepository = $adminRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->transactionService = $transactionService;
        $this->authService = $authService;
    }

    /**
     * Set current admin ID for audit trail
     * Called by AuthService after successful authentication
     * 
     * @param int $adminId
     * @return self
     */
    public function setCurrentAdmin(int $adminId): self
    {
        $this->currentAdminId = $adminId;
        return $this;
    }

    /**
     * Get current admin ID
     * 
     * @return int|null
     */
    public function getCurrentAdminId(): ?int
    {
        return $this->currentAdminId;
    }

    // ============================================
    // AUTHENTICATION METHODS
    // ============================================

    /**
     * Authenticate admin credentials
     * 
     * @param LoginRequestDTO $loginDto
     * @return LoginResponseDTO
     * @throws ValidationException If credentials are invalid
     * @throws AuthorizationException If account is locked
     */
    public function authenticate(LoginRequestDTO $loginDto): LoginResponseDTO
    {
        // Start transaction
        $transaction = $this->transactionService->begin();
        
        try {
            // Verify credentials
            $admin = $this->adminRepository->verifyCredentials(
                $loginDto->usernameOrEmail,
                $loginDto->password
            );
            
            if ($admin === null) {
                // Log failed attempt if we can identify the admin
                $adminForLog = $this->adminRepository->findByUsernameOrEmail(
                    $loginDto->usernameOrEmail,
                    false
                );
                
                if ($adminForLog) {
                    $this->adminRepository->recordFailedLogin(
                        $adminForLog->getId(),
                        $loginDto->ipAddress
                    );
                    
                    // Check if account is now locked
                    if ($this->adminRepository->isAccountLocked($adminForLog->getId())) {
                        throw new AuthorizationException(
                            'Account is locked due to too many failed login attempts. Please contact administrator.',
                            'ACCOUNT_LOCKED'
                        );
                    }
                }
                
                throw new ValidationException(
                    'Invalid username/email or password.',
                    'INVALID_CREDENTIALS'
                );
            }
            
            // Check if account is active
            if (!$admin->isActive()) {
                throw new AuthorizationException(
                    'Account is inactive. Please contact administrator.',
                    'ACCOUNT_INACTIVE'
                );
            }
            
            // Record successful login
            $this->adminRepository->recordSuccessfulLogin(
                $admin->getId(),
                $loginDto->ipAddress,
                $loginDto->userAgent
            );
            
            // Set current admin for audit trail
            $this->setCurrentAdmin($admin->getId());
            
            // Generate session/token (handled by AuthService)
            $sessionData = $this->authService->createAdminSession($admin);
            
            $transaction->commit();
            
            // Return response DTO
            return new LoginResponseDTO([
                'admin' => $this->transformAdminToResponse($admin),
                'session_token' => $sessionData['token'],
                'expires_at' => $sessionData['expires_at'],
                'message' => 'Login successful'
            ]);
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Authentication failed: " . $e->getMessage());
            throw new DomainException('Authentication service temporarily unavailable.', 'SERVICE_UNAVAILABLE');
        }
    }

    /**
     * Logout admin
     * 
     * @param int $adminId
     * @param string|null $ipAddress
     * @return bool
     */
    public function logout(int $adminId, ?string $ipAddress = null): bool
    {
        try {
            $this->authService->destroyAdminSession($adminId);
            
            // Log logout action
            $this->auditLogRepository->insert(
                $this->createAuditLog(
                    'logout',
                    'Admin',
                    $adminId,
                    $adminId,
                    ['ip_address' => $ipAddress]
                )
            );
            
            return true;
        } catch (\Exception $e) {
            log_message('error', "Logout failed for admin {$adminId}: " . $e->getMessage());
            return false;
        }
    }

    // ============================================
    // ADMIN CRUD OPERATIONS
    // ============================================

    /**
     * Create new admin
     * 
     * @param CreateAdminRequestDTO $createDto
     * @return AdminResponseDTO
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules violated
     */
    public function createAdmin(CreateAdminRequestDTO $createDto): AdminResponseDTO
    {
        // Validate permission (only super admins can create admins)
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        $transaction = $this->transactionService->begin();
        
        try {
            // Check if username already exists
            if ($this->adminRepository->usernameExists($createDto->username)) {
                throw new ValidationException(
                    'Username already exists.',
                    'USERNAME_EXISTS'
                );
            }
            
            // Check if email already exists
            if ($this->adminRepository->emailExists($createDto->email)) {
                throw new ValidationException(
                    'Email already exists.',
                    'EMAIL_EXISTS'
                );
            }
            
            // Create admin entity
            $admin = new \App\Entities\Admin(
                $createDto->username,
                $createDto->email,
                $createDto->name
            );
            
            // Set additional properties
            $admin->setRole($createDto->role ?? 'admin');
            $admin->setActive($createDto->active ?? true);
            
            // Set password with hashing
            if ($createDto->password) {
                $admin->setPasswordWithHash($createDto->password);
            } else {
                // Generate random password
                $randomPassword = bin2hex(random_bytes(8));
                $admin->setPasswordWithHash($randomPassword);
                $generatedPassword = $randomPassword; // For audit log
            }
            
            // Save admin
            if (!$this->adminRepository->save($admin)) {
                throw new DomainException('Failed to create admin.', 'CREATE_FAILED');
            }
            
            // Log the action
            $this->auditLogRepository->insert(
                $this->createAuditLog(
                    'create',
                    'Admin',
                    $admin->getId(),
                    $this->currentAdminId,
                    [
                        'username' => $admin->getUsername(),
                        'email' => $admin->getEmail(),
                        'role' => $admin->getRole(),
                        'password_generated' => isset($generatedPassword)
                    ]
                )
            );
            
            $transaction->commit();
            
            return new AdminResponseDTO(
                $this->transformAdminToResponse($admin)
            );
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Create admin failed: " . $e->getMessage());
            throw new DomainException('Failed to create admin due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Update admin
     * 
     * @param UpdateAdminRequestDTO $updateDto
     * @return AdminResponseDTO
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws NotFoundException
     */
    public function updateAdmin(UpdateAdminRequestDTO $updateDto): AdminResponseDTO
    {
        // Get existing admin
        $admin = $this->adminRepository->find($updateDto->id);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        // Check permission (can update self or need super admin for others)
        if ($admin->getId() !== $this->currentAdminId) {
            $this->validateSuperAdminPermission($this->currentAdminId);
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $oldValues = $admin->toArray();
            $changes = [];
            
            // Update fields if provided
            if ($updateDto->username !== null && $updateDto->username !== $admin->getUsername()) {
                if ($this->adminRepository->usernameExists($updateDto->username, $admin->getId())) {
                    throw new ValidationException('Username already exists.', 'USERNAME_EXISTS');
                }
                $admin->setUsername($updateDto->username);
                $changes['username'] = ['old' => $oldValues['username'], 'new' => $updateDto->username];
            }
            
            if ($updateDto->email !== null && $updateDto->email !== $admin->getEmail()) {
                if ($this->adminRepository->emailExists($updateDto->email, $admin->getId())) {
                    throw new ValidationException('Email already exists.', 'EMAIL_EXISTS');
                }
                $admin->setEmail($updateDto->email);
                $changes['email'] = ['old' => $oldValues['email'], 'new' => $updateDto->email];
            }
            
            if ($updateDto->name !== null && $updateDto->name !== $admin->getName()) {
                $admin->setName($updateDto->name);
                $changes['name'] = ['old' => $oldValues['name'], 'new' => $updateDto->name];
            }
            
            // Role changes require super admin permission
            if ($updateDto->role !== null && $updateDto->role !== $admin->getRole()) {
                $this->validateSuperAdminPermission($this->currentAdminId);
                
                // Check if demoting last super admin
                if ($admin->isSuperAdmin() && $updateDto->role === 'admin') {
                    if ($this->adminRepository->isLastActiveSuperAdmin($admin->getId())) {
                        throw new DomainException(
                            'Cannot demote the last active super admin.',
                            'LAST_SUPER_ADMIN'
                        );
                    }
                }
                
                $admin->setRole($updateDto->role);
                $changes['role'] = ['old' => $oldValues['role'], 'new' => $updateDto->role];
            }
            
            // Status changes require super admin permission
            if ($updateDto->active !== null && $updateDto->active !== $admin->isActive()) {
                if ($admin->getId() !== $this->currentAdminId) {
                    $this->validateSuperAdminPermission($this->currentAdminId);
                }
                
                $admin->setActive($updateDto->active);
                $changes['active'] = ['old' => $oldValues['active'], 'new' => $updateDto->active];
            }
            
            // Save if there are changes
            if (!empty($changes)) {
                if (!$this->adminRepository->save($admin)) {
                    throw new DomainException('Failed to update admin.', 'UPDATE_FAILED');
                }
                
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'update',
                        'Admin',
                        $admin->getId(),
                        $this->currentAdminId,
                        ['changes' => $changes]
                    )
                );
            }
            
            $transaction->commit();
            
            return new AdminResponseDTO(
                $this->transformAdminToResponse($admin)
            );
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Update admin failed: " . $e->getMessage());
            throw new DomainException('Failed to update admin due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Get admin by ID
     * 
     * @param int $adminId
     * @return AdminResponseDTO
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getAdmin(int $adminId): AdminResponseDTO
    {
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        // Check permission (can view self or need super admin for others)
        if ($admin->getId() !== $this->currentAdminId) {
            $this->validateSuperAdminPermission($this->currentAdminId);
        }
        
        return new AdminResponseDTO(
            $this->transformAdminToResponse($admin)
        );
    }

    /**
     * Delete/Archive admin
     * 
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function deleteAdmin(int $adminId): bool
    {
        // Get existing admin
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        // Check permission (cannot delete self, need super admin for others)
        if ($admin->getId() === $this->currentAdminId) {
            throw new AuthorizationException('Cannot delete your own account.', 'SELF_DELETION');
        }
        
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        $transaction = $this->transactionService->begin();
        
        try {
            // Check if can be archived
            $canArchive = $this->adminRepository->canBeArchived($adminId, $this->currentAdminId);
            if (!$canArchive['can']) {
                throw new DomainException($canArchive['reason'], 'ARCHIVE_VALIDATION_FAILED');
            }
            
            // Archive with validation
            $success = $this->adminRepository->archiveWithValidation($adminId, $this->currentAdminId);
            
            if ($success) {
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'archive',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['username' => $admin->getUsername(), 'email' => $admin->getEmail()]
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Delete admin failed: " . $e->getMessage());
            throw new DomainException('Failed to delete admin due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Restore archived admin
     * 
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function restoreAdmin(int $adminId): bool
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        $transaction = $this->transactionService->begin();
        
        try {
            // Check if admin exists (including archived)
            $admin = $this->adminRepository->find($adminId, false);
            if (!$admin) {
                throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
            }
            
            // Restore using model directly (since repository doesn't have restore method)
            $model = $this->adminRepository->getModel();
            $success = $model->restore($adminId);
            
            if ($success) {
                // Activate account
                $this->adminRepository->activate($adminId);
                
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'restore',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['username' => $admin->getUsername(), 'email' => $admin->getEmail()]
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Restore admin failed: " . $e->getMessage());
            throw new DomainException('Failed to restore admin due to database error.', 'DATABASE_ERROR');
        }
    }

    // ============================================
    // PASSWORD & SECURITY OPERATIONS
    // ============================================

    /**
     * Change password
     * 
     * @param ChangePasswordRequestDTO $changeDto
     * @return bool
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function changePassword(ChangePasswordRequestDTO $changeDto): bool
    {
        // Verify current password
        if (!$this->adminRepository->verifyPassword($changeDto->adminId, $changeDto->currentPassword)) {
            throw new ValidationException('Current password is incorrect.', 'INVALID_CURRENT_PASSWORD');
        }
        
        // Check permission (can change own password or need super admin for others)
        if ($changeDto->adminId !== $this->currentAdminId) {
            $this->validateSuperAdminPermission($this->currentAdminId);
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $success = $this->adminRepository->updatePassword($changeDto->adminId, $changeDto->newPassword);
            
            if ($success) {
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'password_change',
                        'Admin',
                        $changeDto->adminId,
                        $this->currentAdminId,
                        ['action' => 'Password changed']
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Change password failed: " . $e->getMessage());
            throw new DomainException('Failed to change password due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Reset password (by super admin)
     * 
     * @param int $adminId
     * @return string Generated password
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function resetPassword(int $adminId): string
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        // Get admin
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            // Generate random password
            $newPassword = bin2hex(random_bytes(8));
            
            $success = $this->adminRepository->updatePassword($adminId, $newPassword);
            
            if ($success) {
                // Reset login attempts
                $this->adminRepository->resetLoginAttempts($adminId);
                
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'password_reset',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['action' => 'Password reset by administrator']
                    )
                );
            }
            
            $transaction->commit();
            return $newPassword;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Reset password failed: " . $e->getMessage());
            throw new DomainException('Failed to reset password due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Unlock admin account
     * 
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function unlockAccount(int $adminId): bool
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        // Get admin
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $success = $this->adminRepository->unlockAccount($adminId);
            
            if ($success) {
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'account_unlock',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['action' => 'Account unlocked by administrator']
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Unlock account failed: " . $e->getMessage());
            throw new DomainException('Failed to unlock account due to database error.', 'DATABASE_ERROR');
        }
    }

    // ============================================
    // ROLE MANAGEMENT OPERATIONS
    // ============================================

    /**
     * Promote admin to super admin
     * 
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function promoteToSuperAdmin(int $adminId): bool
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        // Get admin
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        // Already a super admin
        if ($admin->isSuperAdmin()) {
            return true;
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $success = $this->adminRepository->promoteToSuperAdmin($adminId);
            
            if ($success) {
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'role_change',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['new_role' => 'super_admin']
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Promote to super admin failed: " . $e->getMessage());
            throw new DomainException('Failed to promote admin due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Demote super admin to regular admin
     * 
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function demoteToRegularAdmin(int $adminId): bool
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        // Get admin
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new NotFoundException('Admin not found.', 'ADMIN_NOT_FOUND');
        }
        
        // Already a regular admin
        if ($admin->isRegularAdmin()) {
            return true;
        }
        
        // Check if last active super admin
        if ($this->adminRepository->isLastActiveSuperAdmin($adminId)) {
            throw new DomainException(
                'Cannot demote the last active super admin.',
                'LAST_SUPER_ADMIN'
            );
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $success = $this->adminRepository->demoteToRegularAdmin($adminId);
            
            if ($success) {
                // Log the action
                $this->auditLogRepository->insert(
                    $this->createAuditLog(
                        'role_change',
                        'Admin',
                        $adminId,
                        $this->currentAdminId,
                        ['new_role' => 'admin']
                    )
                );
            }
            
            $transaction->commit();
            return $success;
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Demote to regular admin failed: " . $e->getMessage());
            throw new DomainException('Failed to demote admin due to database error.', 'DATABASE_ERROR');
        }
    }

    // ============================================
    // BULK OPERATIONS
    // ============================================

    /**
     * Bulk update admin statuses
     * 
     * @param BulkAdminActionRequestDTO $bulkDto
     * @return BulkActionResultDTO
     * @throws AuthorizationException
     */
    public function bulkUpdateStatus(BulkAdminActionRequestDTO $bulkDto): BulkActionResultDTO
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        if (empty($bulkDto->adminIds)) {
            return new BulkActionResultDTO([
                'success' => true,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'message' => 'No admins selected'
            ]);
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $processed = count($bulkDto->adminIds);
            $succeeded = 0;
            $failedIds = [];
            
            foreach ($bulkDto->adminIds as $adminId) {
                try {
                    // Skip if trying to modify self
                    if ($adminId === $this->currentAdminId) {
                        $failedIds[] = $adminId;
                        continue;
                    }
                    
                    // Update status
                    if ($bulkDto->action === 'activate') {
                        $success = $this->adminRepository->activate($adminId);
                    } else {
                        $success = $this->adminRepository->deactivate($adminId);
                    }
                    
                    if ($success) {
                        $succeeded++;
                        
                        // Log individual action
                        $this->auditLogRepository->insert(
                            $this->createAuditLog(
                                'status_change',
                                'Admin',
                                $adminId,
                                $this->currentAdminId,
                                [
                                    'action' => 'bulk_' . $bulkDto->action,
                                    'new_status' => $bulkDto->action === 'activate' ? 'active' : 'inactive'
                                ]
                            )
                        );
                    } else {
                        $failedIds[] = $adminId;
                    }
                } catch (\Exception $e) {
                    $failedIds[] = $adminId;
                    log_message('error', "Bulk status update failed for admin {$adminId}: " . $e->getMessage());
                }
            }
            
            // Log bulk action summary
            $this->auditLogRepository->insert(
                $this->createAuditLog(
                    'bulk_operation',
                    'Admin',
                    0,
                    $this->currentAdminId,
                    [
                        'action' => 'bulk_status_update',
                        'target_action' => $bulkDto->action,
                        'total_processed' => $processed,
                        'succeeded' => $succeeded,
                        'failed' => count($failedIds),
                        'failed_ids' => $failedIds
                    ]
                )
            );
            
            $transaction->commit();
            
            return new BulkActionResultDTO([
                'success' => $succeeded > 0,
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => count($failedIds),
                'failed_ids' => $failedIds,
                'message' => "Processed {$processed} admins, {$succeeded} succeeded, " . count($failedIds) . " failed"
            ]);
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Bulk status update failed: " . $e->getMessage());
            throw new DomainException('Failed to bulk update statuses due to database error.', 'DATABASE_ERROR');
        }
    }

    /**
     * Bulk delete/archive admins
     * 
     * @param BulkAdminActionRequestDTO $bulkDto
     * @return BulkActionResultDTO
     * @throws AuthorizationException
     */
    public function bulkDeleteAdmins(BulkAdminActionRequestDTO $bulkDto): BulkActionResultDTO
    {
        $this->validateSuperAdminPermission($this->currentAdminId);
        
        if (empty($bulkDto->adminIds)) {
            return new BulkActionResultDTO([
                'success' => true,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'message' => 'No admins selected'
            ]);
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $processed = count($bulkDto->adminIds);
            $succeeded = 0;
            $failedIds = [];
            
            foreach ($bulkDto->adminIds as $adminId) {
                try {
                    // Skip if trying to delete self
                    if ($adminId === $this->currentAdminId) {
                        $failedIds[] = $adminId;
                        continue;
                    }
                    
                    // Check if can be archived
                    $canArchive = $this->adminRepository->canBeArchived($adminId, $this->currentAdminId);
                    if (!$canArchive['can']) {
                        $failedIds[] = $adminId;
                        continue;
                    }
                    
                    // Archive with validation
                    $success = $this->adminRepository->archiveWithValidation($adminId, $this->currentAdminId);
                    
                    if ($success) {
                        $succeeded++;
                    } else {
                        $failedIds[] = $adminId;
                    }
                } catch (\Exception $e) {
                    $failedIds[] = $adminId;
                    log_message('error', "Bulk delete failed for admin {$adminId}: " . $e->getMessage());
                }
            }
            
            // Log bulk action summary
            $this->auditLogRepository->insert(
                $this->createAuditLog(
                    'bulk_operation',
                    'Admin',
                    0,
                    $this->currentAdminId,
                    [
                        'action' => 'bulk_delete',
                        'total_processed' => $processed,
                        'succeeded' => $succeeded,
                        'failed' => count($failedIds),
                        'failed_ids' => $failedIds
                    ]
                )
            );
            
            $transaction->commit();
            
            return new BulkActionResultDTO([
                'success' => $succeeded > 0,
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => count($failedIds),
                'failed_ids' => $failedIds,
                'message' => "Processed {$processed} admins, {$succeeded} archived, " . count($failedIds) . " failed"
            ]);
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "Bulk delete failed: " . $e->getMessage());
            throw new DomainException('Failed to bulk delete admins due to database error.', 'DATABASE_ERROR');
        }
    }

    // ============================================
    // SEARCH & LISTING OPERATIONS
    // ============================================

    /**
     * Search admins with pagination
     * 
     * @param AdminSearchRequestDTO $searchDto
     * @return AdminListResponseDTO
     */
    public function searchAdmins(AdminSearchRequestDTO $searchDto): AdminListResponseDTO
    {
        // Build search criteria
        $criteria = [];
        
        if ($searchDto->searchTerm) {
            // Search in name, username, or email
            $admins = $this->adminRepository->search($searchDto->searchTerm, $searchDto->limit);
        } else {
            // Build conditions
            if ($searchDto->role) {
                $criteria['role'] = $searchDto->role;
            }
            
            if ($searchDto->active !== null) {
                $criteria['active'] = $searchDto->active;
            }
            
            // Always exclude soft-deleted unless explicitly requested
            if ($searchDto->includeArchived !== true) {
                $criteria['deleted_at'] = null;
            }
            
            $admins = $this->adminRepository->findAll(
                $criteria,
                $searchDto->limit,
                $searchDto->offset
            );
        }
        
        // Transform to response DTOs
        $adminDtos = array_map(
            fn($admin) => $this->transformAdminToResponse($admin),
            $admins
        );
        
        // Get total count for pagination
        $total = $this->adminRepository->count($criteria);
        
        return new AdminListResponseDTO([
            'admins' => $adminDtos,
            'total' => $total,
            'limit' => $searchDto->limit,
            'offset' => $searchDto->offset,
            'has_more' => ($searchDto->offset + $searchDto->limit) < $total
        ]);
    }

    /**
     * Get admin statistics
     * 
     * @return AdminStatisticsResponseDTO
     */
    public function getStatistics(): AdminStatisticsResponseDTO
    {
        $stats = $this->adminRepository->getStatistics();
        $activityStats = $this->adminRepository->getActivityStatistics(30);
        $roleDistribution = $this->adminRepository->getRoleDistribution();
        
        return new AdminStatisticsResponseDTO([
            'total_admins' => $stats['total_admins'] ?? 0,
            'active_admins' => $stats['active_admins'] ?? 0,
            'super_admins' => $stats['super_admins'] ?? 0,
            'never_logged_in' => $stats['never_logged_in'] ?? 0,
            'locked_accounts' => $stats['locked_accounts'] ?? 0,
            'avg_days_since_login' => $stats['avg_days_since_login'] ?? 0,
            'recent_activity' => $activityStats,
            'role_distribution' => $roleDistribution,
            'generated_at' => Time::now()->toDateTimeString()
        ]);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Transform Admin entity to response array
     * 
     * @param \App\Entities\Admin $admin
     * @return array
     */
    private function transformAdminToResponse(\App\Entities\Admin $admin): array
    {
        return [
            'id' => $admin->getId(),
            'username' => $admin->getUsername(),
            'email' => $admin->getEmail(),
            'name' => $admin->getName(),
            'role' => $admin->getRole(),
            'role_label' => $admin->getRoleLabel(),
            'active' => $admin->isActive(),
            'status_label' => $admin->getStatusLabel(),
            'last_login' => $admin->getLastLogin() ? $admin->getLastLogin()->format('Y-m-d H:i:s') : null,
            'formatted_last_login' => $admin->getFormattedLastLogin(),
            'login_attempts' => $admin->getLoginAttempts(),
            'is_super_admin' => $admin->isSuperAdmin(),
            'is_regular_admin' => $admin->isRegularAdmin(),
            'is_locked' => $admin->isLocked(),
            'is_recently_active' => $admin->isRecentlyActive(),
            'initials' => $admin->getInitials(),
            'created_at' => $admin->getCreatedAt() ? $admin->getCreatedAt()->format('Y-m-d H:i:s') : null,
            'updated_at' => $admin->getUpdatedAt() ? $admin->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            'can_be_archived' => $admin->canBeArchivedBy($this->currentAdminId ?? 0, 1)['can']
        ];
    }

    /**
     * Create audit log entity
     * 
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param int|null $adminId
     * @param array $data
     * @return \App\Entities\AuditLog
     */
    private function createAuditLog(
        string $actionType,
        string $entityType,
        int $entityId,
        ?int $adminId,
        array $data = []
    ): \App\Entities\AuditLog {
        $auditLog = new \App\Entities\AuditLog($actionType, $entityType, $entityId);
        $auditLog->setAdminId($adminId);
        
        if (!empty($data)) {
            $auditLog->setChangesSummary(json_encode($data, JSON_PRETTY_PRINT));
        }
        
        // Set request info if available
        $request = service('request');
        if ($request) {
            $auditLog->setIpAddress($request->getIPAddress());
            $auditLog->setUserAgent($request->getUserAgent()->getAgentString());
        }
        
        return $auditLog;
    }

    /**
     * Validate super admin permission
     * 
     * @param int|null $adminId
     * @throws AuthorizationException If not super admin
     */
    private function validateSuperAdminPermission(?int $adminId): void
    {
        if ($adminId === null) {
            throw new AuthorizationException('Authentication required.', 'UNAUTHENTICATED');
        }
        
        $admin = $this->adminRepository->find($adminId);
        if (!$admin || !$admin->isSuperAdmin()) {
            throw new AuthorizationException(
                'Super administrator privileges required.',
                'SUPER_ADMIN_REQUIRED'
            );
        }
    }

    /**
     * Initialize system admin if not exists
     * This should be called during system setup
     * 
     * @param string $username
     * @param string $email
     * @param string $password
     * @return AdminResponseDTO
     */
    public function initializeSystemAdmin(string $username, string $email, string $password): AdminResponseDTO
    {
        // Check if system admin already exists
        if ($this->adminRepository->systemAdminExists()) {
            $systemAdmin = $this->adminRepository->getSystemAdmin();
            return new AdminResponseDTO(
                $this->transformAdminToResponse($systemAdmin)
            );
        }
        
        $transaction = $this->transactionService->begin();
        
        try {
            $systemAdmin = $this->adminRepository->initializeSystemAdmin($username, $email, $password);
            
            $transaction->commit();
            
            return new AdminResponseDTO(
                $this->transformAdminToResponse($systemAdmin)
            );
            
        } catch (DatabaseException $e) {
            $transaction->rollback();
            log_message('error', "System admin initialization failed: " . $e->getMessage());
            throw new DomainException('Failed to initialize system admin.', 'SYSTEM_INIT_FAILED');
        }
    }

    /**
     * Health check for admin service
     * 
     * @return array
     */
    public function healthCheck(): array
    {
        $checks = [];
        
        try {
            // Check database connection via repository
            $stats = $this->adminRepository->getStatistics();
            $checks['database'] = ['status' => 'healthy', 'total_admins' => $stats['total_admins'] ?? 0];
        } catch (\Exception $e) {
            $checks['database'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
        
        try {
            // Check system admin exists
            $systemAdminExists = $this->adminRepository->systemAdminExists();
            $checks['system_admin'] = ['status' => $systemAdminExists ? 'exists' : 'missing'];
        } catch (\Exception $e) {
            $checks['system_admin'] = ['status' => 'error', 'error' => $e->getMessage()];
        }
        
        // Overall status
        $unhealthy = array_filter($checks, fn($check) => in_array($check['status'], ['unhealthy', 'error']));
        
        return [
            'healthy' => empty($unhealthy),
            'checks' => $checks,
            'timestamp' => Time::now()->toDateTimeString()
        ];
    }
}