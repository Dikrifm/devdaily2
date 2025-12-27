<?php
// Default values untuk mencegah error undefined variable
$type = $type ?? 'button'; // button, submit, reset
$variant = $variant ?? 'primary'; // primary, secondary, glass, danger
$class = $class ?? '';
$label = $label ?? 'Button';
$onclick = $onclick ?? '';

// Style logic sederhana
$baseClass = "px-4 py-2 rounded-lg font-medium transition-all duration-200 focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed";

$variants = [
    'primary'   => 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500',
    'secondary' => 'bg-gray-200 hover:bg-gray-300 text-gray-800 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-200',
    'glass'     => 'glass text-white hover:bg-white/20', // Menggunakan utility custom .glass
    'danger'    => 'bg-red-500 hover:bg-red-600 text-white focus:ring-red-500',
];

$finalClass = "{$baseClass} {$variants[$variant]} {$class}";
?>

<button type="<?= $type ?>" class="<?= $finalClass ?>" onclick="<?= $onclick ?>">
    <?= $label ?>
</button>
