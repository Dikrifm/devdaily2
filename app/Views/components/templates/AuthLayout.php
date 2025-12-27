<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Auth' ?></title>
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-gray-50 dark:bg-dark min-h-screen flex items-center justify-center p-4 font-sans antialiased">
    
    <?= $this->renderSection('content') ?>

</body>
</html>
