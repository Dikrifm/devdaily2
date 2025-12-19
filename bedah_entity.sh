#!/bin/bash

ENTITY_NAME=$1
TABLE_NAME=$2

if [ -z "$ENTITY_NAME" ]; then
    echo "❌ Cara pakai: ./bedah_entity.sh [NamaEntity] [NamaTabel]"
    echo "Contoh: ./bedah_entity.sh Link links"
    exit 1
fi

FILE_PATH="app/Entities/$ENTITY_NAME.php"

echo "============================================"
echo "🕵️  BEDAH ENTITY: $ENTITY_NAME (Versi Bernomer)"
echo "============================================"

# 1. CEK METHOD (Fungsi di File PHP)
if [ -f "$FILE_PATH" ]; then
    echo "📁 SOURCE CODE: $FILE_PATH"
    echo "👇 Daftar Method & Constant:"
    echo "--------------------------------------------"
    
    # Logic: Cari 'public function', hapus spasi depan, lalu beri nomor urut
    grep -E "public function|const" "$FILE_PATH" \
    | sed 's/^[ \t]*//' \
    | awk '{print NR ". " $0}'
    
else
    echo "❌ File $FILE_PATH tidak ditemukan!"
fi

echo ""

# 2. CEK PROPERTI (Kolom Database)
if [ -z "$TABLE_NAME" ]; then
    echo "⚠️  Nama tabel belum dimasukkan."
else
    echo "🗄️  DATABASE TABLE: $TABLE_NAME (Properti Magic)"
    echo "👇 Daftar Properti (Kolom Tabel):"
    echo "--------------------------------------------"
    
    # Logic: 
    # 1. Panggil spark db:table metadata
    # 2. Ambil baris yang ada tanda '|' (garis tabel)
    # 3. Buang baris header (Field/Type) dan baris pemisah (+)
    # 4. Ambil kolom ke-2 (Nama Field)
    # 5. Bersihkan spasi
    # 6. Beri nomor urut dan tambah tanda '$' di depan
    
    php spark db:table "$TABLE_NAME" --metadata \
    | grep "|" \
    | grep -v "+" \
    | grep -v "Column Name" \
    | grep -v "Field" \
    | awk -F "|" '{print $2}' \
    | sed 's/^[ \t]*//;s/[ \t]*$//' \
    | awk '{print NR ". $" $0}'
fi

echo "============================================"
