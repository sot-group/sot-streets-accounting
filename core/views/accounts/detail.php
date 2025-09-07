
<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$acc_tbl = $wpdb->prefix.'sde_accounts';
$ln_tbl  = $wpdb->prefix.'sde_journal_lines';
$jr_tbl  = $wpdb->prefix.'sde_journals';

// Handle POST first: update account and redirect
if (isset($_POST['_sdem_do']) && $_POST['_sdem_do']==='acc_flags_update'){
    if (!current_user_can('edit_posts')) wp_die('Insufficient permissions');
    check_admin_referer('sdem_acc_flags');
    $acc_id = intval($_POST['acc_id'] ?? 0);
    $is_cash = isset($_POST['is_cash']) ? 1 : 0;
    $is_ar   = isset($_POST['is_ar']) ? 1 : 0;
    $is_ap   = isset($_POST['is_ap']) ? 1 : 0;
    if ($acc_id>0){ $wpdb->update($acc_tbl, ['is_cash'=>$is_cash,'is_ar'=>$is_ar,'is_ap'=>$is_ap], ['id'=>$acc_id], ['%d','%d','%d'], ['%d']); }
    $u = add_query_arg(['page'=>'sde-modular-account','acc'=>$acc_id,'msg'=>'acc_updated'], admin_url('admin.php'));
    if (!headers_sent()){ wp_safe_redirect($u); exit; } else { echo '<script>location.href='.json_encode($u).'</script>'; exit; }
}
// Handle POST first: update account and redirect
if (isset($_POST['_sdem_do']) && $_POST['_sdem_do']==='acc_update'){
    if (!current_user_can('edit_posts')) wp_die('Insufficient permissions');
    check_admin_referer('sdem_acc_update');
    $acc_id = intval($_POST['acc_id'] ?? 0);
    $new_code = sanitize_text_field($_POST['code'] ?? '');
    $new_name = sanitize_text_field($_POST['name'] ?? '');
    if ($acc_id > 0 && $new_code !== '' && $new_name !== ''){
        $wpdb->update($acc_tbl, ['code'=>$new_code,'name'=>$new_name], ['id'=>$acc_id], ['%s','%s'], ['%d']);
        $redir = add_query_arg(['page'=>'sde-modular','msg'=>'acc_updated'], admin_url('admin.php'));
    } else {
        $redir = add_query_arg(['page'=>'sde-modular-account','acc'=>$acc_id,'msg'=>'acc_err'], admin_url('admin.php'));
    }
    if (!headers_sent()){ wp_safe_redirect($redir); exit; } else { echo '<script>location.href=' . json_encode($redir) . '</script>'; exit; }
}


// Handle DELETE account
if (isset($_POST['_sdem_do']) && $_POST['_sdem_do']==='acc_delete'){
    if (!current_user_can('edit_posts')) wp_die('Insufficient permissions');
    check_admin_referer('sdem_acc_delete');
    $acc_id_del = intval($_POST['acc_id'] ?? 0);
    if ($acc_id_del > 0){
        $in_use = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$ln_tbl} WHERE account_id=%d", $acc_id_del)));
        if ($in_use > 0){
            $u = add_query_arg(['page'=>'sde-modular-account','acc'=>$acc_id_del,'msg'=>'acc_delete_blocked'], admin_url('admin.php'));
        } else {
            $wpdb->delete($acc_tbl, ['id'=>$acc_id_del], ['%d']);
            $u = add_query_arg(['page'=>'sde-modular','msg'=>'acc_deleted'], admin_url('admin.php'));
        }
        if (!headers_sent()){ wp_safe_redirect($u); exit; } else { echo '<script>location.href=' . json_encode($u) . '</script>'; exit; }
    }
}
$acc_id = intval($_GET['acc'] ?? 0);
echo '<div class="wrap"><h1>Account</h1>';
if ($acc_id <= 0){ echo '<p>Missing account id.</p></div>'; return; }

$acc_row = $wpdb->get_row($wpdb->prepare("SELECT id, code, name FROM {$acc_tbl} WHERE id=%d", $acc_id));
if (!$acc_row){ echo '<p>Account not found.</p></div>'; return; }

$msg = sanitize_text_field($_GET['msg'] ?? '');
if ($msg==='acc_err') echo '<div class="notice notice-error"><p>Could not update account. Please check values.</p></div>';

// Date filters
$today = current_time('Y-m-d');
$y = date('Y', strtotime($today));
$q = ceil(date('n', strtotime($today))/3);
$y_start = $y.'-01-01'; $y_end = $y.'-12-31';
$q_start = date('Y-m-01', strtotime($y.'-'.(3*($q-1)+1).'-01'));
$q_end   = date('Y-m-t', strtotime($y.'-'.(3*$q).'-01'));
$m_start = date('Y-m-01', strtotime($today)); $m_end = date('Y-m-t', strtotime($today));

$from = sanitize_text_field($_GET['from'] ?? $y_start);
$to   = sanitize_text_field($_GET['to'] ?? $y_end);

// CSV export after filters are known
$do_export = (isset($_GET['export']) && $_GET['export']==='csv');
if ($do_export){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=account-' . $acc_id . '-' . $from . '_to_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Doc','Description','Debit','Credit','Vehicle']);
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT j.id, j.entry_no, j.trn_date, j.description,
               SUM(CASE WHEN jl.debit  > 0 THEN jl.debit  ELSE 0 END) AS dr,
               SUM(CASE WHEN jl.credit > 0 THEN jl.credit ELSE 0 END) AS cr,
               MAX(jl.cc_id) AS cc_id
          FROM {$jr_tbl} j
          JOIN {$ln_tbl} jl ON jl.journal_id=j.id
         WHERE j.trn_date BETWEEN %s AND %s
           AND EXISTS (SELECT 1 FROM {$ln_tbl} jl2 WHERE jl2.journal_id=j.id AND jl2.account_id=%d)
         GROUP BY j.id, j.entry_no, j.trn_date, j.description
         ORDER BY j.trn_date ASC, j.entry_no ASC
    ", $from, $to, $acc_id));
    foreach ($rows as $r){
        $veh = '';
        if ($r->cc_id){
            $veh = get_post_meta(intval($r->cc_id), 'sde_cc_code', true);
            if (!$veh) $veh = get_the_title(intval($r->cc_id));
        }
        fputcsv($out, [$r->trn_date, $r->entry_no, $r->description, number_format((float)$r->dr,2,'.',''), number_format((float)$r->cr,2,'.',''), $veh]);
    }
    fclose($out);
    exit;
}


// Header + filters
echo '<h2>' . esc_html($acc_row->code . ' — ' . $acc_row->name) . '</h2>';

echo '<form method="get" style="margin:10px 0">';
echo '<input type="hidden" name="page" value="sde-modular-account" />';
echo '<input type="hidden" name="acc" value="'.intval($acc_row->id).'" />';
echo '<div style="display:grid;grid-template-columns:repeat(12,minmax(80px,auto));gap:10px;align-items:end">';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-weight:600">From</span><input type="date" name="from" value="'.esc_attr($from).'" /></div>';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-weight:600">To</span><input type="date" name="to" value="'.esc_attr($to).'" /></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-account','acc'=>intval($acc_row->id),'from'=>$y_start,'to'=>$y_end], admin_url('admin.php'))).'">YTD</a></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-account','acc'=>intval($acc_row->id),'from'=>$q_start,'to'=>$q_end], admin_url('admin.php'))).'">QTD</a></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-account','acc'=>intval($acc_row->id),'from'=>$m_start,'to'=>$m_end], admin_url('admin.php'))).'">MTD</a></div>';
echo '<div style="grid-column:span 3;display:flex;gap:8px;align-items:end"><button class="button button-primary">Apply</button> <a class="button" href="'.esc_url(add_query_arg(['export'=>'csv'])).'">Export CSV</a></div>';
echo '</div>';
echo '</form>';
echo '<form method="post" onsubmit="return confirm(\'Delete this account? This cannot be undone.\')">';
wp_nonce_field('sdem_acc_delete');
echo '<input type="hidden" name="_sdem_do" value="acc_delete" />';
echo '<input type="hidden" name="acc_id" value="'.intval($acc_id).'" />';
echo '<p><button class="button button-link-delete">Delete Account</button></p>';
echo '</form>';


// Edit form
echo '<form method="post" style="display:grid;grid-template-columns:220px 1fr 1fr 160px;gap:10px;align-items:end;margin:10px 0">';
wp_nonce_field('sdem_acc_update');
echo '<input type="hidden" name="_sdem_do" value="acc_update" />';
echo '<input type="hidden" name="acc_id" value="'.intval($acc_row->id).'" />';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Account Code</span><input type="text" name="code" value="'.esc_attr($acc_row->code).'" required /></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Account Name</span><input type="text" name="name" value="'.esc_attr($acc_row->name).'" required /></div>';
echo '<div></div>';
echo '<div><button class="button button-primary">Save</button></div>';
echo '</form>';
echo '<form method="post" onsubmit="return confirm(\'Delete this account? This cannot be undone.\')">';
wp_nonce_field('sdem_acc_delete');
echo '<input type="hidden" name="_sdem_do" value="acc_delete" />';
echo '<input type="hidden" name="acc_id" value="'.intval($acc_id).'" />';
echo '<p><button class="button button-link-delete">Delete Account</button></p>';
echo '</form>';


// Ledger query filtered by date
$sql = "
SELECT j.id, j.entry_no, j.trn_date, j.description,
       MAX(CASE WHEN l.account_id=%d THEN l.cc END) AS cc_code,
       SUM(CASE WHEN l.account_id=%d THEN l.debit  ELSE 0 END) AS dr,
       SUM(CASE WHEN l.account_id=%d THEN l.credit ELSE 0 END) AS cr
FROM {$jr_tbl} j
JOIN {$ln_tbl} l ON l.journal_id=j.id
WHERE j.trn_date BETWEEN %s AND %s
  AND EXISTS (SELECT 1 FROM {$ln_tbl} lx WHERE lx.journal_id=j.id AND lx.account_id=%d)
GROUP BY j.id, j.entry_no, j.trn_date, j.description
ORDER BY j.trn_date ASC, j.entry_no ASC
";
$rows = $wpdb->get_results($wpdb->prepare($sql, $acc_id, $acc_id, $acc_id, $from, $to, $acc_id));

$fmt = function($n){ return number_format((float)$n, 2, '.', ','); };
echo '<table class="widefat fixed striped" style="table-layout:fixed;width:100%">';
echo '<colgroup><col style="width:10%"><col style="width:15%"><col style="width:45%"><col style="width:10%"><col style="width:10%"><col style="width:10%"></colgroup>';
echo '<thead><tr><th>Doc #</th><th>Date</th><th>Description</th><th>CC</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
$tD=0; $tC=0;
if ($rows){
    foreach ($rows as $r){
        $tD += (float)$r->dr; $tC += (float)$r->cr;
        echo '<tr>';
        echo '<td>'.esc_html(intval($r->entry_no)).'</td>';
        echo '<td>'.esc_html(date('d/m/Y', strtotime($r->trn_date))).'</td>';
        echo '<td>'.esc_html($r->description).'</td>';
                    $cc_code = isset($r->cc_code) ? $r->cc_code : '';
                    if ($cc_code){
                        $pid = 0; $posts = get_posts(['post_type'=>'sde_cost_center','meta_key'=>'sde_cc_code','meta_value'=>$cc_code,'posts_per_page'=>1,'fields'=>'ids']);
                        if (!empty($posts)) $pid = intval($posts[0]);
                        if ($pid){ $cc_html = '<a target="_blank" rel="noopener" href="'.esc_url( add_query_arg(['post'=>$pid,'action'=>'edit'], admin_url('post.php')) ).'">'.esc_html($cc_code).'</a>'; }
                        else { $cc_html = esc_html($cc_code); }
                    } else { $cc_html = '—'; }
                    echo '<td>'.$cc_html.'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($r->dr)).'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($r->cr)).'</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="5">No entries for this period.</td></tr>';
}
echo '<tr style="font-weight:600;background:#f6f7f7"><td colspan="4">Total</td><td class="num" style="text-align:right">'.esc_html($fmt($tD)).'</td><td class="num" style="text-align:right">'.esc_html($fmt($tC)).'</td></tr>';
echo '</tbody></table>';
echo '<p>';
echo '<button class="button button-primary">Save</button> ';
echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=sde-modular')).'">Back</a> ';
echo '</p>';

echo '<hr style="margin:18px 0" />';
echo '<h2>Account Flags</h2>';
$acc_flags = $wpdb->get_row($wpdb->prepare("SELECT is_cash,is_ar,is_ap FROM {$acc_tbl} WHERE id=%d", $acc_id));
$is_cash = $acc_flags ? intval($acc_flags->is_cash) : 0;
$is_ar   = $acc_flags ? intval($acc_flags->is_ar)   : 0;
$is_ap   = $acc_flags ? intval($acc_flags->is_ap)   : 0;
echo '<form method="post">';
wp_nonce_field('sdem_acc_flags');
echo '<input type="hidden" name="_sdem_do" value="acc_flags_update" />';
echo '<input type="hidden" name="acc_id" value="'.intval($acc_id).'" />';
echo '<table class="form-table"><tbody>';
echo '<tr><th scope="row"><label>Cash / Bank account</label></th><td><label><input type="checkbox" name="is_cash" '.($is_cash?'checked':'').' /> Treat as cash/bank</label></td></tr>';
echo '<tr><th scope="row"><label>Accounts Receivable</label></th><td><label><input type="checkbox" name="is_ar" '.($is_ar?'checked':'').' /> Mark as AR</label></td></tr>';
echo '<tr><th scope="row"><label>Accounts Payable</label></th><td><label><input type="checkbox" name="is_ap" '.($is_ap?'checked':'').' /> Mark as AP</label></td></tr>';
echo '</tbody></table>';
echo '<p><button class="button button-primary">Save Flags</button></p>';
echo '</form>';


echo '</div>';
