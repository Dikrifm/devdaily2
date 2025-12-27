<?php

namespace App\Validators;

use App\Models\CategoryModel;
use App\Models\MarketplaceModel;
use App\Models\ProductModel;
use App\Services\CacheService;
use CodeIgniter\Validation\Validation;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Enterprise-grade Slug Validator
 *
 * Reusable slug validation for multiple entities with format checking,
 * uniqueness validation, reserved words protection, and SEO optimization.
 */
class SlugValidator
{
    private Validation $validation;
    private CacheService $cacheService;

    // Model instances (lazy-loaded)
    private $productModel;
    private $categoryModel;
    private $marketplaceModel;
    private $pageModel;

    // Entity types
    public const ENTITY_PRODUCT = 'product';
    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_MARKETPLACE = 'marketplace';
    public const ENTITY_PAGE = 'page';
    public const ENTITY_POST = 'post';
    public const ENTITY_TAG = 'tag';
    public const ENTITY_USER = 'user';

    // Validation contexts
    public const CONTEXT_CREATE = 'create';
    public const CONTEXT_UPDATE = 'update';
    public const CONTEXT_GENERATE = 'generate';

    // Slug configuration
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;
    private const DEFAULT_LENGTH = 50;
    private const MAX_ATTEMPTS = 10; // Maximum attempts for unique slug generation

    // Regex patterns
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const CLEAN_PATTERN = '/[^a-z0-9]+/';
    private const MULTI_HYPHEN_PATTERN = '/-+/';

    // Reserved slugs (common system routes and protected terms)
    private const RESERVED_SLUGS = [
        // System routes
        'admin', 'api', 'dashboard', 'login', 'logout', 'register', 'password',
        'profile', 'settings', 'search', 'cart', 'checkout', 'order', 'payment',
        'invoice', 'account', 'user', 'users', 'customer', 'customers',

        // Common pages
        'home', 'index', 'default', 'main', 'welcome', 'about', 'contact',
        'privacy', 'terms', 'policy', 'faq', 'help', 'support', 'blog', 'news',

        // Product-related
        'products', 'categories', 'marketplaces', 'brands', 'shops', 'stores',
        'deals', 'offers', 'discounts', 'sales', 'new', 'featured', 'popular',
        'trending', 'best', 'top', 'latest',

        // API endpoints
        'v1', 'v2', 'graphql', 'webhook', 'callback', 'oauth', 'auth',

        // File paths
        'assets', 'css', 'js', 'images', 'uploads', 'downloads', 'files',
        'storage', 'public', 'private',

        // System files
        'robots.txt', 'sitemap.xml', 'favicon.ico', 'humans.txt',

        // Admin features
        'backend', 'cp', 'control-panel', 'manager', 'moderator',
    ];

    // Entity-specific reserved slugs
    private const ENTITY_RESERVED_SLUGS = [
        self::ENTITY_PRODUCT => [
            'create', 'edit', 'update', 'delete', 'publish', 'archive', 'manage'
        ],
        self::ENTITY_CATEGORY => [
            'all', 'uncategorized', 'misc', 'other', 'general'
        ],
        self::ENTITY_PAGE => [
            'page', 'pages', 'content', 'articles'
        ],
    ];

    // SEO-friendly slug rules
    private const SEO_OPTIMIZATION_RULES = [
        'remove_stop_words' => true,
        'stop_words' => ['a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'],
        'max_words' => 6,
        'prefer_lowercase' => true,
        'remove_special_chars' => true,
        'replace_underscores' => true,
        'trim_hyphens' => true,
    ];

    // Cache TTLs
    private const CACHE_TTL_SLUG_CHECK = 300; // 5 minutes
    private const CACHE_TTL_RESERVED = 3600; // 1 hour
    private const CACHE_TTL_SUGGESTIONS = 600; // 10 minutes

    public function __construct(
       //Validation $validation,
       //CacheService $cacheService
    ) {
        // 1. Ambil Validation service bawaan CI4
        $this->validation = \Config\Services::validation();

        // 2. Ambil CacheService custom Anda (menggunakan static create)
        $this->cacheService = \App\Services\CacheService::create();

        // 3. Load Model (agar properti model tidak error saat dipakai nanti)
        $this->productModel     = model('App\Models\ProductModel');
        $this->categoryModel    = model('App\Models\CategoryModel');
        $this->marketplaceModel = model('App\Models\MarketplaceModel');
        //$this->validation = $validation;
        //$this->cacheService = $cacheService;
    }

    /**
     * Validate slug for an entity
     */
    public function validate(
        string $slug,
        string $entityType,
        string $context = self::CONTEXT_CREATE,
        ?int $excludeId = null,
        array $options = []
    ): array {
        $errors = [];

        // 1. Basic format validation
        $formatErrors = $this->validateFormat($slug, $options);
        if (!empty($formatErrors)) {
            $errors = array_merge($errors, $formatErrors);
        }

        // 2. Length validation
        $lengthErrors = $this->validateLength($slug, $options);
        if (!empty($lengthErrors)) {
            $errors = array_merge($errors, $lengthErrors);
        }

        // 3. Reserved words validation
        $reservedErrors = $this->validateReserved($slug, $entityType, $options);
        if (!empty($reservedErrors)) {
            $errors = array_merge($errors, $reservedErrors);
        }

        // 4. Uniqueness validation (skip for generate context)
        if ($context !== self::CONTEXT_GENERATE) {
            $uniquenessErrors = $this->validateUniqueness($slug, $entityType, $excludeId, $options);
            if (!empty($uniquenessErrors)) {
                $errors = array_merge($errors, $uniquenessErrors);
            }
        }

        // 5. SEO validation (if enabled)
        if ($options['seo_validation'] ?? false) {
            $seoErrors = $this->validateSeo($slug, $options);
            if (!empty($seoErrors)) {
                $errors = array_merge($errors, $seoErrors);
            }
        }

        // 6. Entity-specific validation
        $entityErrors = $this->validateEntitySpecific($slug, $entityType, $context, $options);
        if (!empty($entityErrors)) {
            $errors = array_merge($errors, $entityErrors);
        }

        return $this->buildValidationResult($slug, empty($errors), $errors, [
            'entity_type' => $entityType,
            'context' => $context,
            'exclude_id' => $excludeId,
            'options' => $options
        ]);
    }

    /**
     * Generate a unique slug from a string
     */
    public function generate(
        string $source,
        string $entityType,
        ?int $excludeId = null,
        array $options = []
    ): string {
        $options = array_merge([
            'max_length' => self::DEFAULT_LENGTH,
            'preserve_case' => false,
            'separator' => '-',
            'increment_separator' => '-',
            'max_attempts' => self::MAX_ATTEMPTS,
            'force_lowercase' => true,
            'remove_stop_words' => self::SEO_OPTIMIZATION_RULES['remove_stop_words'],
            'stop_words' => self::SEO_OPTIMIZATION_RULES['stop_words'],
            'max_words' => self::SEO_OPTIMIZATION_RULES['max_words'],
        ], $options);

        // 1. Clean the source string
        $baseSlug = $this->cleanString($source, $options);

        // 2. Truncate to max length
        $baseSlug = $this->truncateSlug($baseSlug, $options['max_length']);

        // 3. Remove stop words if enabled
        if ($options['remove_stop_words']) {
            $baseSlug = $this->removeStopWords($baseSlug, $options['stop_words'], $options['separator']);
        }

        // 4. Limit words if specified
        if ($options['max_words'] > 0) {
            $baseSlug = $this->limitWords($baseSlug, $options['max_words'], $options['separator']);
        }

        // 5. Generate unique slug
        return $this->generateUniqueSlug($baseSlug, $entityType, $excludeId, $options);
    }

    /**
     * Suggest alternative slugs when validation fails
     */
    public function suggestAlternatives(
        string $originalSlug,
        string $entityType,
        ?int $excludeId = null,
        array $options = []
    ): array {
        $suggestions = [];
        $attempts = 0;
        $maxSuggestions = $options['max_suggestions'] ?? 5;

        // Cache key for suggestions
        $cacheKey = $this->getCacheKey('suggestions', [
            'slug' => $originalSlug,
            'entity' => $entityType,
            'exclude' => $excludeId,
            'max' => $maxSuggestions
        ]);

        // Try to get from cache first
        $cached = $this->cacheService->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Strategy 1: Try with incrementing numbers
        $baseSlug = $originalSlug;
        $increment = 1;

        while ($attempts < $maxSuggestions && $increment <= self::MAX_ATTEMPTS) {
            $suggestion = $baseSlug . '-' . $increment;

            if ($this->isValid($suggestion, $entityType, self::CONTEXT_CREATE, $excludeId, $options)) {
                $suggestions[] = $suggestion;
                $attempts++;
            }

            $increment++;
        }

        // Strategy 2: Try with random suffixes if still need more suggestions
        if (count($suggestions) < $maxSuggestions) {
            $randomAttempts = 0;
            $maxRandomAttempts = ($maxSuggestions - count($suggestions)) * 3;

            while (count($suggestions) < $maxSuggestions && $randomAttempts < $maxRandomAttempts) {
                $suffix = $this->generateRandomSuffix(3);
                $suggestion = $baseSlug . '-' . $suffix;

                if ($this->isValid($suggestion, $entityType, self::CONTEXT_CREATE, $excludeId, $options)) {
                    if (!in_array($suggestion, $suggestions)) {
                        $suggestions[] = $suggestion;
                    }
                }

                $randomAttempts++;
            }
        }

        // Strategy 3: Try with timestamp if still need more
        if (count($suggestions) < $maxSuggestions) {
            $timestamp = time();
            $suggestion = $baseSlug . '-' . $timestamp;

            if ($this->isValid($suggestion, $entityType, self::CONTEXT_CREATE, $excludeId, $options)) {
                $suggestions[] = $suggestion;
            }
        }

        // Strategy 4: Generate completely new slug from original
        if (count($suggestions) < $maxSuggestions) {
            $newSlug = $this->generate($originalSlug, $entityType, $excludeId, array_merge($options, [
                'max_attempts' => $maxSuggestions - count($suggestions)
            ]));

            if ($newSlug !== $originalSlug && !in_array($newSlug, $suggestions)) {
                $suggestions[] = $newSlug;
            }
        }

        // Cache the suggestions
        $this->cacheService->set($cacheKey, $suggestions, self::CACHE_TTL_SUGGESTIONS);

        return array_slice($suggestions, 0, $maxSuggestions);
    }

    /**
     * Check if a slug is valid (quick check without detailed errors)
     */
    public function isValid(
        string $slug,
        string $entityType,
        string $context = self::CONTEXT_CREATE,
        ?int $excludeId = null,
        array $options = []
    ): bool {
        // Check cache first
        $cacheKey = $this->getCacheKey('is_valid', [
            'slug' => $slug,
            'entity' => $entityType,
            'context' => $context,
            'exclude' => $excludeId,
            'options' => $options
        ]);

        $cached = $this->cacheService->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Perform validation
        $result = $this->validate($slug, $entityType, $context, $excludeId, $options);
        $isValid = $result['is_valid'];

        // Cache the result
        $this->cacheService->set($cacheKey, $isValid, self::CACHE_TTL_SLUG_CHECK);

        return $isValid;
    }

    /**
     * Normalize a slug (clean and format consistently)
     */
    public function normalize(string $slug, array $options = []): string
    {
        $options = array_merge([
            'force_lowercase' => true,
            'trim' => true,
            'replace_underscores' => true,
            'replace_spaces' => true,
            'collapse_hyphens' => true,
            'remove_special' => true,
        ], $options);

        $normalized = $slug;

        // Convert to lowercase
        if ($options['force_lowercase']) {
            $normalized = mb_strtolower($normalized, 'UTF-8');
        }

        // Replace underscores with hyphens
        if ($options['replace_underscores']) {
            $normalized = str_replace('_', '-', $normalized);
        }

        // Replace spaces with hyphens
        if ($options['replace_spaces']) {
            $normalized = preg_replace('/\s+/', '-', $normalized);
        }

        // Remove special characters
        if ($options['remove_special']) {
            $normalized = preg_replace(self::CLEAN_PATTERN, '-', $normalized);
        }

        // Collapse multiple hyphens
        if ($options['collapse_hyphens']) {
            $normalized = preg_replace(self::MULTI_HYPHEN_PATTERN, '-', $normalized);
        }

        // Trim hyphens from start and end
        if ($options['trim']) {
            $normalized = trim($normalized, '-');
        }

        return $normalized;
    }

    /**
     * Get SEO analysis for a slug
     */
    public function analyzeSeo(string $slug, array $options = []): array
    {
        $analysis = [
            'slug' => $slug,
            'score' => 0,
            'max_score' => 100,
            'factors' => [],
            'recommendations' => [],
            'timestamp' => (new DateTimeImmutable())->format('c')
        ];

        $score = 0;
        $factors = [];

        // 1. Length analysis
        $length = mb_strlen($slug);
        $lengthScore = $this->calculateLengthScore($length);
        $score += $lengthScore['score'];
        $factors['length'] = $lengthScore;

        if ($lengthScore['score'] < $lengthScore['max_score']) {
            $analysis['recommendations'][] = $lengthScore['recommendation'];
        }

        // 2. Word count analysis
        $wordCount = substr_count($slug, '-') + 1;
        $wordScore = $this->calculateWordScore($wordCount);
        $score += $wordScore['score'];
        $factors['word_count'] = $wordScore;

        if ($wordScore['score'] < $wordScore['max_score']) {
            $analysis['recommendations'][] = $wordScore['recommendation'];
        }

        // 3. Keyword analysis
        $keywordScore = $this->calculateKeywordScore($slug);
        $score += $keywordScore['score'];
        $factors['keywords'] = $keywordScore;

        if (!empty($keywordScore['recommendations'])) {
            $analysis['recommendations'] = array_merge(
                $analysis['recommendations'],
                $keywordScore['recommendations']
            );
        }

        // 4. Readability analysis
        $readabilityScore = $this->calculateReadabilityScore($slug);
        $score += $readabilityScore['score'];
        $factors['readability'] = $readabilityScore;

        if (!empty($readabilityScore['recommendations'])) {
            $analysis['recommendations'] = array_merge(
                $analysis['recommendations'],
                $readabilityScore['recommendations']
            );
        }

        // 5. Special characters analysis
        $specialCharScore = $this->calculateSpecialCharScore($slug);
        $score += $specialCharScore['score'];
        $factors['special_characters'] = $specialCharScore;

        if (!empty($specialCharScore['recommendations'])) {
            $analysis['recommendations'] = array_merge(
                $analysis['recommendations'],
                $specialCharScore['recommendations']
            );
        }

        $analysis['score'] = min($score, 100);
        $analysis['factors'] = $factors;

        // Overall rating
        $analysis['rating'] = $this->getSeoRating($analysis['score']);

        return $analysis;
    }

    /**
     * Check if slug is reserved
     */
    public function isReserved(string $slug, ?string $entityType = null): bool
    {
        $normalizedSlug = $this->normalize($slug);

        // Check global reserved slugs
        if (in_array($normalizedSlug, self::RESERVED_SLUGS)) {
            return true;
        }

        // Check entity-specific reserved slugs
        if ($entityType && isset(self::ENTITY_RESERVED_SLUGS[$entityType])) {
            if (in_array($normalizedSlug, self::ENTITY_RESERVED_SLUGS[$entityType])) {
                return true;
            }
        }

        // Check numeric-only slugs (could conflict with IDs)
        if (is_numeric($normalizedSlug) && strlen($normalizedSlug) <= 10) {
            return true;
        }

        // Check for common file extensions
        $extensions = ['.html', '.htm', '.php', '.asp', '.aspx', '.jsp', '.do', '.action'];
        foreach ($extensions as $ext) {
            if (str_ends_with($normalizedSlug, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all reserved slugs for an entity
     */
    public function getReservedSlugs(?string $entityType = null): array
    {
        $reserved = self::RESERVED_SLUGS;

        if ($entityType && isset(self::ENTITY_RESERVED_SLUGS[$entityType])) {
            $reserved = array_merge($reserved, self::ENTITY_RESERVED_SLUGS[$entityType]);
        }

        // Add numeric slugs
        for ($i = 0; $i <= 100; $i++) {
            $reserved[] = (string) $i;
        }

        // Sort and remove duplicates
        sort($reserved);
        $reserved = array_unique($reserved);

        return $reserved;
    }

    /**
     * Validate slug format
     */
    private function validateFormat(string $slug, array $options): array
    {
        $errors = [];
        $normalizedSlug = $this->normalize($slug);

        // Check if slug matches pattern
        if (!preg_match(self::SLUG_PATTERN, $normalizedSlug)) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'format',
                'message' => 'Slug can only contain lowercase letters, numbers, and hyphens. ' .
                           'Cannot start or end with hyphen, and cannot have consecutive hyphens.',
                'value' => $slug,
                'normalized' => $normalizedSlug
            ];
        }

        // Check for invalid characters
        $invalidChars = $this->findInvalidCharacters($slug);
        if (!empty($invalidChars)) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'invalid_characters',
                'message' => sprintf(
                    'Slug contains invalid characters: %s',
                    implode(', ', array_unique($invalidChars))
                ),
                'value' => $slug,
                'invalid_chars' => array_unique($invalidChars)
            ];
        }

        return $errors;
    }

    /**
     * Validate slug length
     */
    private function validateLength(string $slug, array $options): array
    {
        $errors = [];
        $length = mb_strlen($slug);

        $minLength = $options['min_length'] ?? self::MIN_LENGTH;
        $maxLength = $options['max_length'] ?? self::MAX_LENGTH;

        if ($length < $minLength) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'min_length',
                'message' => sprintf('Slug must be at least %s characters long', $minLength),
                'value' => $slug,
                'length' => $length,
                'min_length' => $minLength
            ];
        }

        if ($length > $maxLength) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'max_length',
                'message' => sprintf('Slug cannot exceed %s characters', $maxLength),
                'value' => $slug,
                'length' => $length,
                'max_length' => $maxLength
            ];
        }

        return $errors;
    }

    /**
     * Validate reserved words
     */
    private function validateReserved(string $slug, string $entityType, array $options): array
    {
        $errors = [];

        if ($this->isReserved($slug, $entityType)) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'reserved',
                'message' => 'This slug is reserved and cannot be used',
                'value' => $slug,
                'entity_type' => $entityType
            ];
        }

        // Check for partial matches with reserved words
        if ($options['check_partial_reserved'] ?? false) {
            $partialReserved = $this->checkPartialReserved($slug, $entityType);
            if (!empty($partialReserved)) {
                $errors[] = [
                    'field' => 'slug',
                    'rule' => 'partial_reserved',
                    'message' => sprintf(
                        'Slug partially matches reserved words: %s',
                        implode(', ', $partialReserved)
                    ),
                    'value' => $slug,
                    'matches' => $partialReserved
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate uniqueness
     */
    private function validateUniqueness(
        string $slug,
        string $entityType,
        ?int $excludeId,
        array $options
    ): array {
        $errors = [];

        // Check cache first
        $cacheKey = $this->getCacheKey('uniqueness', [
            'slug' => $slug,
            'entity' => $entityType,
            'exclude' => $excludeId
        ]);

        $cached = $this->cacheService->get($cacheKey);
        if ($cached !== null) {
            if (!$cached['is_unique']) {
                return [$cached['error']];
            }
            return [];
        }

        // Check uniqueness in database
        $isUnique = $this->checkDatabaseUniqueness($slug, $entityType, $excludeId);

        if (!$isUnique) {
            $error = [
                'field' => 'slug',
                'rule' => 'unique',
                'message' => sprintf('%s slug must be unique', ucfirst($entityType)),
                'value' => $slug,
                'entity_type' => $entityType,
                'suggestions' => $this->suggestAlternatives($slug, $entityType, $excludeId, [
                    'max_suggestions' => 3
                ])
            ];

            // Cache the negative result
            $this->cacheService->set($cacheKey, [
                'is_unique' => false,
                'error' => $error
            ], self::CACHE_TTL_SLUG_CHECK);

            $errors[] = $error;
        } else {
            // Cache the positive result
            $this->cacheService->set($cacheKey, [
                'is_unique' => true,
                'error' => null
            ], self::CACHE_TTL_SLUG_CHECK);
        }

        return $errors;
    }

    /**
     * Validate SEO factors
     */
    private function validateSeo(string $slug, array $options): array
    {
        $errors = [];
        $analysis = $this->analyzeSeo($slug, $options);

        $minSeoScore = $options['min_seo_score'] ?? 60;

        if ($analysis['score'] < $minSeoScore) {
            $errors[] = [
                'field' => 'slug',
                'rule' => 'seo_score',
                'message' => sprintf(
                    'Slug SEO score is %d/%d (minimum required: %d)',
                    $analysis['score'],
                    $analysis['max_score'],
                    $minSeoScore
                ),
                'value' => $slug,
                'seo_score' => $analysis['score'],
                'min_required_score' => $minSeoScore,
                'recommendations' => $analysis['recommendations']
            ];
        }

        // Check for stop words if enabled
        if ($options['warn_stop_words'] ?? false) {
            $stopWords = $this->findStopWords($slug);
            if (!empty($stopWords)) {
                $errors[] = [
                    'field' => 'slug',
                    'rule' => 'contains_stop_words',
                    'message' => sprintf(
                        'Slug contains stop words: %s',
                        implode(', ', $stopWords)
                    ),
                    'value' => $slug,
                    'stop_words' => $stopWords
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate entity-specific rules
     */
    private function validateEntitySpecific(
        string $slug,
        string $entityType,
        string $context,
        array $options
    ): array {
        $errors = [];

        switch ($entityType) {
            case self::ENTITY_PRODUCT:
                // Product slugs should not look like IDs
                if (is_numeric($slug) && strlen($slug) <= 10) {
                    $errors[] = [
                        'field' => 'slug',
                        'rule' => 'numeric_slug',
                        'message' => 'Product slug cannot be numeric-only',
                        'value' => $slug
                    ];
                }

                // Product slugs should be descriptive
                if (strlen($slug) < 5 && $context === self::CONTEXT_CREATE) {
                    $errors[] = [
                        'field' => 'slug',
                        'rule' => 'too_short_descriptive',
                        'message' => 'Product slug should be more descriptive (at least 5 characters)',
                        'value' => $slug,
                        'min_descriptive_length' => 5
                    ];
                }
                break;

            case self::ENTITY_CATEGORY:
                // Category slugs should be short and clear
                if (str_word_count(str_replace('-', ' ', $slug)) > 3) {
                    $errors[] = [
                        'field' => 'slug',
                        'rule' => 'too_many_words',
                        'message' => 'Category slug should be concise (maximum 3 words)',
                        'value' => $slug,
                        'word_count' => str_word_count(str_replace('-', ' ', $slug)),
                        'max_words' => 3
                    ];
                }
                break;

            case self::ENTITY_PAGE:
                // Page slugs should not start with numbers
                if (preg_match('/^\d/', $slug)) {
                    $errors[] = [
                        'field' => 'slug',
                        'rule' => 'starts_with_number',
                        'message' => 'Page slug should not start with a number',
                        'value' => $slug
                    ];
                }
                break;
        }

        return $errors;
    }

    /**
     * Check database uniqueness
     */
    private function checkDatabaseUniqueness(
        string $slug,
        string $entityType,
        ?int $excludeId
    ): bool {
        $model = $this->getModelForEntity($entityType);

        if (!$model) {
            throw new InvalidArgumentException("Unsupported entity type: {$entityType}");
        }

        $normalizedSlug = $this->normalize($slug);

        // Build query
        $builder = $model->builder();
        $builder->where('slug', $normalizedSlug);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        // Consider soft deleted records based on context
        if (method_exists($model, 'builder') && $model->useSoftDeletes ?? false) {
            $builder->where('deleted_at IS NULL');
        }

        return $builder->countAllResults() === 0;
    }

    /**
     * Generate unique slug with incrementing suffix
     */
    private function generateUniqueSlug(
        string $baseSlug,
        string $entityType,
        ?int $excludeId,
        array $options
    ): string {
        $attempt = 1;
        $maxAttempts = $options['max_attempts'] ?? self::MAX_ATTEMPTS;
        $incrementSeparator = $options['increment_separator'] ?? '-';

        $slug = $baseSlug;

        while ($attempt <= $maxAttempts) {
            // Check if current slug is valid
            if ($this->isValid($slug, $entityType, self::CONTEXT_CREATE, $excludeId, $options)) {
                return $slug;
            }

            // Try with incrementing number
            $attempt++;
            $slug = $baseSlug . $incrementSeparator . $attempt;
        }

        // If all attempts fail, append timestamp
        $timestamp = time();
        return $baseSlug . $incrementSeparator . $timestamp;
    }

    /**
     * Clean string for slug generation
     */
    private function cleanString(string $string, array $options): string
    {
        $cleaned = $string;

        // Convert to lowercase if needed
        if ($options['force_lowercase'] ?? true) {
            $cleaned = mb_strtolower($cleaned, 'UTF-8');
        }

        // Remove accents/diacritics
        $cleaned = $this->removeAccents($cleaned);

        // Replace spaces and special characters with separator
        $cleaned = preg_replace(self::CLEAN_PATTERN, $options['separator'], $cleaned);

        // Remove duplicate separators
        $cleaned = preg_replace('/' . preg_quote($options['separator'], '/') . '+/', $options['separator'], $cleaned);

        // Trim separators from ends
        $cleaned = trim($cleaned, $options['separator']);

        return $cleaned;
    }

    /**
     * Remove accents from string
     */
    private function removeAccents(string $string): string
    {
        $search = [
            'À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï',
            'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß', 'à', 'á',
            'â', 'ã', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ð', 'ñ',
            'ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'Ā', 'ā', 'Ă', 'ă',
            'Ą', 'ą', 'Ć', 'ć', 'Ĉ', 'ĉ', 'Ċ', 'ċ', 'Č', 'č', 'Ď', 'ď', 'Đ', 'đ', 'Ē', 'ē',
            'Ĕ', 'ĕ', 'Ė', 'ė', 'Ę', 'ę', 'Ě', 'ě', 'Ĝ', 'ĝ', 'Ğ', 'ğ', 'Ġ', 'ġ', 'Ģ', 'ģ',
            'Ĥ', 'ĥ', 'Ħ', 'ħ', 'Ĩ', 'ĩ', 'Ī', 'ī', 'Ĭ', 'ĭ', 'Į', 'į', 'İ', 'ı', 'Ĳ', 'ĳ',
            'Ĵ', 'ĵ', 'Ķ', 'ķ', 'Ĺ', 'ĺ', 'Ļ', 'ļ', 'Ľ', 'ľ', 'Ŀ', 'ŀ', 'Ł', 'ł', 'Ń', 'ń',
            'Ņ', 'ņ', 'Ň', 'ň', 'ŉ', 'Ŋ', 'ŋ', 'Ō', 'ō', 'Ŏ', 'ŏ', 'Ő', 'ő', 'Œ', 'œ', 'Ŕ',
            'ŕ', 'Ŗ', 'ŗ', 'Ř', 'ř', 'Ś', 'ś', 'Ŝ', 'ŝ', 'Ş', 'ş', 'Š', 'š', 'Ţ', 'ţ', 'Ť',
            'ť', 'Ŧ', 'ŧ', 'Ũ', 'ũ', 'Ū', 'ū', 'Ŭ', 'ŭ', 'Ů', 'ů', 'Ű', 'ű', 'Ų', 'ų', 'Ŵ',
            'ŵ', 'Ŷ', 'ŷ', 'Ÿ', 'Ź', 'ź', 'Ż', 'ż', 'Ž', 'ž', 'ſ', 'ƒ', 'Ơ', 'ơ', 'Ư', 'ư',
            'Ǎ', 'ǎ', 'Ǐ', 'ǐ', 'Ǒ', 'ǒ', 'Ǔ', 'ǔ', 'Ǖ', 'ǖ', 'Ǘ', 'ǘ', 'Ǚ', 'ǚ', 'Ǜ', 'ǜ',
            'Ǻ', 'ǻ', 'Ǽ', 'ǽ', 'Ǿ', 'ǿ'
        ];

        $replace = [
            'A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I',
            'D', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 's', 'a', 'a',
            'a', 'a', 'a', 'a', 'ae', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'd', 'n',
            'o', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'A', 'a', 'A', 'a',
            'A', 'a', 'C', 'c', 'C', 'c', 'C', 'c', 'C', 'c', 'D', 'd', 'D', 'd', 'E', 'e',
            'E', 'e', 'E', 'e', 'E', 'e', 'E', 'e', 'G', 'g', 'G', 'g', 'G', 'g', 'G', 'g',
            'H', 'h', 'H', 'h', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'IJ', 'ij',
            'J', 'j', 'K', 'k', 'L', 'l', 'L', 'l', 'L', 'l', 'L', 'l', 'L', 'l', 'N', 'n',
            'N', 'n', 'N', 'n', 'n', 'N', 'n', 'O', 'o', 'O', 'o', 'O', 'o', 'OE', 'oe', 'R',
            'r', 'R', 'r', 'R', 'r', 'S', 's', 'S', 's', 'S', 's', 'S', 's', 'T', 't', 'T',
            't', 'T', 't', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'W',
            'w', 'Y', 'y', 'Y', 'Z', 'z', 'Z', 'z', 'Z', 'z', 's', 'f', 'O', 'o', 'U', 'u',
            'A', 'a', 'I', 'i', 'O', 'o', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u',
            'A', 'a', 'AE', 'ae', 'O', 'o'
        ];

        return str_replace($search, $replace, $string);
    }

    /**
     * Truncate slug to maximum length
     */
    private function truncateSlug(string $slug, int $maxLength): string
    {
        if (mb_strlen($slug) <= $maxLength) {
            return $slug;
        }

        // Try to truncate at word boundary
        $truncated = mb_substr($slug, 0, $maxLength);
        $lastSeparator = mb_strrpos($truncated, '-');

        if ($lastSeparator > 0 && $lastSeparator > $maxLength * 0.7) {
            $truncated = mb_substr($truncated, 0, $lastSeparator);
        }

        return rtrim($truncated, '-');
    }

    /**
     * Remove stop words from slug
     */
    private function removeStopWords(string $slug, array $stopWords, string $separator): string
    {
        $words = explode($separator, $slug);
        $filteredWords = array_filter($words, function ($word) use ($stopWords) {
            return !in_array($word, $stopWords);
        });

        return implode($separator, $filteredWords);
    }

    /**
     * Limit number of words in slug
     */
    private function limitWords(string $slug, int $maxWords, string $separator): string
    {
        $words = explode($separator, $slug);

        if (count($words) <= $maxWords) {
            return $slug;
        }

        $limitedWords = array_slice($words, 0, $maxWords);
        return implode($separator, $limitedWords);
    }

    /**
     * Generate random suffix
     */
    private function generateRandomSuffix(int $length = 3): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $suffix = '';

        for ($i = 0; $i < $length; $i++) {
            $suffix .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $suffix;
    }

    /**
     * Find invalid characters in slug
     */
    private function findInvalidCharacters(string $slug): array
    {
        $invalid = [];
        $normalized = $this->normalize($slug, ['force_lowercase' => false]);

        // Check each character
        for ($i = 0; $i < mb_strlen($normalized); $i++) {
            $char = mb_substr($normalized, $i, 1);

            // Allow letters, numbers, hyphens
            if (!preg_match('/^[a-zA-Z0-9-]$/', $char)) {
                $invalid[] = $char === ' ' ? '[space]' : $char;
            }
        }

        return $invalid;
    }

    /**
     * Check for partial matches with reserved words
     */
    private function checkPartialReserved(string $slug, string $entityType): array
    {
        $matches = [];
        $slugParts = explode('-', $slug);
        $allReserved = $this->getReservedSlugs($entityType);

        foreach ($allReserved as $reserved) {
            foreach ($slugParts as $part) {
                if (strpos($reserved, $part) !== false || strpos($part, $reserved) !== false) {
                    if (!in_array($reserved, $matches)) {
                        $matches[] = $reserved;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Find stop words in slug
     */
    private function findStopWords(string $slug): array
    {
        $stopWords = self::SEO_OPTIMIZATION_RULES['stop_words'];
        $slugWords = explode('-', $slug);
        $found = [];

        foreach ($slugWords as $word) {
            if (in_array($word, $stopWords)) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /**
     * Calculate length score for SEO
     */
    private function calculateLengthScore(int $length): array
    {
        $maxScore = 20;
        $idealMin = 3;
        $idealMax = 60;
        $absoluteMax = 100;

        if ($length < $idealMin) {
            $score = 5;
            $recommendation = "Slug is too short. Aim for {$idealMin}-{$idealMax} characters.";
        } elseif ($length > $absoluteMax) {
            $score = 0;
            $recommendation = "Slug is too long. Maximum recommended length is {$absoluteMax} characters.";
        } elseif ($length > $idealMax) {
            // Linear decrease from idealMax to absoluteMax
            $score = $maxScore * (1 - ($length - $idealMax) / ($absoluteMax - $idealMax));
            $recommendation = "Slug is longer than recommended. Consider shortening to {$idealMax} characters or less.";
        } else {
            // Full score for ideal length
            $score = $maxScore;
            $recommendation = "Slug length is optimal.";
        }

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'length' => $length,
            'ideal_range' => [$idealMin, $idealMax],
            'recommendation' => $recommendation
        ];
    }

    /**
     * Calculate word count score for SEO
     */
    private function calculateWordScore(int $wordCount): array
    {
        $maxScore = 20;
        $idealMin = 2;
        $idealMax = 5;
        $absoluteMax = 8;

        if ($wordCount < $idealMin) {
            $score = $maxScore * 0.5;
            $recommendation = "Slug has too few words. Aim for {$idealMin}-{$idealMax} words.";
        } elseif ($wordCount > $absoluteMax) {
            $score = 0;
            $recommendation = "Slug has too many words. Maximum recommended is {$absoluteMax} words.";
        } elseif ($wordCount > $idealMax) {
            // Linear decrease from idealMax to absoluteMax
            $score = $maxScore * (1 - ($wordCount - $idealMax) / ($absoluteMax - $idealMax));
            $recommendation = "Slug has more words than recommended. Aim for {$idealMax} words or less.";
        } else {
            // Full score for ideal word count
            $score = $maxScore;
            $recommendation = "Word count is optimal.";
        }

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'word_count' => $wordCount,
            'ideal_range' => [$idealMin, $idealMax],
            'recommendation' => $recommendation
        ];
    }

    /**
     * Calculate keyword score for SEO
     */
    private function calculateKeywordScore(string $slug): array
    {
        $maxScore = 30;
        $score = $maxScore;
        $recommendations = [];

        $words = explode('-', $slug);

        // Check for keyword stuffing (repetition)
        $wordCounts = array_count_values($words);
        foreach ($wordCounts as $word => $count) {
            if ($count > 2) {
                $score -= 10;
                $recommendations[] = "Word '{$word}' is repeated too many times.";
            }
        }

        // Check for generic words
        $genericWords = ['page', 'post', 'item', 'product', 'category', 'tag'];
        $genericFound = array_intersect($words, $genericWords);
        if (!empty($genericFound)) {
            $score -= 5;
            $recommendations[] = "Consider replacing generic words: " . implode(', ', $genericFound);
        }

        // Check for numbers at the end (good for SEO)
        $lastWord = end($words);
        if (is_numeric($lastWord)) {
            $score += 5;
        }

        // Ensure score stays within bounds
        $score = max(0, min($maxScore, $score));

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Calculate readability score
     */
    private function calculateReadabilityScore(string $slug): array
    {
        $maxScore = 20;
        $score = $maxScore;
        $recommendations = [];

        $words = explode('-', $slug);

        // Check word lengths
        foreach ($words as $word) {
            $length = strlen($word);
            if ($length > 15) {
                $score -= 5;
                $recommendations[] = "Word '{$word}' is very long. Consider shortening.";
            } elseif ($length > 20) {
                $score -= 10;
                $recommendations[] = "Word '{$word}' is too long. Consider splitting or replacing.";
            }
        }

        // Check for consecutive consonants/vowels (hard to pronounce)
        foreach ($words as $word) {
            if (preg_match('/[bcdfghjklmnpqrstvwxyz]{4,}/i', $word) ||
                preg_match('/[aeiou]{4,}/i', $word)) {
                $score -= 3;
                $recommendations[] = "Word '{$word}' might be hard to pronounce.";
            }
        }

        // Ensure score stays within bounds
        $score = max(0, min($maxScore, $score));

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Calculate special characters score
     */
    private function calculateSpecialCharScore(string $slug): array
    {
        $maxScore = 10;
        $score = $maxScore;
        $recommendations = [];

        // Check for special characters (already validated, but double-check)
        if (preg_match('/[^a-z0-9-]/', $slug)) {
            $score = 0;
            $recommendations[] = "Slug contains invalid characters. Only lowercase letters, numbers, and hyphens are allowed.";
        }

        // Check for consecutive hyphens
        if (strpos($slug, '--') !== false) {
            $score -= 5;
            $recommendations[] = "Avoid consecutive hyphens in slug.";
        }

        // Check for leading/trailing hyphens
        if ($slug[0] === '-' || substr($slug, -1) === '-') {
            $score -= 5;
            $recommendations[] = "Remove hyphens from beginning or end of slug.";
        }

        // Ensure score stays within bounds
        $score = max(0, min($maxScore, $score));

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Get SEO rating based on score
     */
    private function getSeoRating(int $score): string
    {
        if ($score >= 90) {
            return 'excellent';
        }
        if ($score >= 80) {
            return 'very good';
        }
        if ($score >= 70) {
            return 'good';
        }
        if ($score >= 60) {
            return 'fair';
        }
        if ($score >= 50) {
            return 'needs improvement';
        }
        return 'poor';
    }

    /**
     * Get model instance for entity type
     */
    private function getModelForEntity(string $entityType)
    {
        switch ($entityType) {
            case self::ENTITY_PRODUCT:
                if (!isset($this->productModel)) {
                    $this->productModel = model(ProductModel::class);
                }
                return $this->productModel;

            case self::ENTITY_CATEGORY:
                if (!isset($this->categoryModel)) {
                    $this->categoryModel = model(CategoryModel::class);
                }
                return $this->categoryModel;

            case self::ENTITY_MARKETPLACE:
                if (!isset($this->marketplaceModel)) {
                    $this->marketplaceModel = model(MarketplaceModel::class);
                }
                return $this->marketplaceModel;

            default:
                throw new InvalidArgumentException("Unsupported entity type: {$entityType}");
        }
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $type, array $params = []): string
    {
        $key = 'slug_validator_' . $type;

        if (!empty($params)) {
            $key .= '_' . md5(serialize($params));
        }

        return $key;
    }

    /**
     * Build validation result
     */
    private function buildValidationResult(
        string $slug,
        bool $isValid,
        array $errors,
        array $context
    ): array {
        $normalizedSlug = $this->normalize($slug);

        $result = [
            'is_valid' => $isValid,
            'slug' => $slug,
            'normalized_slug' => $normalizedSlug,
            'errors' => $errors,
            'context' => $context,
            'timestamp' => (new DateTimeImmutable())->format('c'),
            'error_count' => count($errors)
        ];

        // Add suggestions if there are errors
        if (!$isValid && !empty($errors)) {
            $result['suggestions'] = $this->suggestAlternatives(
                $slug,
                $context['entity_type'],
                $context['exclude_id'] ?? null,
                ['max_suggestions' => 3]
            );
        }

        return $result;
    }

    /**
     * Create SlugValidator instance with default dependencies
     */
    public static function create(): self
    {
        $validation = service('validation');
        $cacheService = new CacheService(service('cache'));

        return new self($validation, $cacheService);
    }
}
