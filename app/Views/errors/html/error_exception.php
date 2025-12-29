<?php
/**
 * Custom Exception Handler View
 * Menggunakan styling minimalis agar tetap ringan saat crash.
 */
$errorId = uniqid('error', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Error - DevDaily Debug</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Inline CSS Critical untuk halaman Error (Tanpa load external file agar aman saat network down) */
        :root { --bg: #f8fafc; --text: #334155; --red: #ef4444; --red-bg: #fee2e2; --code-bg: #1e293b; }
        body { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; background: var(--bg); color: var(--text); margin: 0; padding: 2rem; line-height: 1.5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: #fff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border-left: 6px solid var(--red); margin-bottom: 2rem; }
        .type { color: var(--red); font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.875rem; }
        h1 { margin: 0.5rem 0; font-size: 1.5rem; color: #0f172a; }
        .path { color: #64748b; font-size: 0.875rem; break-all: break-all; }
        .trace-box { background: #fff; border-radius: 0.5rem; overflow: hidden; box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1); }
        .trace-header { background: #f1f5f9; padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; font-weight: bold; font-size: 0.875rem; color: #475569; }
        .trace-item { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; gap: 1rem; font-size: 0.85rem; }
        .trace-item:last-child { border-bottom: none; }
        .trace-num { color: #94a3b8; min-width: 20px; }
        .trace-file { font-weight: 600; color: #334155; }
        .trace-func { color: #059669; }
        .args { color: #64748b; font-size: 0.75em; margin-top: 0.25rem; display: block; }
        pre { background: var(--code-bg); color: #e2e8f0; padding: 1rem; border-radius: 0.375rem; overflow-x: auto; font-size: 0.8rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <div class="type"><?= get_class($exception) ?></div>
            <h1><?= esc($message) ?></h1>
            <div class="path">
                in <span style="font-weight:600"><?= clean_path($exception->getFile()) ?></span> 
                at line <span style="font-weight:600"><?= $exception->getLine() ?></span>
            </div>
        </div>

        <div class="trace-box">
            <div class="trace-header">
                Stack Trace
            </div>
            
            <?php foreach ($trace as $index => $row) : ?>
                <div class="trace-item">
                    <div class="trace-num">#<?= $index ?></div>
                    <div style="flex: 1">
                        <div class="trace-file">
                            <?php if (isset($row['file']) && is_file($row['file'])) : ?>
                                <?= clean_path($row['file']) ?> : <?= $row['line'] ?>
                            <?php else : ?>
                                [internal function]
                            <?php endif; ?>
                        </div>
                        <div class="trace-func">
                            <?= $row['class'] ?? '' ?><?= $row['type'] ?? '' ?><?= $row['function'] ?>
                            <span class="args">
                                <?php if (!empty($row['args'])): ?>
                                    Arguments: <?= count($row['args']) ?> passed
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</body>
</html>
