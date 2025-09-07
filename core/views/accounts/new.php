<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

global $wpdb;
$acc_tbl = $wpdb->prefix . 'sde_accounts';

$msg = sanitize_text_field($_GET['msg'] ?? '');
if ($msg === 'created') {
    echo '<div class="notice notice-success"><p>Account created.</p></div>';
}
$err = sanitize_text_field($_GET['err'] ?? '');
if ($err) {
    echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
}

if (isset($_POST['_sdem_do']) && $_POST['_sdem_do']==='acc_create'){
    check_admin_referer('sdem_acc_create');
    $code = strtoupper(trim(sanitize_text_field($_POST['code'] ?? '')));
    $name = trim(sanitize_text_field($_POST['name'] ?? ''));
    $type = strtolower(trim(sanitize_text_field($_POST['type'] ?? '')));

    $allowed = ['asset','liability','equity','revenue','expense'];
    if ($code === '' || $name === '' || !in_array($type, $allowed, true)) {
        $u = add_query_arg(['page'=>'sde-modular-account-new','err'=>'Please fill all fields correctly.'], admin_url('admin.php'));
        if (!headers_sent()) { wp_safe_redirect($u); exit; }
        echo '<script>location.href=' . json_encode($u) . ';</script>'; exit;
    }

    // unique code
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$acc_tbl} WHERE code=%s", $code));
    if ($exists) {
        $u = add_query_arg(['page'=>'sde-modular-account-new','err'=>'Account code already exists.'], admin_url('admin.php'));
        if (!headers_sent()) { wp_safe_redirect($u); exit; }
        echo '<script>location.href=' . json_encode($u) . ';</script>'; exit;
    }

    $wpdb->insert($acc_tbl, [
        'code'=>$code,
        'name'=>$name,
        'type'=>$type,
        'is_active'=>1,
        'created_at'=>current_time('mysql'),
    ], ['%s','%s','%s','%d','%s']);

    $u = add_query_arg(['page'=>'sde-modular','msg'=>'acc_created'], admin_url('admin.php'));
    if (!headers_sent()) { wp_safe_redirect($u); exit; }
    echo '<script>location.href=' . json_encode($u) . ';</script>'; exit;
}

echo '<div class="wrap"><h1>Add New Account</h1>';
echo '<form method="post" style="max-width:720px">';
wp_nonce_field('sdem_acc_create');
echo '<input type="hidden" name="_sdem_do" value="acc_create" />';
echo '<table class="form-table"><tbody>';
echo '<tr><th scope="row"><label for="code">Code</label></th><td><input name="code" id="code" type="text" required pattern="[A-Za-z0-9\-\.]{1,20}" /></td></tr>';
echo '<tr><th scope="row"><label for="name">Name</label></th><td><input name="name" id="name" type="text" required style="width:420px" /></td></tr>';
echo '<tr><th scope="row"><label for="type">Type</label></th><td><select name="type" id="type">';
foreach (['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','revenue'=>'Revenue','expense'=>'Expense'] as $k=>$v){
    echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';
}
echo '</select></td></tr>';
echo '</tbody></table>';
echo '<p><button class="button button-primary">Create Account</button> ';
echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=sde-modular')).'">Back to Accounts</a></p>';
echo '</form></div>';
