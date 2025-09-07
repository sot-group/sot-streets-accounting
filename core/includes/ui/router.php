<?php
namespace SDEM\UI;
if (!defined('ABSPATH')) exit;

class Router {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_filter('parent_file', function($parent){
            $screen = get_current_screen();
            if ($screen && $screen->post_type === 'sde_cost_center') return 'sde-modular';
            return $parent;
        });
        add_filter('submenu_file', function($submenu){
            $screen = get_current_screen();
            if ($screen && $screen->post_type === 'sde_cost_center') return 'sde-modular-vehicles';
            return $submenu;
        });
        add_action('admin_enqueue_scripts', function($hook){
            if (strpos($hook, 'sde-modular') === false) return;
            wp_enqueue_style('sdem-admin', SDEM_URL . 'assets/css/admin.css', [], SDEM_VER);
            wp_enqueue_script('sdem-admin', SDEM_URL . 'assets/js/admin.js', ['jquery'], SDEM_VER, true);
        });
    }

    public static function menu(){
        // Top level
        add_menu_page('S.O.T. Accounting', 'S.O.T. Accounting', 'manage_options', 'sde-modular', [__CLASS__, 'page_accounts'], 'dashicons-chart-line', 42);

        // Submenus (grouped under Accounts)
        add_submenu_page('sde-modular', 'Accounts', 'Accounts', 'edit_posts', 'sde-modular', [__CLASS__, 'page_accounts']);
        add_submenu_page('sde-modular', 'Account', 'Account', 'edit_posts', 'sde-modular-account', [__CLASS__, 'page_account']);
        add_submenu_page('sde-modular', 'Add Account', 'Add Account', 'edit_posts', 'sde-modular-account-new', [__CLASS__, 'page_account_new']);

        // Other features
        add_submenu_page('sde-modular', 'Transactions', 'Transactions', 'edit_posts', 'sde-modular-trans', [__CLASS__, 'page_transactions']);
        add_submenu_page('sde-modular', 'Vehicles', 'Vehicles', 'edit_posts', 'sde-modular-vehicles', [__CLASS__, 'page_vehicles']);
                add_submenu_page('sde-modular', 'VIKCar Sync', 'VIKCar Sync', 'edit_posts', 'sde-modular-vik', [__CLASS__, 'page_vik']);
        add_submenu_page('sde-modular', 'Health', 'Health', 'edit_posts', 'sde-modular-health', [__CLASS__, 'page_health']);
        add_submenu_page('sde-modular', 'Import (Transactions)', 'Import (Transactions)', 'manage_options', 'sde-modular-import', [__CLASS__, 'page_import_transactions']);
    }

    public static function page_accounts(){ require_once SDEM_PATH . 'views/accounts/index-wrapper.php'; }
    public static function page_account(){ require_once SDEM_PATH . 'views/accounts/detail.php'; }
    public static function page_account_new(){ require_once SDEM_PATH . 'views/accounts/new.php'; }
    public static function page_transactions(){ require_once SDEM_PATH . 'views/transactions/index.php'; }
    public static function page_import_transactions(){ require_once SDEM_PATH . 'views/import/transactions.php'; }

    public static function page_vehicles(){
        // Keep user inside Accounting menu while viewing Vehicles (CPT admin)
        $url = admin_url('edit.php?post_type=sde_cost_center');
        if (!headers_sent()) { wp_safe_redirect($url); exit; }
        echo '<script>window.location.href=' . json_encode($url) . ';</script>'; exit;
    }

    public static function page_keynumbers(){
        echo '<div class="wrap"><h1>Key Numbers</h1><p>Milestone M4 stub.</p></div>';
    }
    public static function page_vik(){
        echo '<div class="wrap"><h1>VIKCar Sync</h1><p>Milestone M5 stub (mapping & import).</p></div>';
    }
    public static function page_health(){
        require_once SDEM_PATH . 'views/partials/health.php';
    }
}
