#!/bin/bash

# 1. Tangkap file yang berubah
FILE=$1
FILENAME=$(basename "$FILE" .php)

# 2. Bersihkan layar agar fokus
clear
echo "---------------------------------------------"
echo "---------------------------------------------"
echo -e "\033[1;34m[MONITOR] File Saved: $FILE\033[0m"
echo "---------------------------------------------"

# 3. FASE 1: Debugging (PHPStan)
# Cek kualitas kode hanya pada file yang diedit
echo -e "\n🔎 \033[1;33mChecking Logic (PHPStan)...\033[0m"

# Jalankan PHPStan dengan limit memori aman untuk HP
vendor/bin/phpstan analyse --level 1 --memory-limit=1G --ansi "$FILE"

# Cek status: Jika Error (kode 1), BERHENTI DISINI.
if [ $? -ne 0 ]; then
    echo -e "\n❌ \033[1;31mSTOP! Perbaiki error di atas dulu.\033[0m"
    echo -e "\a" # Bunyi beep
    exit 1
fi

# 4. FASE 2: Testing (PHPUnit)
echo -e "\n---------------------------------------------"
echo -e "🧪 \033[1;33mRunning Tests (PHPUnit)...\033[0m"

# Logika Mencari Pasangan Test
TEST_FILE=""

if [[ "$FILE" == *"Test.php" ]]; then
    # Jika yang diedit adalah file Test, jalankan file itu sendiri
    TEST_FILE="$FILE"
else
    # Jika yang diedit adalah Source Code, cari file Test-nya
    # Cari di folder tests/ dengan nama yang sesuai
    FOUND=$(find tests -name "${FILENAME}Test.php" | head -n 1)
    if [ -n "$FOUND" ]; then
        TEST_FILE="$FOUND"
    fi
fi

if [ -n "$TEST_FILE" ]; then
    echo -e "Target: $TEST_FILE"
    vendor/bin/phpunit --colors=always "$TEST_FILE"
else
    echo -e "⚠️ \033[1;30mTidak ditemukan file test khusus untuk ini.\033[0m"
fi
