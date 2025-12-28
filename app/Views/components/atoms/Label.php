<?php
/**
 * Atom: Label
 * * Parameter:
 * - $for (string): ID input target (wajib)
 * - $text (string): Teks label (wajib)
 * - $required (bool): Apakah field ini wajib? (Default: false)
 * - $class (string): Tambahan class custom
 */

// Defaults
$for = $for ?? '';
$text = $text ?? 'Label Name';
$required = $required ?? false;
$extraClass = $class ?? '';

// Base Styling (Tailwind)
$baseClass = "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1";
?>

<label for="<?= $for ?>" class="<?= $baseClass ?> <?= $extraClass ?>">
    <?= esc($text) ?>
    <?php if ($required): ?>
        <span class="text-red-500">*</span>
    <?php endif; ?>
</label>
