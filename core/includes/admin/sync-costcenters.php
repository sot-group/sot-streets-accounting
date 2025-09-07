
<?php
if (!defined('ABSPATH')) exit;

function sde_cc_sync_from_vik_run() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('sde_cc_sync');
    global $wpdb;

    $cars_table = $wpdb->prefix . 'vikrentcar_cars';
    $rows = $wpdb->get_results("SELECT id, name, units, params FROM `{$cars_table}`", ARRAY_A);

    $inserted = 0; $updated = 0; $errors = 0;
    foreach ((array)$rows as $car){
        $car_id = intval($car['id']);
        $model  = trim($car['name'] ?? '');
        $params = json_decode($car['params'] ?? '', true);
        if (!is_array($params)) $params = [];
        $features = $params['features'] ?? [];

        foreach ((array)$features as $idx => $u){
            if (!is_array($u)) continue;

            // Try to determine a label/code
            $registration = '';
            foreach (['Registration', 'Registration Nr.', 'registration', 'plate'] as $k){
                if (!empty($u[$k])){ $registration = trim($u[$k]); break; }
            }
            $nickname = !empty($u['Nickname']) ? trim($u['Nickname']) : '';
            $label = $registration ?: $nickname ?: ('Unit #'.$idx);

            $cc_code = preg_replace('/\s+/', ' ', $label);
            $cc_name = ($model ? $model.' — ' : '') . $label;
            $cc_type = (stripos($model, 'starlink') !== false) ? 'Starlink' : 'Vehicle';
            $ext_key = 'vikcar:'.$car_id.':'.$idx;

            // Find existing CC post by external key
            $existing = get_posts([
                'post_type'   => 'sde_cost_center',
                'meta_key'    => 'sde_cc_external',
                'meta_value'  => $ext_key,
                'fields'      => 'ids',
                'post_status' => ['publish','draft'],
                'numberposts' => 1,
            ]);

            $postarr = [
                'post_type'   => 'sde_cost_center',
                'post_status' => 'publish',
                'post_title'  => $cc_name,
                'post_excerpt'=> $registration,
            ];

            if ($existing){
                $pid = intval($existing[0]);
                $postarr['ID'] = $pid;
                $res = wp_update_post($postarr, true);
                if (is_wp_error($res)){ $errors++; continue; }
                $updated++;
            } else {
                $res = wp_insert_post($postarr, true);
                if (is_wp_error($res)){ $errors++; continue; }
                $pid = intval($res);
                $inserted++;
            }

            // Meta
            update_post_meta($pid, 'sde_cc_code', $cc_code);
            update_post_meta($pid, 'sde_cc_type', $cc_type);
            update_post_meta($pid, 'sde_cc_external', $ext_key);
            update_post_meta($pid, 'sde_cc_model', $model);
            if ($registration) update_post_meta($pid, 'sde_cc_registration', $registration);
            if ($nickname)     update_post_meta($pid, 'sde_cc_nickname', $nickname);
            update_post_meta($pid, 'sde_cc_source_unit', wp_json_encode($u));
        }
    }

    $back = admin_url('admin.php?page=sde-modular-cc-sync&synced=1&ins='.$inserted.'&upd='.$updated.'&err='.$errors);
    if (!headers_sent()){ wp_safe_redirect($back); exit; }
    echo '<script>window.location.href=' . json_encode($back) . ';</script>';
    exit;
}

echo '<div class="wrap"><h1>Cost Center Sync</h1>';

if (isset($_GET['synced'])){
    $ins = intval($_GET['ins'] ?? 0);
    $upd = intval($_GET['upd'] ?? 0);
    $err = intval($_GET['err'] ?? 0);
    echo '<div class="notice notice-success"><p>Sync finished. Inserted: '.esc_html($ins).', Updated: '.esc_html($upd).', Errors: '.esc_html($err).'</p></div>';
}

$run_url = wp_nonce_url(admin_url('admin.php?page=sde-modular-cc-sync&do=run'), 'sde_cc_sync');
echo '<p><a class="button button-primary" href="'.esc_url($run_url).'">Run Sync from VikRentCar</a></p>';

if (isset($_GET['do']) && $_GET['do']==='run'){
    sde_cc_sync_from_vik_run();
}

echo '</div>';
