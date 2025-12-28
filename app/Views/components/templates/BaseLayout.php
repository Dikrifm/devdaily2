<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('title') ?> - DevDaily</title>
    
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
    
    <?= csrf_meta() ?>
</head>
<body class="bg-gray-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col">

    <main class="flex-grow">
        <?= $this->renderSection('content') ?>
    </main>

    <?= $this->renderSection('scripts') ?>
    
</body>
</html>
