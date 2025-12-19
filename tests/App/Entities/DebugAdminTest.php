<?php

namespace tests\App\Entities;

use App\Entities\Admin;

require_once __DIR__ . '/../../../vendor/autoload.php';

echo "=== DEBUG ADMIN ENTITY ===\n\n";

// Test 1: Constructor biasa
echo "Test 1: Membuat Admin biasa\n";
$admin = new Admin('john', 'john@email.com', 'John Doe');
echo "- Username: " . $admin->getUsername() . "\n";
echo "- Email: " . $admin->getEmail() . "\n";
echo "- Nama: " . $admin->getName() . "\n";
echo "- Role: " . $admin->getRole() . "\n";
echo "- Active: " . ($admin->isActive() ? 'Ya' : 'Tidak') . "\n\n";

// Test 2: canBeArchivedBy return apa?
echo "Test 2: canBeArchivedBy return apa?\n";
$result = $admin->canBeArchivedBy(1, 1);
echo "- Tipe data: " . gettype($result) . "\n";
echo "- Nilai: ";
print_r($result);
echo "\n";

// Test 3: validate() return apa?
echo "Test 3: validate() return apa?\n";
$errors = $admin->validate();
echo "- Tipe data: " . gettype($errors) . "\n";
echo "- Nilai: ";
print_r($errors);
echo "\n";

// Test 4: validate() dengan username kosong
echo "Test 4: validate() dengan username kosong\n";
$admin2 = new Admin('', 'test@email.com', 'Test');
$admin2->setUsername('');
$errors2 = $admin2->validate();
echo "- Tipe data: " . gettype($errors2) . "\n";
echo "- Nilai: ";
print_r($errors2);
echo "\n";

echo "=== SELESAI DEBUG ===\n";
