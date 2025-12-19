#!/bin/bash

# Script: audit_backend.sh (Mode: 1 File = 1 Baris)
# Output: backend_structure.txt

OUTPUT_FILE="audit_backend.txt"
target_dirs="app/Config app/Controllers app/Contracts app/DTOs app/Entities app/Enums app/Exceptions app/Models app/Repositories app/Services app/Validators  tests/"

# Reset file output
echo "--- CI4 BACKEND SKELETON (Compressed Mode) ---" > "$OUTPUT_FILE"
echo "Generated at: $(date)" >> "$OUTPUT_FILE"
echo "Format: [No]. [File Path] :: [Content separated by '||']" >> "$OUTPUT_FILE"
echo "------------------------------------------------" >> "$OUTPUT_FILE"

echo "🚀 Memulai scanning & kompresi..."
echo "📂 Target: $target_dirs"
echo "📄 Output: $OUTPUT_FILE"

# Counter untuk nomor baris
count=1

# Loop file
find $target_dirs -name "*.php" | sort | while read file; do
    # 1. Tampilkan progress di layar (singkat saja agar tidak spam)
    echo -ne "\r⏳ Processing [$count]: $file \033[0K"
    
    # 2. Ambil Skeleton Code
    raw_content=$(egrep "^\s*(namespace|use|class|interface|trait|enum|abstract|final|public|protected|private|function|const|protected .table|protected .allowedFields|protected .useTimestamps)" "$file")
    
    # 3. Kompresi menjadi 1 baris
    # tr '\n' ' '     -> Ubah enter jadi spasi
    # sed 's/\s\+/ /g' -> Hapus spasi ganda berlebih
    # sed ...         -> Ganti ' ; ' dengan ' || ' agar lebih tegas pemisahnya bagi AI
    compressed_content=$(echo "$raw_content" | tr '\n' ' ' | sed 's/\s\+/ /g' | sed 's/; / || /g')
    
    # 4. Tulis ke file dengan nomor urut
    echo "$count. [$file] :: $compressed_content" >> "$OUTPUT_FILE"
    
    ((count++))
done

echo -e "\n\n✅ SELESAI! $count file telah diproses."
echo "👉 Gunakan perintah ini untuk melihat hasilnya:"
echo "   cat $OUTPUT_FILE"
