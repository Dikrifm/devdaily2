<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\CLI\CLI;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $username = 'admin';
        $email    = 'admin@devdaily.com';
        
        // 1. Cek User Existing (Idempotency Check)
        $existing = $this->db->table('admins')
                             ->where('username', $username)
                             ->orWhere('email', $email)
                             ->get()
                             ->getRow();

        if ($existing) {
            CLI::write("Admin user '{$username}' sudah ada. Seeder dilewati.", 'yellow');
            return;
        }

        // 2. Siapkan Data
        // Pastikan setiap baris di dalam [] diakhiri koma (,)
        $data = [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
            'name'          => 'Super Administrator',
            'role'          => 'super_admin',
            'active'        => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'), // Koma terakhir opsional tapi praktik yang baik (trailing comma)
        ];

        // 3. Insert ke Database
        $this->db->table('admins')->insert($data);

        // 4. Output Sukses
        CLI::write("Admin user berhasil dibuat!", 'green');
        CLI::write("Username: " . $username, 'white');
        CLI::write("Password: password123", 'white');
    }
}
