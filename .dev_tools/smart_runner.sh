#!/bin/bash


# --- KONFIGURASI ---
FILE=$1
NOTIF_ID="gauntlet_scan"

# --- FUNGSI NOTIFIKASI AMAN ---
# Fungsi ini mencegah script crash jika Anda lupa install termux-api

    # --- FUNGSI NOTIFIKASI ANTI-MACET (DENGAN TIMEOUT) ---
notify_me() {
    # Cek apakah perintah ada
    if command -v termux-notification &> /dev/null; then
        # 'timeout 1s': Jika lebih dari 1 detik tidak ada respon, paksa lanjut!
        # '|| true': Mencegah script error jika timeout terjadi
        timeout 1s termux-notification --id "$NOTIF_ID" --title "$1" --content "$2" --priority "$3" --led-color "$4" || true
    fi
}


# --- VALIDASI AWAL (PENTING) ---
if [ -z "$FILE" ]; then
    echo -e "\033[1;31m[ERROR] Masukkan nama file!\033[0m"
    echo "Usage: ./cek app/Controllers/Home.php"
    exit 1
fi

if [ ! -f "$FILE" ]; then
    echo -e "\033[1;31m[ERROR] File tidak ditemukan: $FILE\033[0m"
    exit 1
fi

if [ ! -f "vendor/bin/phpstan" ]; then
    echo -e "\033[1;31m[ERROR] Jalankan script ini dari ROOT FOLDER project (sejajar dengan folder vendor).\033[0m"
    exit 1
fi

FILENAME=$(basename "$FILE" .php)

# --- 2. MULAI SCAN ---
clear
notify_me "🚀 Gauntlet Started" "Scanning: $FILENAME" "low" "blue"

echo "---------------------------------------------"
echo "$FILE"
echo ". . . . . ."
echo -e "\033[1;34m[THE GAUNTLET] Target: $FILENAME\033[0m"
echo -e "\033[1;30mMode: Incremental Scan (Level 0 -> 9)\033[0m"
echo "---------------------------------------------"

# --- 3. FASE 1: PHPStan Loop ---
for LEVEL in {0..9}
do
    echo -ne "Level \033[1;33m$LEVEL\033[0m : "

    # Capture output & exit code
    OUTPUT=$(vendor/bin/phpstan analyse --level "$LEVEL" --memory-limit=1G --no-progress --ansi "$FILE" 2>&1)
    STATUS=$?

    if [ $STATUS -eq 0 ]; then
        echo -e "\033[1;32m✅ OK\033[0m"
    else
        # JIKA GAGAL
        notify_me "❌ GAGAL: Level $LEVEL" "Error di $FILENAME. Cek terminal!" "high" "red"

        echo -e "\033[1;31m❌ GAGAL\033[0m"
        echo "---------------------------------------------"
        echo "$OUTPUT"
        echo "---------------------------------------------"
        echo -e "\a" 
        echo -e "\033[1;37m[SARAN] \033[0mPerbaiki error Level $LEVEL ini dulu."
        echo -e "\033[1;31m⛔ STOP.\033[0m"
        exit 1
    fi
done

notify_me "🏆 PERFECT! (Lvl 0-9)" "$FILENAME bersih. Lanjut testing..." "default" "green"
echo "---------------------------------------------"
echo -e "\033[1;32m🏆 PERFECT! Lolos semua Level (0-9).\033[0m"

# --- 4. FASE 2: Testing (PHPUnit) ---
echo -e "\n🧪 \033[1;33mRunning Tests (PHPUnit)...\033[0m"

# Logika pencarian file test yang lebih aman
TEST_FILE=""
if [[ "$FILE" == *"Test.php" ]]; then
    TEST_FILE="$FILE"
else
    # Cari di folder tests, ambil hasil pertama
    if [ -d "tests" ]; then
        TEST_FILE=$(find tests -type f -name "${FILENAME}Test.php" | head -n 1)
    fi
fi

if [ -n "$TEST_FILE" ]; then
    echo -e "Target: $TEST_FILE"
    vendor/bin/phpunit --colors=always "$TEST_FILE"
    
    TEST_STATUS=$?
    if [ $TEST_STATUS -eq 0 ]; then
         notify_me "✅ ALL CLEAR" "$FILENAME: Scan & Test Lulus!" "default" "green"
    else
         notify_me "⚠️ SCAN OK, TEST FAIL" "$FILENAME gagal di PHPUnit." "high" "yellow"
    fi
else
    echo -e "⚠️ \033[1;30mTidak ditemukan file test: ${FILENAME}Test.php\033[0m"
    # Tetap kirim notif sukses scan meskipun tidak ada test
    notify_me "✅ SCAN COMPLETE" "$FILENAME Lolos Scan (No Test Found)" "default" "green"
fi
