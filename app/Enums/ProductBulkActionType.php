<?php

namespace App\Enums;

/**
 * Product Bulk Action Type Enum
 * 
 * Defines all possible bulk actions that can be performed on products
 * Eliminates magic strings, provides type safety and autocompletion
 * 
 * @package DevDaily
 * @subpackage ProductEnums
 */
enum ProductBulkActionType: string
{
    /**
     * Publish selected products (set status to published)
     */
    case PUBLISH = 'publish';
    
    /**
     * Unpublish selected products (set status to draft)
     */
    case UNPUBLISH = 'unpublish';
    
    /**
     * Soft delete selected products (set deleted_at)
     */
    case DELETE = 'delete';
    
    /**
     * Hard delete selected products (permanent removal)
     */
    case HARD_DELETE = 'hard_delete';
    
    /**
     * Change category for selected products
     */
    case CHANGE_CATEGORY = 'change_category';
    
    /**
     * Change price for selected products
     */
    case CHANGE_PRICE = 'change_price';
    
    /**
     * Add tags to selected products
     */
    case ADD_TAGS = 'add_tags';
    
    /**
     * Remove tags from selected products
     */
    case REMOVE_TAGS = 'remove_tags';
    
    /**
     * Export selected products to file
     */
    case EXPORT = 'export';
    
    /**
     * Duplicate selected products
     */
    case DUPLICATE = 'duplicate';
    
    /**
     * Move products to another marketplace section
     */
    case MOVE_TO_MARKETPLACE = 'move_to_marketplace';
    
    /**
     * Update affiliate links for selected products
     */
    case UPDATE_LINKS = 'update_links';
    
    /**
     * Clear cache for selected products
     */
    case CLEAR_CACHE = 'clear_cache';
    
    /**
     * Bulk edit metadata
     */
    case BULK_EDIT = 'bulk_edit';
    
    /**
     * Get display name for the action
     * 
     * @return string
     */
    public function displayName(): string
    {
        return match($this) {
            self::PUBLISH => 'Publish',
            self::UNPUBLISH => 'Unpublish',
            self::DELETE => 'Delete',
            self::HARD_DELETE => 'Permanently Delete',
            self::CHANGE_CATEGORY => 'Change Category',
            self::CHANGE_PRICE => 'Change Price',
            self::ADD_TAGS => 'Add Tags',
            self::REMOVE_TAGS => 'Remove Tags',
            self::EXPORT => 'Export',
            self::DUPLICATE => 'Duplicate',
            self::MOVE_TO_MARKETPLACE => 'Move to Marketplace',
            self::UPDATE_LINKS => 'Update Links',
            self::CLEAR_CACHE => 'Clear Cache',
            self::BULK_EDIT => 'Bulk Edit',
        };
    }
    
    /**
     * Get description for the action
     * 
     * @return string
     */
    public function description(): string
    {
        return match($this) {
            self::PUBLISH => 'Make selected products visible to users',
            self::UNPUBLISH => 'Hide selected products from users',
            self::DELETE => 'Move selected products to trash (can be restored)',
            self::HARD_DELETE => 'Permanently remove selected products (cannot be undone)',
            self::CHANGE_CATEGORY => 'Change category for all selected products',
            self::CHANGE_PRICE => 'Adjust price for selected products',
            self::ADD_TAGS => 'Add tags to selected products',
            self::REMOVE_TAGS => 'Remove tags from selected products',
            self::EXPORT => 'Export selected products to CSV/Excel/JSON',
            self::DUPLICATE => 'Create copies of selected products',
            self::MOVE_TO_MARKETPLACE => 'Move products to different marketplace section',
            self::UPDATE_LINKS => 'Update affiliate links for selected products',
            self::CLEAR_CACHE => 'Clear cache for selected products',
            self::BULK_EDIT => 'Edit multiple fields for selected products',
        };
    }
    
    /**
     * Check if action requires confirmation dialog
     * 
     * @return bool
     */
    public function requiresConfirmation(): bool
    {
        return in_array($this, [
            self::DELETE,
            self::HARD_DELETE,
            self::UNPUBLISH,
            self::CHANGE_PRICE,
            self::MOVE_TO_MARKETPLACE,
        ]);
    }
    
    /**
     * Check if action is destructive (can't be easily undone)
     * 
     * @return bool
     */
    public function isDestructive(): bool
    {
        return in_array($this, [
            self::DELETE,
            self::HARD_DELETE,
            self::REMOVE_TAGS,
            self::CLEAR_CACHE,
        ]);
    }
    
    /**
     * Check if action requires background processing
     * 
     * @return bool
     */
    public function requiresBackgroundProcessing(): bool
    {
        return in_array($this, [
            self::EXPORT,
            self::DUPLICATE,
            self::UPDATE_LINKS,
            self::CLEAR_CACHE,
            self::BULK_EDIT,
        ]);
    }
    
    /**
     * Check if action requires additional parameters
     * 
     * @return bool
     */
    public function requiresParameters(): bool
    {
        return in_array($this, [
            self::CHANGE_CATEGORY,
            self::CHANGE_PRICE,
            self::ADD_TAGS,
            self::REMOVE_TAGS,
            self::MOVE_TO_MARKETPLACE,
            self::UPDATE_LINKS,
            self::BULK_EDIT,
        ]);
    }
    
    /**
     * Get available parameters for this action
     * 
     * @return array
     */
    public function availableParameters(): array
    {
        return match($this) {
            self::CHANGE_CATEGORY => [
                'category_id' => ['type' => 'integer', 'required' => true]
            ],
            self::CHANGE_PRICE => [
                'price_adjustment_type' => [
                    'type' => 'string', 
                    'required' => true,
                    'options' => ['set', 'increase', 'decrease', 'percentage_increase', 'percentage_decrease']
                ],
                'price_value' => ['type' => 'float', 'required' => true]
            ],
            self::ADD_TAGS => [
                'tags' => ['type' => 'array', 'required' => true]
            ],
            self::REMOVE_TAGS => [
                'tags' => ['type' => 'array', 'required' => true]
            ],
            self::EXPORT => [
                'format' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'csv',
                    'options' => ['csv', 'excel', 'json']
                ],
                'include_images' => ['type' => 'boolean', 'required' => false, 'default' => false],
                'include_links' => ['type' => 'boolean', 'required' => false, 'default' => true],
            ],
            self::DUPLICATE => [
                'copy_images' => ['type' => 'boolean', 'required' => false, 'default' => true],
                'copy_links' => ['type' => 'boolean', 'required' => false, 'default' => true],
                'duplicate_count' => ['type' => 'integer', 'required' => false, 'default' => 1, 'min' => 1, 'max' => 10],
            ],
            self::MOVE_TO_MARKETPLACE => [
                'marketplace_id' => ['type' => 'integer', 'required' => true],
                'section_id' => ['type' => 'integer', 'required' => false],
            ],
            self::UPDATE_LINKS => [
                'link_updates' => ['type' => 'array', 'required' => true],
                'update_strategy' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'merge',
                    'options' => ['replace', 'merge', 'append']
                ],
            ],
            self::BULK_EDIT => [
                'fields' => ['type' => 'array', 'required' => true],
                'values' => ['type' => 'array', 'required' => true],
            ],
            default => [],
        };
    }
    
    /**
     * Get icon for the action (for UI)
     * 
     * @return string
     */
    public function icon(): string
    {
        return match($this) {
            self::PUBLISH => 'eye',
            self::UNPUBLISH => 'eye-slash',
            self::DELETE => 'trash',
            self::HARD_DELETE => 'trash-fill',
            self::CHANGE_CATEGORY => 'folder',
            self::CHANGE_PRICE => 'tag',
            self::ADD_TAGS => 'tag-plus',
            self::REMOVE_TAGS => 'tag-minus',
            self::EXPORT => 'download',
            self::DUPLICATE => 'copy',
            self::MOVE_TO_MARKETPLACE => 'arrow-right',
            self::UPDATE_LINKS => 'link',
            self::CLEAR_CACHE => 'broom',
            self::BULK_EDIT => 'pencil-square',
        };
    }
    
    /**
     * Get CSS class for the action (for UI styling)
     * 
     * @return string
     */
    public function cssClass(): string
    {
        return match($this) {
            self::PUBLISH => 'bg-green-100 text-green-800',
            self::UNPUBLISH => 'bg-yellow-100 text-yellow-800',
            self::DELETE => 'bg-red-100 text-red-800',
            self::HARD_DELETE => 'bg-red-200 text-red-900',
            self::CHANGE_CATEGORY => 'bg-blue-100 text-blue-800',
            self::CHANGE_PRICE => 'bg-purple-100 text-purple-800',
            self::ADD_TAGS => 'bg-indigo-100 text-indigo-800',
            self::REMOVE_TAGS => 'bg-pink-100 text-pink-800',
            self::EXPORT => 'bg-gray-100 text-gray-800',
            self::DUPLICATE => 'bg-teal-100 text-teal-800',
            self::MOVE_TO_MARKETPLACE => 'bg-orange-100 text-orange-800',
            self::UPDATE_LINKS => 'bg-cyan-100 text-cyan-800',
            self::CLEAR_CACHE => 'bg-gray-200 text-gray-900',
            self::BULK_EDIT => 'bg-violet-100 text-violet-800',
        };
    }
    
    /**
     * Get all actions that are safe for MVP
     * 
     * @return array
     */
    public static function mvpActions(): array
    {
        return [
            self::PUBLISH,
            self::UNPUBLISH,
            self::DELETE,
            self::CHANGE_CATEGORY,
            self::CHANGE_PRICE,
        ];
    }
    
    /**
     * Get all actions that are admin-only
     * 
     * @return array
     */
    public static function adminOnlyActions(): array
    {
        return [
            self::HARD_DELETE,
            self::EXPORT,
            self::DUPLICATE,
            self::MOVE_TO_MARKETPLACE,
            self::CLEAR_CACHE,
        ];
    }
    
    /**
     * Check if action is available in MVP
     * 
     * @return bool
     */
    public function isMvpAction(): bool
    {
        return in_array($this, self::mvpActions());
    }
    
    /**
     * Check if action is admin-only
     * 
     * @return bool
     */
    public function isAdminOnly(): bool
    {
        return in_array($this, self::adminOnlyActions());
    }
    
    /**
     * Get confirmation message for action
     * 
     * @param int $count Number of items affected
     * @return string
     */
    public function confirmationMessage(int $count = 1): string
    {
        $itemText = $count === 1 ? 'this product' : "these $count products";
        
        return match($this) {
            self::PUBLISH => "Are you sure you want to publish $itemText?",
            self::UNPUBLISH => "Are you sure you want to unpublish $itemText?",
            self::DELETE => "Are you sure you want to move $itemText to trash?",
            self::HARD_DELETE => "WARNING: This will permanently delete $itemText. This action cannot be undone. Are you sure?",
            self::CHANGE_CATEGORY => "Are you sure you want to change category for $itemText?",
            self::CHANGE_PRICE => "Are you sure you want to change price for $itemText?",
            self::ADD_TAGS => "Add tags to $itemText?",
            self::REMOVE_TAGS => "Remove tags from $itemText?",
            self::EXPORT => "Export $itemText?",
            self::DUPLICATE => "Duplicate $itemText?",
            self::MOVE_TO_MARKETPLACE => "Move $itemText to another marketplace?",
            self::UPDATE_LINKS => "Update links for $itemText?",
            self::CLEAR_CACHE => "Clear cache for $itemText?",
            self::BULK_EDIT => "Bulk edit $itemText?",
        };
    }
    
    /**
     * Get success message for action
     * 
     * @param int $count Number of items affected
     * @return string
     */
    public function successMessage(int $count = 1): string
    {
        $itemText = $count === 1 ? 'product' : 'products';
        
        return match($this) {
            self::PUBLISH => "$count $itemText published successfully",
            self::UNPUBLISH => "$count $itemText unpublished successfully",
            self::DELETE => "$count $itemText moved to trash",
            self::HARD_DELETE => "$count $itemText permanently deleted",
            self::CHANGE_CATEGORY => "Category updated for $count $itemText",
            self::CHANGE_PRICE => "Price updated for $count $itemText",
            self::ADD_TAGS => "Tags added to $count $itemText",
            self::REMOVE_TAGS => "Tags removed from $count $itemText",
            self::EXPORT => "$count $itemText exported successfully",
            self::DUPLICATE => "$count $itemText duplicated successfully",
            self::MOVE_TO_MARKETPLACE => "$count $itemText moved to marketplace",
            self::UPDATE_LINKS => "Links updated for $count $itemText",
            self::CLEAR_CACHE => "Cache cleared for $count $itemText",
            self::BULK_EDIT => "$count $itemText updated successfully",
        };
    }
}