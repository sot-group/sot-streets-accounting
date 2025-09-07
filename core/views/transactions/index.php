
<?php
if (!defined('ABSPATH')) exit;
global $wpdb;

$acc_tbl = $wpdb->prefix . 'sde_accounts';
$jr_tbl  = $wpdb->prefix . 'sde_journals';
$ln_tbl  = $wpdb->prefix . 'sde_journal_lines';
$cc_tbl  = $wpdb->prefix . 'sde_cost_centers';
$set_tbl = $wpdb->prefix . 'sde_settings';


$nonce_action = 'sdem_txn_all';
$veh_types = [
  'purchase'=>'Purchase',
  'sale'=>'Sale',
  'income'=>'Income',
  'maintenance'=>'Maintenance/Rep',
  'insurance'=>'Insurance',
  'registration'=>'Registration'
];


// Vehicles dropdown data (direct SQL against CPT 'sde_cost_center')
$cc_q = intval($_GET['cc_filter'] ?? 0);
$vehicles_map = [];

$posts_tbl = $wpdb->posts;
$meta_tbl  = $wpdb->postmeta;
$rows = $wpdb->get_results("
    SELECT p.ID, p.post_title,
           MAX(CASE WHEN pm.meta_key='sde_cc_code' THEN pm.meta_value END) AS code
      FROM {$posts_tbl} p
 LEFT JOIN {$meta_tbl} pm ON pm.post_id = p.ID
     WHERE p.post_type = 'sde_cost_center'
       AND p.post_status IN ('publish','draft')
  GROUP BY p.ID, p.post_title
  ORDER BY p.post_title ASC
");
foreach ($rows as $row){
    $label = $row->code ? $row->code : $row->post_title;
    $vehicles_map[intval($row->ID)] = $label;
}

// Legacy table fallback
if (empty($vehicles_map)){
    $legacy = $wpdb->prefix . 'sde_cost_centers';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy));
    if ($exists === $legacy){
        $rows2 = $wpdb->get_results("SELECT id, code, name FROM {$legacy} WHERE is_active=1 ORDER BY code ASC");
        foreach ($rows2 as $r){
            $label = $r->code ? $r->code : $r->name;
            $vehicles_map[-intval($r->id)] = $label;
        }
    }
}

// Options renderer
$cc_opts = function($sel) use ($vehicles_map){
    if (empty($vehicles_map)){
        return '<option value="">— No vehicles found —</option>';
    }
    $h = '<option value="">— All —</option>';
    foreach ($vehicles_map as $id=>$lab){
        $h .= '<option value="'.$id.'"'.((string)$sel===(string)$id?' selected':'').'>'.esc_html($lab).'</option>';
    }
    return $h;
};






$base = admin_url('admin.php?page=sde-modular-trans');

// ---------- POST actions (do these before any output) ----------
$action = isset($_POST['_sdem_do']) ? sanitize_text_field($_POST['_sdem_do']) : '';
if ($action){
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer($nonce_action);
    try {
        if ($action === 'add'){
            $trn_date = sanitize_text_field($_POST['trn_date'] ?? '');
            $desc     = sanitize_text_field($_POST['description'] ?? '');
            $dr_id    = intval($_POST['debit_account'] ?? 0);
            $cr_id    = intval($_POST['credit_account'] ?? 0);
            $amount   = floatval($_POST['amount'] ?? 0);
            

$cc1_raw = intval($_POST['cc1'] ?? 0);
$cc1 = '';
$cc1_id = 0;
if ($cc1_raw > 0){
    $cc1_id = $cc1_raw;
    $cc1 = get_post_meta($cc1_id, 'sde_cc_code', true);
    if (!$cc1) { $cc1 = get_the_title($cc1_id); }
} elseif ($cc1_raw < 0){
    $legacy_id = abs($cc1_raw);
    $tbl = $wpdb->prefix . 'sde_cost_centers';
    $row = $wpdb->get_row($wpdb->prepare("SELECT code, name FROM {$tbl} WHERE id=%d", $legacy_id));
    if ($row){
        $cc1 = $row->code ? $row->code : $row->name;
        $cc1_id = 0;
    }
}

if (!$trn_date || !$desc || $dr_id<=0 || $cr_id<=0 || $amount<=0) throw new Exception('Missing or invalid fields.');
            if ($dr_id === $cr_id) throw new Exception('Debit and Credit accounts must differ.');

            // next entry number
            $entry_no = intval($wpdb->get_var($wpdb->prepare("SELECT v FROM {$set_tbl} WHERE k=%s", 'next_entry_no')));
            if ($entry_no <= 0) { $entry_no = intval($wpdb->get_var("SELECT COALESCE(MAX(CAST(entry_no AS UNSIGNED)),0)+1 FROM {$jr_tbl}")); }

            $wpdb->query('START TRANSACTION');
            // Safety: ensure veh_trx_type column exists (in case schema migration hasn't run yet)
$__col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$jr_tbl} LIKE %s", 'veh_trx_type'));
if (!$__col){
    $wpdb->query("ALTER TABLE {$jr_tbl} ADD COLUMN veh_trx_type VARCHAR(32) NULL DEFAULT NULL");
    $wpdb->query("ALTER TABLE {$jr_tbl} ADD KEY veh_trx_type (veh_trx_type)");
}
$ok = $wpdb->insert($jr_tbl, [
                'entry_no'=>$entry_no, 'trn_date'=>$trn_date, 'description'=>$desc, 'veh_trx_type'=>sanitize_text_field($_POST['veh_trx_type'] ?? ''), 'created_at'=>current_time('mysql'),
            ], ['%d','%s','%s','%s','%s']);
            if (!$ok) throw new Exception('Failed to insert journal');
            $jid = intval($wpdb->insert_id);

            $ok = $wpdb->insert($ln_tbl, [
                'journal_id'=>$jid,'account_id'=>$dr_id,'debit'=>$amount,'credit'=>0,'cc'=>$cc1,'cc_id'=>$cc1_id,'created_at'=>current_time('mysql')
            ], ['%d','%d','%f','%f','%s','%s']);
            if (!$ok) throw new Exception('Failed to insert debit line');

            $ok = $wpdb->insert($ln_tbl, [
                'journal_id'=>$jid,'account_id'=>$cr_id,'debit'=>0,'credit'=>$amount,'cc'=>$cc1,'cc_id'=>$cc1_id,'created_at'=>current_time('mysql')
            ], ['%d','%d','%f','%f','%s','%s']);
            if (!$ok) throw new Exception('Failed to insert credit line');

            $wpdb->query($wpdb->prepare("UPDATE {$set_tbl} SET v=v+1 WHERE k=%s", 'next_entry_no'));
            $wpdb->query('COMMIT');
            if (!headers_sent()){ wp_redirect(add_query_arg(['msg'=>'added'], $base)); exit; }
            echo '<script>location.href='.json_encode(add_query_arg(['msg'=>'added'], $base)).'</script>'; exit;
        }
        if ($action === 'update'){
            $jid     = intval($_POST['jid'] ?? 0);
            $trn_date= sanitize_text_field($_POST['trn_date'] ?? '');
            $desc    = sanitize_text_field($_POST['description'] ?? '');
            $dr_id   = intval($_POST['debit_account'] ?? 0);
            $cr_id   = intval($_POST['credit_account'] ?? 0);
            $amount  = floatval($_POST['amount'] ?? 0);
            

$cc1_raw = intval($_POST['cc1'] ?? 0);
$cc1 = '';
$cc1_id = 0;
if ($cc1_raw > 0){
    $cc1_id = $cc1_raw;
    $cc1 = get_post_meta($cc1_id, 'sde_cc_code', true);
    if (!$cc1) { $cc1 = get_the_title($cc1_id); }
} elseif ($cc1_raw < 0){
    $legacy_id = abs($cc1_raw);
    $tbl = $wpdb->prefix . 'sde_cost_centers';
    $row = $wpdb->get_row($wpdb->prepare("SELECT code, name FROM {$tbl} WHERE id=%d", $legacy_id));
    if ($row){
        $cc1 = $row->code ? $row->code : $row->name;
        $cc1_id = 0;
    }
}

if ($jid<=0 || !$trn_date || !$desc || $dr_id<=0 || $cr_id<=0 || $amount<=0) throw new Exception('Missing or invalid fields.');
            if ($dr_id === $cr_id) throw new Exception('Debit and Credit accounts must differ.');

            $wpdb->query('START TRANSACTION');
            $wpdb->update($jr_tbl, ['trn_date'=>$trn_date,'description'=>$desc,'veh_trx_type'=>sanitize_text_field($_POST['veh_trx_type'] ?? '')], ['id'=>$jid], ['%s','%s','%s'], ['%d']);
            $wpdb->query($wpdb->prepare("DELETE FROM {$ln_tbl} WHERE journal_id=%d", $jid));
            $wpdb->insert($ln_tbl, [
                'journal_id'=>$jid,'account_id'=>$dr_id,'debit'=>$amount,'credit'=>0,'cc'=>$cc1,'cc_id'=>$cc1_id,'created_at'=>current_time('mysql')
            ], ['%d','%d','%f','%f','%s','%s']);
            $wpdb->insert($ln_tbl, [
                'journal_id'=>$jid,'account_id'=>$cr_id,'debit'=>0,'credit'=>$amount,'cc'=>$cc1,'cc_id'=>$cc1_id,'created_at'=>current_time('mysql')
            ], ['%d','%d','%f','%f','%s','%s']);
            $wpdb->query('COMMIT');
            if (!headers_sent()){ wp_redirect(add_query_arg(['msg'=>'updated'], $base)); exit; }
            echo '<script>location.href='.json_encode(add_query_arg(['msg'=>'updated'], $base)).'</script>'; exit;
        }
        if ($action === 'delete'){
            $jid = intval($_POST['jid'] ?? 0);
            if ($jid<=0) throw new Exception('Invalid journal id');
            $wpdb->query('START TRANSACTION');
            $wpdb->query($wpdb->prepare("DELETE FROM {$ln_tbl} WHERE journal_id=%d", $jid));
            $wpdb->query($wpdb->prepare("DELETE FROM {$jr_tbl} WHERE id=%d", $jid));
            $wpdb->query('COMMIT');
            if (!headers_sent()){ wp_redirect(add_query_arg(['msg'=>'deleted'], $base)); exit; }
            echo '<script>location.href='.json_encode(add_query_arg(['msg'=>'deleted'], $base)).'</script>'; exit;
        }
    } catch (Exception $e){
        $wpdb->query('ROLLBACK');
        if (!headers_sent()){ wp_redirect(add_query_arg(['msg'=>'error','errm'=>$e->getMessage()], $base)); exit; }
        echo '<script>location.href='.json_encode(add_query_arg(['msg'=>'error','errm'=>$e->getMessage()], $base)).'</script>'; exit;
    }
}

// ---------- Helpers ----------
$acc_opts = function($selected=0) use ($wpdb,$acc_tbl){
    $rows = $wpdb->get_results("SELECT id, code, name FROM {$acc_tbl} ORDER BY code");
    $html = '<option value="0">— select —</option>';
    foreach ((array)$rows as $r){
        $label = esc_html($r->code.' — '.$r->name);
        $sel = (intval($selected)===intval($r->id)) ? ' selected' : '';
        $html .= '<option value="'.esc_attr(intval($r->id)).'"'.$sel.'>'.$label.'</option>';
    }
    return $html;
};
$cc_opts_legacy = function($selected='') use ($wpdb,$cc_tbl){
    $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cc_tbl)) === $cc_tbl);
    $html = '<option value="">— none —</option>';
    if ($exists){
        $rows = $wpdb->get_results("SELECT code, name FROM {$cc_tbl} ORDER BY code");
        foreach ((array)$rows as $r){
            $sel = ($selected!=='' && $selected===$r->code) ? ' selected' : '';
            $label = esc_html($r->code . ($r->name ? ' — '.$r->name : ''));
            $html .= '<option value="'.esc_attr($r->code).'"'.$sel.'>'.$label.'</option>';
        }
    }
    return $html;
};

// ---------- Filters ----------
$today = current_time('Y-m-d');
$y = date('Y', strtotime($today));
$q = ceil(date('n', strtotime($today))/3);
$y_start = $y.'-01-01'; $y_end = $y.'-12-31';
$q_start = date('Y-m-01', strtotime($y.'-'.(3*($q-1)+1).'-01'));
$q_end   = date('Y-m-t', strtotime($y.'-'.(3*$q).'-01'));
$m_start = date('Y-m-01', strtotime($today)); $m_end = date('Y-m-t', strtotime($today));

$from = sanitize_text_field($_GET['from'] ?? $y_start);
$to   = sanitize_text_field($_GET['to'] ?? $y_end);
$entry_q = sanitize_text_field($_GET['entry'] ?? '');
$desc_q  = sanitize_text_field($_GET['desc'] ?? '');

// ---------- Output: header + filters ----------
echo '<div class="wrap"><h1>Transactions</h1>';

$msg = sanitize_text_field($_GET['msg'] ?? '');
if ($msg==='added')   echo '<div class="notice notice-success"><p>Transaction added.</p></div>';
if ($msg==='updated') echo '<div class="notice notice-success"><p>Transaction updated.</p></div>';
if ($msg==='deleted') echo '<div class="notice notice-warning"><p>Transaction deleted.</p></div>';
if ($msg==='error')   echo '<div class="notice notice-error"><p>Error: '.esc_html(sanitize_text_field($_GET['errm'] ?? 'Error')).'</p></div>';

echo '<form method="get" style="margin:10px 0">';
echo '<input type="hidden" name="page" value="sde-modular-trans" />';
echo '<div style="display:grid;grid-template-columns:repeat(12,minmax(80px,auto));gap:10px;align-items:end">';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-weight:600">From</span><input type="date" name="from" value="'.esc_attr($from).'" /></div>';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-weight:600">To</span><input type="date" name="to" value="'.esc_attr($to).'" /></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-trans','from'=>$y_start,'to'=>$y_end], admin_url('admin.php'))).'">YTD</a></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-trans','from'=>$q_start,'to'=>$q_end], admin_url('admin.php'))).'">QTD</a></div>';
echo '<div style="grid-column:span 1"><a class="button" href="'.esc_url(add_query_arg(['page'=>'sde-modular-trans','from'=>$m_start,'to'=>$m_end], admin_url('admin.php'))).'">MTD</a></div>';
echo '<div style="grid-column:span 3"></div>';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-weight:600">Entry #</span><input type="text" name="entry" value="'.esc_attr($entry_q).'" placeholder="#" /></div>';
echo '<div style="grid-column:span 6;display:flex;flex-direction:column"><span style="font-weight:600">Description</span><input type="text" name="desc" value="'.esc_attr($desc_q).'" placeholder="search description" /></div>';
echo '<div style="grid-column:span 3;display:flex;flex-direction:column"><span style="font-size:12px;color:#666">Vehicle</span><select name="cc_filter">' . $cc_opts($cc_q) . '</select></div>';

echo '<div style="grid-column:span 3;display:flex;gap:8px;align-items:end"><button class="button button-primary">Apply</button></div>';
echo '</div>';
echo '</form>';

// New Transaction (one row, 7% save column)
echo '<div class="postbox" style="padding:12px;margin:12px 0">';
echo '<h2 style="margin:0 0 8px;">New Transaction</h2>';
echo '<form method="post" action="'.$base.'" style="display:grid;grid-template-columns:9% 23% 10% 10% 12% 13% 13% 7%;gap:12px;align-items:end">';
wp_nonce_field($nonce_action);
echo '<input type="hidden" name="_sdem_do" value="add" />';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Date</span><input style="width:100%" type="date" name="trn_date" value="'.esc_attr($today).'" required /></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Description</span><input style="width:100%" type="text" name="description" placeholder="Description" required /></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Debit account</span><select style="width:100%" name="debit_account" required>'.$acc_opts(0).'</select></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Credit account</span><select style="width:100%" name="credit_account" required>'.$acc_opts(0).'</select></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Amount</span><input style="width:100%" type="number" step="0.01" min="0.01" name="amount" placeholder="0.00" required /></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Type</span><select style="width:100%" name="veh_trx_type">';
foreach ($veh_types as $vk=>$vl){ echo '<option value="'.esc_attr($vk).'">'.esc_html($vl).'</option>'; }
echo '</select></div>';
echo '<div style="display:flex;flex-direction:column"><span style="font-weight:600">Vehicle (optional)</span><select style="width:100%" name="cc1">'.$cc_opts('').'</select></div>';
echo '<div><button class="button button-primary" style="width:100%">Save</button></div>';
echo '</form>';
echo '</div>';

// Build WHERE and rows
$where = $wpdb->prepare("WHERE j.trn_date BETWEEN %s AND %s", $from, $to);
if ($entry_q !== ''){ $where .= $wpdb->prepare(" AND j.entry_no=%d", intval($entry_q)); }
if ($desc_q !== ''){ $like = '%%'.$wpdb->esc_like($desc_q).'%%'; $where .= $wpdb->prepare(" AND j.description LIKE %s", $like); }

$sql = "
SELECT j.id, j.entry_no, j.trn_date, j.description,
       MAX(CASE WHEN l.debit  > 0 THEN l.account_id END) AS dr_id,
       MAX(CASE WHEN l.credit > 0 THEN l.account_id END) AS cr_id,
       MAX(l.debit)  AS amount_debit,
       MAX(l.credit) AS amount_credit,
       MAX(l.cc)     AS cc,
       MAX(l.cc_id)  AS cc_id
FROM {$jr_tbl} j
JOIN {$ln_tbl} l ON l.journal_id=j.id
{$where}
GROUP BY j.id, j.entry_no, j.trn_date, j.description
ORDER BY j.entry_no DESC, j.trn_date DESC
LIMIT 500
";
$rows = $wpdb->get_results($sql);

$accs = $wpdb->get_results("SELECT id, code, name FROM {$acc_tbl}");
$amap = []; foreach ((array)$accs as $a){ $amap[intval($a->id)] = $a->code.' — '.$a->name; }

// Table with strict 8 columns
echo '<h2 style="margin:16px 0 8px;">All Transactions</h2>';
echo '<table class="widefat fixed striped" style="table-layout:fixed;width:100%">';
echo '<colgroup><col style="width:5%"><col style="width:5%"><col style="width:30%"><col style="width:15%"><col style="width:15%"><col style="width:10%"><col style="width:10%"><col style="width:10%"></colgroup>';
echo '<thead><tr><th>Doc #</th><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th><th class="num">Amount</th><th>Vehicle</th><th>Actions</th></tr></thead><tbody>';

$edit_id = intval($_GET['edit'] ?? 0);
if ($rows){
    foreach ($rows as $r){
        $jid = intval($r->id);
        $doc = (string)$r->entry_no;
        $amt = (float)($r->amount_debit ?: $r->amount_credit ?: 0);
        $dr_label = isset($amap[intval($r->dr_id)]) ? esc_html($amap[intval($r->dr_id)]) : '—';
        $cr_label = isset($amap[intval($r->cr_id)]) ? esc_html($amap[intval($r->cr_id)]) : '—';
        if ($edit_id === $jid){
            echo '<tr>';
            echo '<td>'.esc_html($doc).'</td>';
            echo '<td><input type="date" name="trn_date" form="f'.$jid.'" value="'.esc_attr($r->trn_date).'" /></td>';
            echo '<td><input type="text" name="description" form="f'.$jid.'" value="'.esc_attr($r->description).'" style="width:100%" /></td>';
            $cur_cc = intval($wpdb->get_var($wpdb->prepare("SELECT MAX(cc_id) FROM {$ln_tbl} WHERE journal_id=%d", $jid))); echo '<td><select name="debit_account" form="f'.$jid.'">'.$acc_opts(intval($r->dr_id)).'</select></td>';
            echo '<td><select name="credit_account" form="f'.$jid.'">'.$acc_opts(intval($r->cr_id)).'</select></td>';
            echo '<td class="num" style="text-align:right"><input type="number" step="0.01" min="0.01" name="amount" form="f'.$jid.'" value="'.esc_attr(number_format($amt,2,'.','')).'" /></td>';
            echo '<td><select name="veh_trx_type" form="f'.$jid.'">'.(function($v,$opts){$h='';foreach($opts as $k=>$l){$sel=($v===$k)?' selected':'';$h.='<option value="'.esc_attr($k).'"'.$sel.'>'.esc_html($l).'</option>'; } return $h;})($wpdb->get_var($wpdb->prepare("SELECT veh_trx_type FROM {$jr_tbl} WHERE id=%d", $jid)),$veh_types).'</select></td><td><select name="cc1" form="f'.$jid.'">'.$cc_opts($cur_cc).'</select></td>';
            echo '<td>';
            echo '<form method="post" id="f'.$jid.'" style="display:inline">'; wp_nonce_field($nonce_action);
            echo '<input type="hidden" name="_sdem_do" value="update" />';
            echo '<input type="hidden" name="jid" value="'.$jid.'" />';
            echo '<button class="button button-primary">Save</button> ';
            echo '<a class="button" href="'.esc_url($base).'">Cancel</a>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        } else {
            echo '<tr>';
            echo '<td>'.esc_html($doc).'</td>';
            echo '<td>'.esc_html( date('d/m/Y', strtotime($r->trn_date)) ).'</td>';
            echo '<td>'.esc_html($r->description).'</td>';
            echo '<td>'.$dr_label.'</td>';
            echo '<td>'.$cr_label.'</td>';
            echo '<td class="num" style="text-align:right">'.esc_html(number_format($amt,2,'.',',')).'</td>';
            echo '<td>' . ( $r->cc_id ? ('<a href="'.esc_url(get_edit_post_link(intval($r->cc_id))).'">'.esc_html($r->cc ?: get_the_title(intval($r->cc_id))).'</a>') : esc_html($r->cc ?: '—') ) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="'.esc_url(add_query_arg(['page'=>'sde-modular-trans','edit'=>$jid], admin_url('admin.php'))).'">Edit</a> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this entry?\')">'; wp_nonce_field($nonce_action);
            echo '<input type="hidden" name="_sdem_do" value="delete" />';
            echo '<input type="hidden" name="jid" value="'.$jid.'" />';
            echo '<button class="button button-small">Delete</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
    }
} else {
    echo '<tr><td colspan="8">No transactions found.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
