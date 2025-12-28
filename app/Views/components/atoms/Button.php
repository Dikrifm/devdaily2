<?php
/**
 * Atom: Button
 * * Parameter:
 * - $label (string): Teks tombol
 * - $type (string): 'submit' | 'button' | 'reset' (Default: 'submit')
 * - $variant (string): 'primary' | 'secondary' | 'danger' | 'outline' (Default: 'primary')
 * - $width (string): 'w-full' | 'w-auto' (Default: 'w-full')
 * - $class (string): Tambahan class custom
 */

// Defaults
$type = $type ?? 'submit';
$variant = $variant ?? 'primary';
$width = $width ?? 'w-full';
$label = $label ?? 'Submit';
$extraClass = $class ?? '';

// Base Classes (Tailwind)
// Flex, centering, padding, rounded corners, transition effects
$baseClass = "flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition duration-150 ease-in-out disabled:opacity-50 disabled:cursor-not-allowed shadow-sm";

// Variant Logic
$variantClasses = [
    'primary'   => 'text-white bg-blue-600 hover:bg-blue-700 focus:ring-blue-500',
    'secondary' => 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50 focus:ring-indigo-500',
    'danger'    => 'text-white bg-red-600 hover:bg-red-700 focus:ring-red-500',
    'outline'   => 'text-blue-600 bg-transparent border-blue-600 hover:bg-blue-50 focus:ring-blue-500',
];

$selectedVariant = $variantClasses[$variant] ?? $variantClasses['primary'];
?>

<button type="<?= $type ?>" 
        class="<?= $baseClass ?> <?= $selectedVariant ?> <?= $width ?> <?= $extraClass ?>">
    <?= $label ?>
</button>
