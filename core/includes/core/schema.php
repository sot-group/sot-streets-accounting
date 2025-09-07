<?php
namespace SDEM\Core;
if (!defined('ABSPATH')) exit;
class Schema {
    public static function install(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $accounts = $wpdb->prefix . 'sde_accounts';
        $costcenters = $wpdb->prefix . 'sde_cost_centers';
        $journals = $wpdb->prefix . 'sde_journals';
// Ensure entry_no column supports alphanumeric; convert to VARCHAR if numeric
$colInfo = $wpdb->get_row("SHOW COLUMNS FROM {$journals} LIKE 'entry_no'");
if ($colInfo && stripos($colInfo->Type, 'varchar') === false){
    $wpdb->query("ALTER TABLE {$journals} MODIFY COLUMN entry_no VARCHAR(50) NOT NULL");
}
// Ensure unique index on entry_no
$has_idx = $wpdb->get_var("SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$journals}' AND INDEX_NAME='entry_no'");
if (!$has_idx){
    $wpdb->query("ALTER TABLE {$journals} ADD UNIQUE KEY entry_no (entry_no)");
}
// Ensure cc_id exists on journal lines
$has_ccid = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$lines} LIKE %s", 'cc_id'));
if (!$has_ccid){
    $wpdb->query("ALTER TABLE {$lines} ADD COLUMN cc_id BIGINT UNSIGNED NULL DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$lines} ADD KEY cc_id (cc_id)");
}
// Ensure ref_code exists for external reference codes (B-..., E-...)
$has_ref = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$journals} LIKE %s", 'ref_code'));
if (!$has_ref){
    $wpdb->query("ALTER TABLE {$journals} ADD COLUMN ref_code VARCHAR(50) NULL DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$journals} ADD KEY ref_code (ref_code)");
}

        $lines = $wpdb->prefix . 'sde_journal_lines';
        $settings = $wpdb->prefix . 'sde_settings';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$accounts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;");
        dbDelta("CREATE TABLE {$costcenters} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(255) NULL,
            source VARCHAR(32) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;");
        dbDelta("CREATE TABLE {$journals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_no BIGINT UNSIGNED NOT NULL,
            trn_date DATE NOT NULL,
            description VARCHAR(255) NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY trn_date (trn_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            journal_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            debit DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            cc VARCHAR(64) NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY journal_id (journal_id),
            KEY account_id (account_id),
            KEY cc (cc)
        ) $charset;");
        dbDelta("CREATE TABLE {$settings} (
            k VARCHAR(64) NOT NULL,
            v LONGTEXT NULL,
            PRIMARY KEY (k)
        ) $charset;");
        $exists = $wpdb->get_var($wpdb->prepare("SELECT k FROM {$settings} WHERE k=%s", 'next_entry_no'));
        if (!$exists){ $wpdb->insert($settings, ['k'=>'next_entry_no','v'=>'1']); }


        // Ensure unique index on entry_no exists (avoid dbDelta duplicate-key errors)
        $has_idx = $wpdb->get_var("SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$journals}' AND INDEX_NAME='entry_no'");
        if (!$has_idx){
            $wpdb->query("ALTER TABLE {$journals} ADD UNIQUE KEY entry_no (entry_no)");
        }

// Ensure cc_id exists on journal lines
$has_ccid = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$lines} LIKE %s", 'cc_id'));
if (!$has_ccid){
    $wpdb->query("ALTER TABLE {$lines} ADD COLUMN cc_id BIGINT UNSIGNED NULL DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$lines} ADD KEY cc_id (cc_id)");
}

    }
}


        // Ensure veh_trx_type exists on journals
        $has_type = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$journals} LIKE %s", 'veh_trx_type'));
        if (!$has_type){
            $wpdb->query("ALTER TABLE {$journals} ADD COLUMN veh_trx_type VARCHAR(32) NULL DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$journals} ADD KEY veh_trx_type (veh_trx_type)");
        }


        // Ensure account flags exist
        $accounts = $wpdb->prefix . 'sde_accounts';
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$accounts} LIKE %s", 'is_cash'));
        if (!$col){ $wpdb->query("ALTER TABLE {$accounts} ADD COLUMN is_cash TINYINT(1) NOT NULL DEFAULT 0"); }
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$accounts} LIKE %s", 'is_ar'));
        if (!$col){ $wpdb->query("ALTER TABLE {$accounts} ADD COLUMN is_ar TINYINT(1) NOT NULL DEFAULT 0"); }
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$accounts} LIKE %s", 'is_ap'));
        if (!$col){ $wpdb->query("ALTER TABLE {$accounts} ADD COLUMN is_ap TINYINT(1) NOT NULL DEFAULT 0"); }
    