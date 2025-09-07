<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$tables = [
    $wpdb->prefix.'sde_accounts',
    $wpdb->prefix.'sde_cost_centers',
    $wpdb->prefix.'sde_journals',
    $wpdb->prefix.'sde_journal_lines',
    $wpdb->prefix.'sde_settings'
];
echo '<div class="wrap"><h1>Health Check</h1><ul>';
foreach ($tables as $t){
    $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t);
    echo '<li>'.esc_html($t).': '.($exists?'<span style="color:green">OK</span>':'<span style="color:red">Missing</span>').'</li>';
}
echo '</ul><p>If any are missing, deactivate & re-activate the plugin to run the installer.</p></div>';
