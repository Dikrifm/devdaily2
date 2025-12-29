<?php
// Warna ANSI untuk Terminal
$red    = "\033[0;31m";
$yellow = "\033[1;33m";
$reset  = "\033[0m";

// Output Pesan Error
fwrite(STDOUT, $red . "\nERROR: 404 Not Found" . $reset . "\n");
fwrite(STDOUT, $yellow . "The command you entered does not exist: " . $reset . $red . ($code ?? 'Unknown') . $reset . "\n");
fwrite(STDOUT, "Check 'php spark routes' for a list of available commands.\n\n");
