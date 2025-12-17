<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use App\Validators\SlugValidator;

class Validation extends BaseConfig
{
    /**
     * Custom validation rules.
     * 
     * Format: 'rule_name' => 'class_name::method_name'
     */
    public $ruleSets = [
        \CodeIgniter\Validation\Rules::class,
        \CodeIgniter\Validation\FormatRules::class,
        \CodeIgniter\Validation\FileRules::class,
        \CodeIgniter\Validation\CreditCardRules::class,
        // Custom rules bisa ditambahkan di sini
    ];

    /**
     * Custom error messages.
     * 
     * Format: 'rule_name' => 'Error message'
     */
    public array $customMessages = [
        // DTO validation messages
        'required_if_published' => 'Field {field} wajib diisi saat status published.',
        'valid_slug' => 'Field {field} harus berupa slug yang valid (hanya huruf kecil, angka, dan tanda hubung).',
        'unique_slug' => 'Slug {value} sudah digunakan.',
        'valid_enum' => 'Field {field} harus berisi nilai yang valid dari enum {param}.',
        'future_date' => 'Field {field} harus berisi tanggal di masa depan.',
        'price_range' => 'Harga harus antara {param[0]} dan {param[1]}.',
        
        // Business rule messages
        'can_publish' => 'Produk tidak dapat dipublikasikan: {field}',
        'can_archive' => 'Produk tidak dapat diarsipkan: {field}',
        'can_delete' => 'Data tidak dapat dihapus: {field}',
        'valid_state_transition' => 'Transisi status tidak valid dari {param[0]} ke {param[1]}.',
        
        // File validation
        'max_file_count' => 'Maksimal {param} file yang diizinkan.',
        'allowed_mimes' => 'Tipe file tidak diizinkan. Hanya {param} yang diterima.',
        'image_dimensions' => 'Dimensi gambar harus {param}.',
        
        // Slug validation
        'reserved_slug' => 'Slug {value} adalah kata yang dipesan dan tidak dapat digunakan.',
        'seo_friendly' => 'Slug harus SEO-friendly: {field}',
    ];

    /**
     * Custom validation rules.
     * 
     * Format: 'ruleName' => [class, method]
     */
    public array $customRules = [
        'valid_slug' => [SlugValidator::class, 'validateSlug'],
        'unique_slug' => [SlugValidator::class, 'isUniqueSlug'],
        'reserved_slug' => [SlugValidator::class, 'isReservedSlug'],
        'seo_friendly' => [SlugValidator::class, 'analyzeSeo'],
        
        // DTO validation rules
        'required_if_published' => [\App\Validators\ProductValidator::class, 'validateRequiredIfPublished'],
        'valid_enum' => [\App\Validators\EnumValidator::class, 'validateEnumValue'],
        'future_date' => [\App\Validators\DateValidator::class, 'validateFutureDate'],
        'price_range' => [\App\Validators\ProductValidator::class, 'validatePriceRange'],
        
        // Business rule validators
        'can_publish' => [\App\Validators\ProductValidator::class, 'validatePublishEligibility'],
        'can_archive' => [\App\Validators\ProductValidator::class, 'validateArchiveEligibility'],
        'can_delete' => [\App\Validators\EntityValidator::class, 'validateDeletionEligibility'],
        'valid_state_transition' => [\App\Validators\EntityValidator::class, 'validateStateTransition'],
        
        // File validation
        'max_file_count' => [\App\Validators\FileValidator::class, 'validateFileCount'],
        'allowed_mimes' => [\App\Validators\FileValidator::class, 'validateMimeTypes'],
        'image_dimensions' => [\App\Validators\ImageValidator::class, 'validateDimensions'],
    ];

    /**
     * Template untuk aturan validasi umum.
     * Bisa di-reuse di berbagai DTO/Request.
     */
    public array $templates = [
        'product_name' => [
            'label' => 'Nama Produk',
            'rules' => 'required|min_length[3]|max_length[255]|string',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'min_length' => '{field} minimal {param} karakter.',
                'max_length' => '{field} maksimal {param} karakter.',
            ]
        ],
        
        'product_slug' => [
            'label' => 'Slug Produk',
            'rules' => 'required|valid_slug|unique_slug[products,slug]|max_length[100]',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'valid_slug' => '{field} harus berupa slug yang valid.',
                'unique_slug' => '{field} sudah digunakan.',
                'max_length' => '{field} maksimal {param} karakter.',
            ]
        ],
        
        'product_price' => [
            'label' => 'Harga Produk',
            'rules' => 'required|decimal|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'decimal' => '{field} harus berupa angka desimal.',
                'greater_than_equal_to' => '{field} minimal {param}.',
                'less_than_equal_to' => '{field} maksimal {param}.',
            ]
        ],
        
        'product_status' => [
            'label' => 'Status Produk',
            'rules' => 'required|valid_enum[ProductStatus]',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'valid_enum' => '{field} tidak valid.',
            ]
        ],
        
        'pagination_page' => [
            'label' => 'Halaman',
            'rules' => 'if_exist|integer|greater_than_equal_to[1]',
            'errors' => [
                'integer' => '{field} harus berupa angka bulat.',
                'greater_than_equal_to' => '{field} minimal {param}.',
            ]
        ],
        
        'pagination_per_page' => [
            'label' => 'Item per Halaman',
            'rules' => 'if_exist|integer|greater_than_equal_to[1]|less_than_equal_to[100]',
            'errors' => [
                'integer' => '{field} harus berupa angka bulat.',
                'greater_than_equal_to' => '{field} minimal {param}.',
                'less_than_equal_to' => '{field} maksimal {param}.',
            ]
        ],
        
        'admin_email' => [
            'label' => 'Email Admin',
            'rules' => 'required|valid_email|max_length[100]',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'valid_email' => '{field} harus berupa email yang valid.',
                'max_length' => '{field} maksimal {param} karakter.',
            ]
        ],
        
        'admin_password' => [
            'label' => 'Password',
            'rules' => 'required|min_length[8]|max_length[72]',
            'errors' => [
                'required' => '{field} wajib diisi.',
                'min_length' => '{field} minimal {param} karakter.',
                'max_length' => '{field} maksimal {param} karakter.',
            ]
        ],
    ];

    /**
     * Validation rules for common API parameters.
     */
    public array $apiRules = [
        'pagination' => [
            'page' => 'if_exist|integer|greater_than_equal_to[1]',
            'per_page' => 'if_exist|integer|greater_than_equal_to[1]|less_than_equal_to[100]',
            'sort_by' => 'if_exist|in_list[created_at,updated_at,name,price]',
            'sort_order' => 'if_exist|in_list[asc,desc,ASC,DESC]',
        ],
        
        'search' => [
            'keyword' => 'if_exist|string|max_length[100]',
            'fields' => 'if_exist|string',
        ],
        
        'filter' => [
            'status' => 'if_exist|string',
            'category_id' => 'if_exist|integer',
            'date_from' => 'if_exist|valid_date',
            'date_to' => 'if_exist|valid_date',
        ],
    ];

    /**
     * Validation settings.
     */
    public bool $allowEmptyRules = false;
    public bool $requireRule = true;
    
    /**
     * Error display templates.
     */
    public string $errorTemplate = 'App\Views\errors\validation_error_template';
    
    /**
     * Get validation template by name.
     * 
     * @param string $templateName
     * @return array|null
     */
    public function getTemplate(string $templateName): ?array
    {
        return $this->templates[$templateName] ?? null;
    }
    
    /**
     * Get API validation rules by type.
     * 
     * @param string $type
     * @return array|null
     */
    public function getApiRules(string $type): ?array
    {
        return $this->apiRules[$type] ?? null;
    }
}