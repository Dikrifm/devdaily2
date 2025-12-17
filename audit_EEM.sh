# Versi lengkap dengan metadata
{
    echo "CODEIGNITER 4 - AUDIT LAPORAN (Entities)"
    echo "============================================================"
    echo "Tanggal: $(date)"
    echo "Proyek: $(pwd)"
    echo ""

    # ENUMS
    echo ""
    echo "╔══════════════════════════════════════════════════╗"
    echo "║                    ENUMS                         ║"
    echo "╚══════════════════════════════════════════════════╝"
    echo ""
    if [ -d "app/Enums" ]; then
        echo "Total Entities: $(find app/Enums -name "*.php" | wc -l)"
        echo ""
        find app/Enums -name "*.php" | while read file; do
            echo "📁 ENUMS: $file"
            echo "────────────────────────────────────"
            cat "$file"
            echo ""
            echo "════════════════════════════════════════════"
            echo ""
        done
    else
        echo "Folder Enums tidak ditemukan!"
    fi

    # ENTITIES
    echo ""
    echo "╔══════════════════════════════════════════════════╗"
    echo "║                    ENTITIES                      ║"
    echo "╚══════════════════════════════════════════════════╝"
    echo ""
    if [ -d "app/Entities" ]; then
        echo "Total Entities: $(find app/Entities -name "*.php" | wc -l)"
        echo ""
        find app/Entities -name "*.php" | while read file; do
            echo "📁 ENTITIES: $file"
            echo "────────────────────────────────────"
            cat "$file"
            echo ""
            echo "════════════════════════════════════════════"
            echo ""
        done
    else
        echo "Folder Entities tidak ditemukan!"
    fi
    
    # MODELS
    echo ""
    echo "╔══════════════════════════════════════════════════╗"
    echo "║                    MODELS                        ║"
    echo "╚══════════════════════════════════════════════════╝"
    echo ""
    if [ -d "app/Models" ]; then
        echo "Total Entities: $(find app/Models -name "*.php" | wc -l)"
        echo ""
        find app/Models -name "*.php" | while read file; do
            echo "📁 MODELS: $file"
            echo "────────────────────────────────────"
            cat "$file"
            echo ""
            echo "════════════════════════════════════════════"
            echo ""
        done
    else
        echo "Folder Models tidak ditemukan!"
    fi

    
} > audit_EEM.txt

echo "Laporan disimpan (Enums, Entities dan Models): audit_EEM.txt"