<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseAdmin Controller - Layer 4 (Presentation Layer)
 * 
 * Parent untuk SEMUA admin panel controllers dalam sistem DevDaily.
 * Mengasumsikan authentication/authorization sudah ditangani oleh Filter pipeline.
 * 
 * @package App\Controllers
 */
class BaseAdmin extends BaseController
{
    /**
     * Current admin data (dari Filter)
     * 
     * @var array|null
     */
    protected ?array $adminData = null;

    /**
     * Current admin ID (dari Filter)
     * 
     * @var int|null
     */
    protected ?int $adminId = null;

    /**
     * Current admin role (dari Filter)
     * 
     * @var string|null
     */
    protected ?string $adminRole = null;

    /**
     * Whether admin is super admin (dari Filter)
     * 
     * @var bool
     */
    protected bool $isSuperAdmin = false;

    /**
     * Admin permissions (dari Filter)
     * 
     * @var array
     */
    protected array $adminPermissions = [];

    /**
     * Admin sidebar menu (dari config atau service)
     * 
     * @var array
     */
    protected array $sidebarMenu = [];

    /**
     * Page title untuk admin panel
     * 
     * @var string|null
     */
    protected ?string $pageTitle = null;

    /**
     * Breadcrumbs untuk current page
     * 
     * @var array
     */
    protected array $breadcrumbs = [];

    /**
     * Constructor.
     */
    public function initController(
        RequestInterface $request, 
        ResponseInterface $response, 
        LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        
        // Load admin helpers
        helper(['admin', 'form', 'url']);
        
        // Get admin data dari Filter (diset oleh AdminSessionCheck)
        $this->adminData = $request->getAttribute('admin_data');
        $this->adminId = $request->getAttribute('admin_id');
        $this->adminRole = $request->getAttribute('admin_role');
        $this->isSuperAdmin = $request->getAttribute('is_super_admin') ?? false;
        $this->adminPermissions = $request->getAttribute('admin_permissions') ?? [];
        
        // Set default page title
        $this->pageTitle = 'Admin Panel';
        
        // Load sidebar menu dari config atau service
        $this->loadSidebarMenu();
    }

    // =====================================================================
    // ADMIN CONTEXT DATA ACCESSORS
    // =====================================================================

    /**
     * Get current admin ID.
     * 
     * @return int|null
     */
    protected function getAdminId(): ?int
    {
        return $this->adminId;
    }

    /**
     * Get current admin data.
     * 
     * @return array|null
     */
    protected function getAdminData(): ?array
    {
        return $this->adminData;
    }

    /**
     * Get current admin role.
     * 
     * @return string|null
     */
    protected function getAdminRole(): ?string
    {
        return $this->adminRole;
    }

    /**
     * Check jika current admin adalah super admin.
     * 
     * @return bool
     */
    protected function isAdminSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Get admin permissions.
     * 
     * @return array
     */
    protected function getAdminPermissions(): array
    {
        return $this->adminPermissions;
    }

    /**
     * Check jika admin memiliki permission tertentu.
     * 
     * @param string $permission
     * @return bool
     */
    protected function hasPermission(string $permission): bool
    {
        // Super admin punya semua permissions
        if ($this->isSuperAdmin) {
            return true;
        }
        
        // Check permission cache
        return in_array($permission, $this->adminPermissions);
    }

    /**
     * Require specific permission (throw exception jika tidak ada).
     * 
     * @param string $permission
     * @throws \App\Exceptions\AuthorizationException
     */
    protected function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            throw new \App\Exceptions\AuthorizationException(
                "Insufficient permission. Required: {$permission}"
            );
        }
    }

    // =====================================================================
    // ADMIN VIEW RENDERING
    // =====================================================================

    /**
     * Render admin view dengan admin layout.
     * 
     * @param string $view View name (tanpa extension, relative ke admin folder)
     * @param array $data Data untuk view
     * @param int $statusCode HTTP status code
     * @return ResponseInterface
     */
    protected function renderAdminView(
        string $view,
        array $data = [],
        int $statusCode = 200
    ): ResponseInterface {
        // Add admin context data ke semua views
        $data['admin'] = $this->adminData;
        $data['admin_id'] = $this->adminId;
        $data['admin_role'] = $this->adminRole;
        $data['is_super_admin'] = $this->isSuperAdmin;
        $data['admin_permissions'] = $this->adminPermissions;
        
        // Page title
        $data['page_title'] = $this->pageTitle;
        
        // Breadcrumbs
        $data['breadcrumbs'] = $this->breadcrumbs;
        
        // Sidebar menu (sudah difilter berdasarkan permissions)
        $data['sidebar_menu'] = $this->sidebarMenu;
        
        // Current route untuk active menu detection
        $data['current_route'] = service('router')->getMatchedRoute();
        
        // Flash messages
        $session = \Config\Services::session();
        $data['flash_messages'] = [
            'success' => $session->getFlashdata('success'),
            'error' => $session->getFlashdata('error'),
            'warning' => $session->getFlashdata('warning'),
            'info' => $session->getFlashdata('info'),
        ];
        
        // Render dengan admin layout
        return $this->renderView('admin/layout', [
            'content' => view("admin/{$view}", $data),
            'page_title' => $data['page_title'],
            'admin_context' => $data,
        ], $statusCode);
    }

    /**
     * Set page title untuk admin panel.
     * 
     * @param string $title
     * @param bool $appendAppName Jika true, append " | Admin Panel"
     * @return self
     */
    protected function setPageTitle(string $title, bool $appendAppName = true): self
    {
        $this->pageTitle = $appendAppName ? $title . ' | Admin Panel' : $title;
        return $this;
    }

    /**
     * Add breadcrumb item.
     * 
     * @param string $label Breadcrumb label
     * @param string|null $url Breadcrumb URL (null untuk current/active item)
     * @return self
     */
    protected function addBreadcrumb(string $label, ?string $url = null): self
    {
        $this->breadcrumbs[] = [
            'label' => $label,
            'url' => $url,
            'active' => $url === null,
        ];
        
        return $this;
    }

    /**
     * Set breadcrumbs array.
     * 
     * @param array $breadcrumbs
     * @return self
     */
    protected function setBreadcrumbs(array $breadcrumbs): self
    {
        $this->breadcrumbs = $breadcrumbs;
        return $this;
    }

    // =====================================================================
    // ADMIN UTILITIES
    // =====================================================================

    /**
     * Get admin URL helper.
     * 
     * @param string $path Path setelah admin/ (e.g., 'products/create')
     * @return string Full admin URL
     */
    protected function adminUrl(string $path = ''): string
    {
        $baseUrl = rtrim(base_url(), '/');
        return "{$baseUrl}/admin/" . ltrim($path, '/');
    }

    /**
     * Load sidebar menu dari config atau service.
     * 
     * @return void
     */
    protected function loadSidebarMenu(): void
    {
        try {
            // Coba dari config dulu
            $config = config('AdminMenu');
            $menuItems = $config->items ?? [];
            
            // Filter menu berdasarkan permissions
            $this->sidebarMenu = $this->filterMenuByPermissions($menuItems);
            
        } catch (\Exception $e) {
            log_message('error', 'Failed to load admin menu: ' . $e->getMessage());
            $this->sidebarMenu = [];
        }
    }

    /**
     * Filter menu items berdasarkan admin permissions.
     * 
     * @param array $menuItems
     * @return array
     */
    protected function filterMenuByPermissions(array $menuItems): array
    {
        $filteredMenu = [];
        
        foreach ($menuItems as $key => $item) {
            // Check jika item memiliki permission requirement
            if (isset($item['permission']) && !$this->hasPermission($item['permission'])) {
                continue; // Skip item ini
            }
            
            // Filter submenu jika ada
            if (isset($item['submenu']) && is_array($item['submenu'])) {
                $filteredSubmenu = [];
                
                foreach ($item['submenu'] as $subKey => $subItem) {
                    if (isset($subItem['permission']) && !$this->hasPermission($subItem['permission'])) {
                        continue; // Skip subitem ini
                    }
                    $filteredSubmenu[$subKey] = $subItem;
                }
                
                // Jika submenu kosong setelah filtering, hapus submenu
                if (!empty($filteredSubmenu)) {
                    $item['submenu'] = $filteredSubmenu;
                } else {
                    unset($item['submenu']);
                }
            }
            
            $filteredMenu[$key] = $item;
        }
        
        return $filteredMenu;
    }

    /**
     * Handle admin GET request dengan standard template.
     * 
     * @param callable $serviceCall Function yang memanggil service
     * @param array $viewConfig Konfigurasi view
     * @return ResponseInterface
     */
    protected function handleAdminGetRequest(callable $serviceCall, array $viewConfig = []): ResponseInterface
    {
        try {
            // Panggil service untuk mendapatkan data
            $data = $serviceCall();
            
            // Merge dengan view config
            $viewData = array_merge($data, $viewConfig);
            
            // Render view
            return $this->renderAdminView(
                $viewConfig['view'] ?? 'index',
                $viewData,
                $viewConfig['status_code'] ?? 200
            );
            
        } catch (\App\Exceptions\NotFoundException $e) {
            // Not found - render 404 page
            return $this->renderAdminView('errors/404', [
                'message' => $e->getMessage(),
                'title' => 'Not Found',
            ], 404);
            
        } catch (\App\Exceptions\AuthorizationException $e) {
            // Unauthorized - redirect ke login atau show error
            session()->setFlashdata('error', $e->getMessage());
            return redirect()->to($this->adminUrl('auth/login'));
            
        } catch (\Exception $e) {
            // Internal error - log dan show error page
            log_message('error', 'Admin GET request error: ' . $e->getMessage());
            
            return $this->renderAdminView('errors/500', [
                'message' => 'An error occurred while processing your request.',
                'title' => 'Internal Error',
            ], 500);
        }
    }

    /**
     * Handle admin POST/PUT/PATCH request dengan standard template.
     * 
     * @param callable $serviceCall Function yang memanggil service
     * @param string $successMessage Pesan sukses
     * @param string $redirectRoute Route untuk redirect setelah sukses
     * @param array $redirectParams Parameter untuk redirect URL
     * @return ResponseInterface
     */
    protected function handleAdminWriteRequest(
        callable $serviceCall,
        string $successMessage = 'Operation completed successfully',
        string $redirectRoute = '',
        array $redirectParams = []
    ): ResponseInterface {
        try {
            // Panggil service
            $result = $serviceCall();
            
            // Set flash success message
            session()->setFlashdata('success', $successMessage);
            
            // Prepare redirect URL
            $redirectUrl = $redirectRoute ? $this->adminUrl($redirectRoute) : null;
            
            if ($redirectUrl && !empty($redirectParams)) {
                $redirectUrl .= '?' . http_build_query($redirectParams);
            }
            
            // Redirect atau kembali ke halaman sebelumnya
            if ($redirectUrl) {
                return redirect()->to($redirectUrl);
            }
            
            return redirect()->back();
            
        } catch (\App\Exceptions\ValidationException $e) {
            // Validation error - kembali dengan errors dan input
            session()->setFlashdata('errors', $e->getErrors());
            session()->setFlashdata('error', 'Please correct the errors below.');
            
            return redirect()->back()->withInput();
            
        } catch (\App\Exceptions\AuthorizationException $e) {
            // Unauthorized - redirect ke login
            session()->setFlashdata('error', $e->getMessage());
            return redirect()->to($this->adminUrl('auth/login'));
            
        } catch (\Exception $e) {
            // Internal error - log dan kembali dengan error
            log_message('error', 'Admin write request error: ' . $e->getMessage());
            
            session()->setFlashdata('error', 'An error occurred: ' . $e->getMessage());
            return redirect()->back()->withInput();
        }
    }

    /**
     * Handle admin DELETE request.
     * 
     * @param callable $serviceCall
     * @param string $successMessage
     * @param string $redirectRoute
     * @return ResponseInterface
     */
    protected function handleAdminDeleteRequest(
        callable $serviceCall,
        string $successMessage = 'Item deleted successfully',
        string $redirectRoute = ''
    ): ResponseInterface {
        return $this->handleAdminWriteRequest($serviceCall, $successMessage, $redirectRoute);
    }

    /**
     * Handle admin bulk action request.
     * 
     * @param callable $serviceCall
     * @param string $successMessage
     * @param string $redirectRoute
     * @return ResponseInterface
     */
    protected function handleAdminBulkAction(
        callable $serviceCall,
        string $successMessage = 'Bulk action completed successfully',
        string $redirectRoute = ''
    ): ResponseInterface {
        return $this->handleAdminWriteRequest($serviceCall, $successMessage, $redirectRoute);
    }

    /**
     * Get request data untuk admin forms.
     * 
     * @return array
     */
    protected function getAdminRequestData(): array
    {
        $data = $this->request->getPost() ?? [];
        
        // Juga include files jika ada
        $files = $this->request->getFiles();
        if ($files) {
            $data['_files'] = $files;
        }
        
        return $this->sanitizeInput($data);
    }

    /**
     * Create DTO dari admin request data.
     * 
     * @template T
     * @param class-string<T> $dtoClass
     * @param array $additionalData
     * @return T
     */
    protected function createAdminDto(string $dtoClass, array $additionalData = [])
    {
        $requestData = $this->getAdminRequestData();
        $data = array_merge($requestData, $additionalData);
        
        // Tambahkan admin ID jika diperlukan
        if (!isset($data['admin_id']) && $this->adminId) {
            $data['admin_id'] = $this->adminId;
        }
        
        // Gunakan factory method dari BaseController
        return $this->createDtoFromRequest($dtoClass, $data);
    }
}