<?php

namespace App\Controllers;

use App\DTOs\Requests\Auth\LoginRequest;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * AUTH CONTROLLER - SIMPLE & FOCUSED
 * Extends: BaseController (cukup!)
 */
class Auth extends BaseController
{
    /**
     * GET /login
     */
    public function login()
    {
        // Jika sudah login, redirect
        if ($this->authService && $this->authService->isLoggedIn()) {
            return $this->redirectBasedOnRole();
        }
        
        return $this->renderView('auth/login', [
            'title' => 'Login'
        ]);
    }
    
    /**
     * POST /login  
     */
    public function attemptLogin(): RedirectResponse
    {
        try {
            // 1. Create DTO (validation happens here)
            $dto = LoginRequest::fromRequest(
                $this->request->getPost(),
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString()
            );
            
            // 2. Call Service
            $result = $this->authService->login(
                $dto->getIdentifier(),
                $dto->getPassword(),
                $dto->getRememberMe()
            );
            
            // 3. Redirect
            if ($result['success']) {
                session()->setFlashdata('success', 'Login successful');
                return $this->redirectBasedOnRole($result['user']['role'] ?? null);
            }
            
            session()->setFlashdata('error', $result['message'] ?? 'Login failed');
            return redirect()->to('/login')->withInput();
            
        } catch (\Exception $e) {
            session()->setFlashdata('error', 'An error occurred');
            return redirect()->to('/login');
        }
    }
    
    /**
     * POST /logout
     */
    public function logout(): RedirectResponse
    {
        $this->authService->logout($this->getCurrentUserId());
        session()->setFlashdata('success', 'Logged out');
        return redirect()->to('/');
    }
    
    /**
     * Helper: Redirect based on role
     */
    private function redirectBasedOnRole(?string $role = null): RedirectResponse
    {
        $redirectMap = [
            'admin' => '/admin/dashboard',
            'super_admin' => '/admin/dashboard',
            'user' => '/dashboard',
        ];
        
        $url = $redirectMap[$role] ?? '/';
        return redirect()->to($url);
    }
}