<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class InitialSchema extends Migration
{
    public function up()
    {
        // 1. Table: ADMINS
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'username' => ['type' => 'VARCHAR', 'constraint' => 50],
            'email' => ['type' => 'VARCHAR', 'constraint' => 100],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'role' => ['type' => 'ENUM', 'constraint' => ['admin', 'super_admin'], 'default' => 'admin'],
            'active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_login' => ['type' => 'DATETIME', 'null' => true],
            'login_attempts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('username');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey(['active', 'role']);
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('admins', true);

        // 2. Table: CATEGORIES
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'parent_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 100],
            'icon' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'fas fa-folder'],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey(['active', 'sort_order']);
        $this->forge->addKey('parent_id');
        $this->forge->createTable('categories', true);

        // 3. Table: BADGES
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'label' => ['type' => 'VARCHAR', 'constraint' => 100],
            'color' => ['type' => 'CHAR', 'constraint' => 7, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('badges', true);

        // 4. Table: MARKETPLACES
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 100],
            'icon' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'color' => ['type' => 'CHAR', 'constraint' => 7, 'default' => '#64748b'],
            'active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('marketplaces', true);

        // 5. Table: MARKETPLACE_BADGES
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'label' => ['type' => 'VARCHAR', 'constraint' => 100],
            'icon' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'color' => ['type' => 'CHAR', 'constraint' => 7, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('marketplace_badges', true);

        // 6. Table: PRODUCTS
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'category_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['draft', 'pending_verification', 'verified', 'published', 'archived'], 'default' => 'draft'],
            'published_at' => ['type' => 'DATETIME', 'null' => true],
            'verified_at' => ['type' => 'DATETIME', 'null' => true],
            'verified_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 255],
            'image' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'description' => ['type' => 'TEXT', 'null' => true],
            'market_price' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => '0.00'],
            'view_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'image_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'image_source_type' => ['type' => 'ENUM', 'constraint' => ['upload', 'url'], 'default' => 'url', 'null' => true],
            'last_price_check' => ['type' => 'DATETIME', 'null' => true],
            'last_link_check' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('created_at'); // idx_products_active_created (simplified)
        $this->forge->addKey('category_id');
        $this->forge->addKey('view_count');
        $this->forge->addKey(['status', 'published_at']);
        $this->forge->addKey('verified_by');
        $this->forge->addKey('last_price_check');
        $this->forge->addKey('last_link_check');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'SET NULL', 'UPDATE');
        $this->forge->addForeignKey('verified_by', 'admins', 'id', 'SET NULL', 'UPDATE');
        $this->forge->createTable('products', true);

        // 7. Table: LINKS
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'marketplace_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'store_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'price' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => '0.00'],
            'url' => ['type' => 'TEXT', 'null' => true],
            'rating' => ['type' => 'DECIMAL', 'constraint' => '3,2', 'default' => '0.00'],
            'active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sold_count' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'clicks' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'last_price_update' => ['type' => 'DATETIME', 'null' => true],
            'last_validation' => ['type' => 'DATETIME', 'null' => true],
            'affiliate_revenue' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => '0.00'],
            'marketplace_badge_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('marketplace_id');
        $this->forge->addKey(['product_id', 'marketplace_id']);
        $this->forge->addKey(['price', 'product_id']);
        $this->forge->addKey('marketplace_badge_id');
        $this->forge->addKey(['active', 'created_at']);
        $this->forge->addKey(['product_id', 'active', 'price']);
        $this->forge->addKey('clicks');
        $this->forge->addKey('last_price_update');
        $this->forge->addKey('last_validation');
        $this->forge->addKey('affiliate_revenue');
        $this->forge->addKey(['active', 'clicks']);
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'UPDATE');
        $this->forge->addForeignKey('marketplace_id', 'marketplaces', 'id', 'CASCADE', 'UPDATE');
        $this->forge->addForeignKey('marketplace_badge_id', 'marketplace_badges', 'id', 'SET NULL', 'UPDATE');
        $this->forge->createTable('links', true);

        // 8. Table: PRODUCT_BADGES
        $this->forge->addField([
            'product_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'badge_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'assigned_at' => ['type' => 'DATETIME', 'default' => null], // manual default needed for current_timestamp in CI4 raw sql usually better
            'assigned_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['product_id', 'badge_id']);
        $this->forge->addKey(['badge_id', 'product_id']);
        $this->forge->addKey('assigned_at');
        $this->forge->addKey('assigned_by');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'UPDATE');
        $this->forge->addForeignKey('badge_id', 'badges', 'id', 'CASCADE', 'UPDATE');
        $this->forge->addForeignKey('assigned_by', 'admins', 'id', 'SET NULL', 'UPDATE');
        $this->forge->createTable('product_badges', true);
        
        // Manual Query for Default Timestamp (CI4 Forge limitation on current_timestamp)
        // $this->db->query("ALTER TABLE product_badges ALTER COLUMN assigned_at SET DEFAULT CURRENT_TIMESTAMP");

        // 9. Table: AUDIT_LOGS
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'admin_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'old_values' => ['type' => 'LONGTEXT', 'null' => true],
            'new_values' => ['type' => 'LONGTEXT', 'null' => true],
            'changes_summary' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'TEXT', 'null' => true],
            'performed_at' => ['type' => 'DATETIME', 'null' => true], // Manual default logic preferred in Model or SQL
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['admin_id', 'entity_type', 'entity_id']);
        $this->forge->addKey('action_type');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('performed_at');
        $this->forge->addForeignKey('admin_id', 'admins', 'id', 'SET NULL', 'UPDATE');
        $this->forge->createTable('audit_logs', true);
        
        // 10. Table: DATABASE_VERSIONS (Optional - usually CI4 handles migrations table itself)
        // Skipping database_versions as CI4 uses 'migrations' table.
    }

    public function down()
    {
        // Drop in reverse order to respect Foreign Keys
        $this->forge->dropTable('audit_logs', true);
        $this->forge->dropTable('product_badges', true);
        $this->forge->dropTable('links', true);
        $this->forge->dropTable('products', true);
        $this->forge->dropTable('marketplace_badges', true);
        $this->forge->dropTable('marketplaces', true);
        $this->forge->dropTable('badges', true);
        $this->forge->dropTable('categories', true);
        $this->forge->dropTable('admins', true);
    }
}
