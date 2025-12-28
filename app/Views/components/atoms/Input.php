<?php
/**
 * Atom: Input
 * * Parameter:
 * - $name (string): Nama field (wajib)
 * - $type (string): 'text' | 'email' | 'password' | 'number' (Default: 'text')
 * - $id (string): ID element (Default: sama dengan name)
 * - $value (string): Nilai input (Default: '')
 * - $placeholder (string): Placeholder text
 * - $required (bool): Apakah wajib diisi? (Default: false)
 * - $isError (bool): Apakah sedang error? (Default: false)
 * - $class (string): Tambahan class custom
 */

// Defaults
$name = $name ?? 'field_name';
$type = $type ?? 'text';
$id = $id ?? $name;
$value = $value ?? '';
$placeholder = $placeholder ?? '';
$required = $required ?? false;
$isError = $isError ?? false;
$extraClass = $class ?? '';

// Base Styling (Tailwind)
// Appearance-none, relative, block, width full, border styling
$baseClass = "appearance-none block w-full px-3 py-2 border rounded-md shadow-sm placeholder-gray-400 focus:outline-none sm:text-sm transition duration-150 ease-in-out";

// State Styling
// Normal: Border gray, Focus Blue
// Error: Border Red, Focus Red
if ($isError) {
    $stateClass = "border-red-300 text-red-900 focus:ring-red-500 focus:border-red-500 placeholder-red-300";
} else {
    $stateClass = "border-gray-300 focus:ring-blue-500 focus:border-blue-500";
}
?>

<input type="<?= $type ?>" 
       name="<?= $name ?>" 
       id="<?= $id ?>" 
       value="<?= esc($value) ?>" 
       class="<?= $baseClass ?> <?= $stateClass ?> <?= $extraClass ?>" 
       placeholder="<?= $placeholder ?>"
       <?= $required ? 'required' : '' ?>>
