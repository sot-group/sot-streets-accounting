<?php
/*
Plugin Name: SOT Accounting Suite
Description: SOT Accounting core + VIK importer + CSV/TSV import for transactions.
Version: 1.2.2
Author: SOT
*/
// Run schema on activation (guarded)
register_activation_hook(__FILE__, function(){
    if (!defined('ABSPATH')) { return; }
    try {
        require_once plugin_dir_path(__FILE__) . 'core/includes/core/schema.php';
        if (class_exists('SDEM\Core\Schema')) {
            SDEM\Core\Schema::install();
        }
    } catch (\Throwable $e) {
        if (function_exists('error_log')) { error_log('[SOT Accounting] Activation error: '.$e->getMessage()); }
    }
});

function sde_suite_next_entry_no(){
    global $wpdb; $jr_tbl = $wpdb->prefix . 'sde_journals';
    $max = $wpdb->get_var("SELECT MAX(CAST(entry_no AS UNSIGNED)) FROM {$jr_tbl}");
    if (!$max) $max = 0;
    return (string)($max + 1);
}

// --- Doc Number Helpers ------------------------------------------------------
/**
 * Next numeric Doc # for manual entry & CSV import.
 * Uses sde_journals.entry_no as integer space.
 */
if (!function_exists('sde_suite_next_entry_no')){
    function sde_suite_next_entry_no(){
        global $wpdb; $jr_tbl = $wpdb->prefix . 'sde_journals';
        $max = $wpdb->get_var("SELECT MAX(CAST(entry_no AS UNSIGNED)) FROM {$jr_tbl}");
        if (!$max) $max = 0;
        return (string)($max + 1);
    }
}

/**
 * Generate the next Doc # for a given prefix (e.g., 'E' -> E-XX).
 * Looks up the max numeric suffix among existing entries with that prefix.
 */
if (!function_exists('sde_suite_next_prefixed_entry_no')){
    function sde_suite_next_prefixed_entry_no($prefix){
        global $wpdb; $jr_tbl = $wpdb->prefix . 'sde_journals';
        $prefix = preg_replace('/[^A-Za-z]/','',$prefix);
        if ($prefix==='') $prefix='E';
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(entry_no,'-',-1) AS UNSIGNED)) FROM {$jr_tbl} WHERE entry_no LIKE %s",
            $prefix . '-%'
        ));
        if (!$max) $max = 0;
        return $prefix . '-' . (string)($max + 1);
    }
}

/**
 * VIK importer helper: 'B-<order_no>'
 */
if (!function_exists('sde_suite_doc_no_for_vik')){
    function sde_suite_doc_no_for_vik($order_no){
        $num = preg_replace('/[^0-9]/','', (string)$order_no);
        if ($num==='') $num='0';
        return 'B-' . $num;
    }
}



if (!defined('ABSPATH')) exit;

/** Load the bundled core Accounting plugin */
define('SDE_SUITE_DIR', plugin_dir_path(__FILE__));
define('SDE_SUITE_URL', plugin_dir_url(__FILE__));

// Load core plugin files (as-is)
require_once SDE_SUITE_DIR . 'core/sde-modular.php';

/** Add-on: Menu declutter */
add_action('admin_menu', function(){
    global $submenu;
    $parent = 'sde-modular';
    if (!isset($submenu[$parent]) || !is_array($submenu[$parent])) return;
    $hide = array('VICCAR Sync', 'VIKCar Sync', 'Health', 'Account', 'Add Account');
    $new = array();
    foreach ($submenu[$parent] as $row){
        $title = is_array($row) && isset($row[0]) ? wp_strip_all_tags($row[0]) : '';
        if (!in_array($title, $hide, true)) $new[] = $row;
    }
    $submenu[$parent] = $new;
}, 1000);

/** Maintenance: accept Entry No or Journal ID and normalize */
add_action('save_post_sde_cc_maintenance', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;
    $raw = isset($_POST['journal_id']) ? trim((string)$_POST['journal_id']) : get_post_meta($post_id, 'journal_id', true);
    if ($raw === '' || $raw === null) return;
    global $wpdb;
    $jr_tbl = $wpdb->prefix . 'sde_journals';
    $maybe = intval($raw);
    $ok_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$jr_tbl} WHERE id=%d", $maybe));
    if ($ok_id){ update_post_meta($post_id, 'journal_id', intval($ok_id)); return; }
    $by_no = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$jr_tbl} WHERE entry_no=%s", $raw));
    if ($by_no){ update_post_meta($post_id, 'journal_id', intval($by_no)); return; }
    $raw_trim = preg_replace('/\s+/', '', $raw);
    if ($raw_trim !== $raw){
        $by_no2 = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$jr_tbl} WHERE REPLACE(entry_no,' ','')=%s", $raw_trim));
        if ($by_no2){ update_post_meta($post_id, 'journal_id', intval($by_no2)); return; }
    }
}, 20);

/** Activation: import log table */
register_activation_hook(__FILE__, function(){
    global $wpdb;
    $tbl = $wpdb->prefix . 'sde_import_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$tbl} (
        order_id INT UNSIGNED PRIMARY KEY,
        imported_at DATETIME NOT NULL,
        journal_id BIGINT UNSIGNED NULL
    ) {$charset}";
    $wpdb->query($sql);
});

/** Helpers */
function sde_suite_lookup_account_id_by_code($code){
    global $wpdb; $code = (string)$code;
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sde_accounts WHERE code=%s", $code));
    return $id ? intval($id) : 0;
}
function sde_suite_lookup_cc_by_plate($plate){
    global $wpdb; if (!$plate) return 0;
    $plate_norm = preg_replace('/\s+/', '', $plate);
    $sql = "SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type='sde_cost_center' AND p.post_status='publish'
            WHERE pm.meta_key IN ('vik_reg','sde_cc_code')
              AND (pm.meta_value=%s OR REPLACE(pm.meta_value,' ','')=%s)
            LIMIT 1";
    $cc = $wpdb->get_var($wpdb->prepare($sql, $plate, $plate_norm));
    return $cc ? intval($cc) : 0;
}
function sde_suite_parse_extras_total($extracosts){
    if (!is_string($extracosts) || $extracosts==='') return 0.0;
    $arr = json_decode($extracosts, true);
    if (!is_array($arr)) $arr = json_decode(stripslashes($extracosts), true);
    $sum = 0.0;
    if (is_array($arr)){
        foreach ($arr as $item){
            if (is_array($item)){
                foreach (array('total','gross','price','amount','cost','value') as $k){
                    if (isset($item[$k]) && is_numeric($item[$k])){ $sum += floatval($item[$k]); break; }
                }
            }
        }
    }
    return $sum;
}

/** Plate resolution from VIK */
function sde_suite_lookup_reg_from_car($vikcar_id, $carindex){
    global $wpdb; $p = $wpdb->prefix;
    $params = $wpdb->get_var($wpdb->prepare("SELECT params FROM {$p}vikrentcar_cars WHERE id=%d", intval($vikcar_id)));
    if (!$params) return '';
    if ($carindex){
        $carindex_str = (string)intval($carindex);
        if (preg_match('/\"features\"\s*:\s*\{(.*?)\}\s*/is', $params, $m)){
            $features = $m[1];
            $re = '/\"'.preg_quote($carindex_str,'/').'\"\s*:\s*\{[^}]*\"Registration\"\s*:\s*\"([^"]+)\"/i';
            if (preg_match($re, $features, $m2)) return trim($m2[1]);
        }
    }
    if (preg_match('/\"Registration\"\s*:\s*\"([^"]+)\"/i', $params, $m3)) return trim($m3[1]);
    if (preg_match('/Registration[^:]*:\s*([A-Z0-9\-\s]+)/i', $params, $m4)) return trim($m4[1]);
    return '';
}
function sde_suite_resolve_cc_from_vikcar($vikcar_id){
    global $wpdb; $p = $wpdb->prefix;
    $row = $wpdb->get_row($wpdb->prepare("SELECT name, params FROM {$p}vikrentcar_cars WHERE id=%d", intval($vikcar_id)), ARRAY_A);
    $params = $row ? $row['params'] : '';
    $carname = $row ? $row['name'] : '';
    if ($params){
        $reg = '';
        if (preg_match('/\"Registration\"\s*:\s*\"([^"]+)\"/i', $params, $m)) $reg = trim($m[1]);
        if (!$reg && preg_match('/Registration[^:]*:\s*([A-Z0-9\-\s]+)/i', $params, $m2)) $reg = trim($m2[1]);
        if ($reg){ $cc = sde_suite_lookup_cc_by_plate($reg); if ($cc) return $cc; }
    }
    if ($carname){
        $like = '%' . $wpdb->esc_like($carname) . '%';
        $cc2 = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='sde_cost_center' AND post_status='publish' AND post_title LIKE %s LIMIT 1", $like));
        if ($cc2) return intval($cc2);
    }
    return 0;
}
function sde_suite_resolve_cc_from_orderrow($row){
    $reg = '';
    if (!empty($row['order_reg'])) $reg = trim($row['order_reg']);
    if (!$reg && !empty($row['order_carindex'])) $reg = sde_suite_lookup_reg_from_car($row['vikcar_id'], intval($row['order_carindex']));
    if ($reg){
        $cc = sde_suite_lookup_cc_by_plate($reg);
        if ($cc) return $cc;
    }
    return sde_suite_resolve_cc_from_vikcar(intval($row['vikcar_id']));
}

/** Admin UI: VIKCar Import */
add_action('admin_menu', function(){
    add_submenu_page('sde-modular','VIKCar Import','VIKCar Import','manage_options','sde-vik-import','sde_suite_render_vik_import_page');
}, 1001);

function sde_suite_fetch_dryrun_rows(){
    global $wpdb; $p = $wpdb->prefix;
    $sql = $wpdb->prepare(
        "SELECT o.id AS order_id,
                FROM_UNIXTIME(o.ritiro) AS start_date,
                TRIM(COALESCE(NULLIF(o.nominative,''), SUBSTRING_INDEX(SUBSTRING_INDEX(o.custdata,'Name:',-1), %s, 1))) AS renter_name,
                CASE SUBSTRING_INDEX(o.idpayment,'=',1)
                  WHEN '4' THEN '1000' WHEN '1' THEN '1020' WHEN '3' THEN '1020' WHEN '2' THEN '1030' ELSE '1020' END AS debit_acct,
                CASE
                  WHEN (FIND_IN_SET('1', REPLACE(c.idcat,';',','))>0 OR FIND_IN_SET('2', REPLACE(c.idcat,';',','))>0) THEN '4201'
                  WHEN (FIND_IN_SET('7', REPLACE(c.idcat,';',','))>0) THEN '4301'
                  ELSE '4101'
                END AS credit_acct,
                COALESCE(o.order_total, o.totpaid, 0) AS amount,
                o.idcar AS vikcar_id,
                o.reg AS order_reg,
                o.carindex AS order_carindex,
                o.extracosts AS extracosts_json
         FROM {$p}vikrentcar_orders o
         LEFT JOIN {$p}vikrentcar_cars c ON c.id = o.idcar
         WHERE FROM_UNIXTIME(o.ritiro) <= CURDATE() - INTERVAL 1 DAY
           AND o.status IN ('confirmed','paid','closed')
           AND COALESCE(o.order_total, o.totpaid, 0) > 0
         ORDER BY o.ritiro DESC
         LIMIT 1000",
        "\n"
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!$rows) return array();
    foreach ($rows as &$r){
        $amt = floatval($r['amount']);
        $extras = sde_suite_parse_extras_total(isset($r['extracosts_json']) ? $r['extracosts_json'] : '');
        if ($extras < 0) $extras = 0.0;
        if ($extras > $amt) $extras = $amt;
        $r['extras'] = $extras;
        $r['base']   = $amt - $extras;
    }
    return $rows;
}

function sde_suite_render_vik_import_page(){
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>VIKCar Import</h1>';

    if (isset($_POST['act']) && check_admin_referer('sde_vik_import')){
        if ($_POST['act']==='commit'){
            $rows = sde_suite_fetch_dryrun_rows();
            $res = sde_suite_commit_import($rows);
            echo '<div class="notice notice-success"><p>Imported: '.intval($res['inserted']).' — Skipped: '.intval($res['skipped']).' — Errors: '.intval($res['errs']).'</p></div>';
            $diag = get_transient('sde_suite_vik_diag');
            if (is_array($diag)){
                echo '<div class="notice"><p><strong>Details</strong> — Missing accounts: '.intval($diag['err_missing_acc']).', Journal insert fails: '.intval($diag['err_journal']).', Line insert fails: '.intval($diag['err_lines']).', No vehicle match (not an error): '.intval($diag['cc_missing']).'</p></div>';
            }
        }
    }

    echo '<form method="post">'; wp_nonce_field('sde_vik_import');
    echo '<p><button class="button" name="act" value="dry">Dry-Run</button> ';
    echo '<button class="button button-primary" name="act" value="commit">Import (Commit)</button></p></form><hr/>';

    $rows = sde_suite_fetch_dryrun_rows();
    echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Name</th><th>Debit</th><th>Credit</th><th>Total</th><th>Base</th><th>Extras</th><th>VIK Car</th><th>Reg</th><th>Idx</th></tr></thead><tbody>';
    foreach ($rows as $r){
        echo '<tr>';
        echo '<td>'.esc_html($r['order_id']).'</td>';
        echo '<td>'.esc_html($r['start_date']).'</td>';
        echo '<td>'.esc_html($r['renter_name']).'</td>';
        echo '<td>'.esc_html($r['debit_acct']).'</td>';
        echo '<td>'.esc_html($r['credit_acct']).'</td>';
        echo '<td>'.esc_html(number_format((float)$r['amount'],2)).'</td>';
        echo '<td>'.esc_html(number_format((float)$r['base'],2)).'</td>';
        echo '<td>'.esc_html(number_format((float)$r['extras'],2)).'</td>';
        echo '<td>'.esc_html($r['vikcar_id']).'</td>';
        echo '<td>'.esc_html($r['order_reg']).'</td>';
        echo '<td>'.esc_html($r['order_carindex']).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function sde_suite_commit_import($rows){
    global $wpdb; $p = $wpdb->prefix;
    $jr_tbl = "{$p}sde_journals";
    $ln_tbl = "{$p}sde_journal_lines";
    $log_tbl = "{$p}sde_import_log";

    $inserted = 0; $skipped = 0; $errs = 0; $err_missing_acc=0; $err_journal=0; $err_lines=0; $cc_missing=0;

    foreach ($rows as $r){
        $order_id = intval($r['order_id']); if (!$order_id){ $skipped++; continue; }
        $exists = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$log_tbl} WHERE order_id=%d", $order_id));
        if ($exists){ $skipped++; continue; }

        $date = substr($r['start_date'],0,10);
        $desc = $r['renter_name'];
        $amt  = (float)$r['amount'];
        $base = (float)$r['base'];
        $extras = (float)$r['extras'];
        if ($amt <= 0){ $skipped++; continue; }

        $debit_acc_id  = sde_suite_lookup_account_id_by_code($r['debit_acct']);
        $credit_acc_id = sde_suite_lookup_account_id_by_code($r['credit_acct']);
        if (!$debit_acc_id || !$credit_acc_id){ $errs++; $err_missing_acc++; continue; }

        $cc_id = sde_suite_resolve_cc_from_orderrow($r);
        if (!$cc_id) $cc_missing++;

        $ok = $wpdb->insert($jr_tbl, array(
            'trn_date'=>$date,'description'=>$desc,'veh_trx_type'=>'Income'
        ), array('%s','%s','%s'));
        if (!$ok){ $errs++; $err_journal++; continue; }
        $jid = $wpdb->insert_id;

        $ok1 = $wpdb->insert($ln_tbl, array('journal_id'=>$jid,'account_id'=>$debit_acc_id,'debit'=>$amt,'credit'=>0,'cc_id'=>null), array('%d','%d','%f','%f','%d'));
        $ok2 = true; if ($base > 0){
            $ok2 = $wpdb->insert($ln_tbl, array('journal_id'=>$jid,'account_id'=>$credit_acc_id,'debit'=>0,'credit'=>$base,'cc_id'=>$cc_id ?: null), array('%d','%d','%f','%f','%d'));
        }
        $ok3 = true; if ($extras > 0){
            $ok3 = $wpdb->insert($ln_tbl, array('journal_id'=>$jid,'account_id'=>$credit_acc_id,'debit'=>0,'credit'=>$extras,'cc_id'=>null), array('%d','%d','%f','%f','%d'));
        }
        if (!$ok1 || !$ok2 || !$ok3){ $errs++; $err_lines++; continue; }

        $wpdb->insert($log_tbl, array('order_id'=>$order_id,'imported_at'=>current_time('mysql'),'journal_id'=>$jid), array('%d','%s','%d'));
        $inserted++;
    }

    set_transient('sde_suite_vik_diag', array(
        'err_missing_acc'=>$err_missing_acc,
        'err_journal'=>$err_journal,
        'err_lines'=>$err_lines,
        'cc_missing'=>$cc_missing
    ), 60);

    return compact('inserted','skipped','errs');
}

/** Vehicle page: VIK Mapping box */
add_action('add_meta_boxes', function(){
    add_meta_box('sde_suite_vik_map','VIK Mapping','sde_suite_render_vik_map_box','sde_cost_center','side','default');
});
function sde_suite_render_vik_map_box($post){
    wp_nonce_field('sde_suite_vik_map_save','sde_suite_vik_map_nonce');
    $vik_reg = get_post_meta($post->ID, 'vik_reg', true);
    $vik_car_id = get_post_meta($post->ID, 'vik_car_id', true);
    $vik_carindex = get_post_meta($post->ID, 'vik_carindex', true);
    echo '<p><label>VIK Registration (plate)</label><br/><input type="text" name="vik_reg" value="'.esc_attr($vik_reg).'" class="widefat" placeholder="e.g. S 9929 TL"/></p>';
    echo '<p><label>VIK Car ID</label><br/><input type="number" name="vik_car_id" value="'.esc_attr($vik_car_id).'" class="widefat" placeholder="e.g. 6"/></p>';
    echo '<p><label>VIK Car Index</label><br/><input type="number" name="vik_carindex" value="'.esc_attr($vik_carindex).'" class="widefat" placeholder="e.g. 12"/></p>';
    echo '<p class="description">Fill plate to force CC linking. Optionally add Car ID/Index when multiple units exist per model.</p>';
}
add_action('save_post_sde_cost_center', function($post_id){
    if (!isset($_POST['sde_suite_vik_map_nonce']) || !wp_verify_nonce($_POST['sde_suite_vik_map_nonce'],'sde_suite_vik_map_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post',$post_id)) return;
    $fields = array('vik_reg','vik_car_id','vik_carindex');
    foreach ($fields as $f){
        if (isset($_POST[$f])){
            $val = sanitize_text_field($_POST[$f]);
            if ($f==='vik_car_id' || $f==='vik_carindex') $val = preg_replace('/[^0-9]/','',$val);
            update_post_meta($post_id, $f, $val);
        }
    }
}, 10);
