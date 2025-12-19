#!/bin/bash

# Judul
echo -e "\033[1;34m🔧 DEVOPS: Auto-Fix Code (Rector)\033[0m"
echo "----------------------------------------"

# 1. DRY RUN (Simulasi)
# Ini hanya mengecek apa yang AKAN berubah, tanpa mengubah file aslinya.
echo -e "\n\033[1;33m[1/2] Menganalisis potensi perbaikan (Dry Run)...\033[0m"
echo "      Mohon tunggu, sedang memindai..."

# Gunakan limit memori 1GB agar aman di HP
vendor/bin/rector process --dry-run --memory-limit=1G --ansi

# Simpan status exit code
STATUS=$?

echo "----------------------------------------"

# Jika tidak ada yang perlu diperbaiki
if [ $STATUS -eq 0 ]; then
    echo -e "✅ \033[1;32mKode Anda sudah bersih! Tidak ada yang perlu diperbaiki.\033[0m"
    exit 0
fi

# 2. KONFIRMASI USER
# Jika ada saran perubahan, tanya dulu.
echo -e "\n⚠️  Rector menyarankan perubahan di atas."
read -p "❓ Apakah Anda ingin menerapkannya ke file asli? (y/n) " -n 1 -r
echo # Baris baru

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # 3. REAL RUN (Eksekusi)
    echo -e "\n\033[1;32m[2/2] Sedang memperbaiki kode...\033[0m"
    vendor/bin/rector process --memory-limit=1G --ansi
    echo -e "\n✅ \033[1;32mSELESAI! Kode telah diperbaiki otomatis.\033[0m"
    echo "   Disarankan untuk menjalankan './watch.sh' untuk memastikan tidak ada error baru."
else
    echo -e "\n⛔ Operasi dibatalkan. Kode tidak berubah."
fi
