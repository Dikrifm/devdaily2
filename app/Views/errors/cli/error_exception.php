<?php
// Tentukan warna (jika didukung terminal)
$red   = "\033[0;31m";
$green = "\033[0;32m";
$cyan  = "\033[0;36m"; 
$reset = "\033[0m";

echo "\n" . $red . "An uncaught Exception was encountered" . $reset . "\n\n";

echo "Type:     " . get_class($exception) . "\n";
echo "Message:  " . $exception->getMessage() . "\n";
echo "Filename: " . clean_path($exception->getFile()) . "\n";
echo "Line Number: " . $exception->getLine() . "\n";

echo "\n" . $cyan . "Backtrace:" . $reset . "\n";

foreach ($exception->getTrace() as $error) {
    if (isset($error['file'])) {
        echo "\tFile: " . clean_path($error['file']) . "\n";
        echo "\tLine: " . $error['line'] . "\n";
        echo "\tFunction: " . $error['function'] . "\n\n";
    }
}
?>
