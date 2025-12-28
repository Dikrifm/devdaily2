<?php
/**
 * Molecule: FormGroup
 * Menggabungkan Label, Input, dan Pesan Error.
 * * * Parameter:
 * - $name (string): Nama field (wajib)
 * - $label (string): Teks label (wajib)
 * - $value (string): Nilai field (untuk old input)
 * - $type (string): Tipe input (Default: text)
 * - $placeholder (string): Placeholder
 * - $required (bool): Wajib isi?
 * - $error (string|null): Pesan error validasi (jika ada)
 */

// Defaults
$name = $name ?? '';
$label = $label ?? '';
$type = $type ?? 'text';
$value = $value ?? '';
$error = $error ?? null;
$required = $required ?? false;
$placeholder = $placeholder ?? '';

// Tentukan apakah field ini sedang error
$isError = !empty($error);
?>

<div class="mb-4">
    <?= view('components/atoms/Label', [
        'for'      => $name,
        'text'     => $label,
        'required' => $required
    ]) ?>

    <div class="mt-1 relative">
        <?= view('components/atoms/Input', [
            'name'        => $name,
            'type'        => $type,
            'value'       => $value,
            'placeholder' => $placeholder,
            'required'    => $required,
            'isError'     => $isError
        ]) ?>
    </div>

    <?php if ($isError): ?>
        <p class="mt-2 text-sm text-red-600" id="<?= $name ?>-error">
            <?= esc($error) ?>
        </p>
    <?php endif; ?>
</div>
