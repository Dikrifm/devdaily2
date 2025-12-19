#!/bin/bash
# Script untuk menyalakan Mode Otomatis

echo "👀  DevDaily Auto-Debug dimulai..."
echo "    Tekan [CTRL + C] untuk berhenti."
echo "    Menunggu Anda menekan Save..."

# Cari semua file PHP di folder app/ dan tests/
# Lalu pantau perubahannya menggunakan 'entr'
find app tests -name "*.php" | entr -c ./.dev_tools/smart_runner.sh /_

