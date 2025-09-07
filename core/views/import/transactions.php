<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')){
    wp_die(__('Sorry, you are not allowed to import transactions.'));
}
global $wpdb; $p = $wpdb->prefix;
$jr_tbl = "{$p}sde_journals";
$ln_tbl = "{$p}sde_journal_lines";

$report = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('sde_import_txn')){
    $blob = isset($_POST['import_blob']) ? trim(stripslashes($_POST['import_blob'])) : '';
    $rows = [];
    if ($blob){
        $lines = preg_split('/\r?\n/', $blob);
        foreach ($lines as $line){
            $line = trim($line);
            if ($line==='') continue;
            $parts = preg_split('/\t+/', $line);
            if (count($parts) < 5){ $parts = str_getcsv($line); }
            $date = $parts[0] ?? ''; $doc = $parts[1] ?? ''; $desc = $parts[2] ?? '';
            $debit_code = $parts[3] ?? ''; $credit_code = $parts[4] ?? ''; $amount = $parts[5] ?? ''; $cc = $parts[6] ?? '';
            if (!$date || !$doc || !$debit_code || !$credit_code || !$amount) continue;

            $d = DateTime::createFromFormat('d/m/Y', $date);
            if (!$d) $d = DateTime::createFromFormat('d/m/y', $date);
            if (!$d) $d = DateTime::createFromFormat('d-m-Y', $date);
            if (!$d) $d = DateTime::createFromFormat('d.m.Y', $date);
            if (!$d) $d = DateTime::createFromFormat('Y-m-d', $date);
            $date_norm = $d ? $d->format('Y-m-d') : $date;

            $amount = floatval(str_replace(',', '.', $amount));
            $rows[] = compact('date_norm','doc','desc','debit_code','credit_code','amount','cc');
        }
    }

    $inserted=0; $updated=0; $errs=0;
    foreach ($rows as $r){
        $ref_code = 'E-' . preg_replace('/\s+/', '', (string)$r['doc']);
        $trn_date = $r['date_norm'];
        $description = $r['desc'];

        $debit_acc_id  = sde_suite_lookup_account_id_by_code($r['debit_code']);
        $credit_acc_id = sde_suite_lookup_account_id_by_code($r['credit_code']);
        if (!$debit_acc_id || !$credit_acc_id){ $errs++; continue; }

        $cc_id = 0; $cc_str = '';
        if (!empty($r['cc'])){
            $cc_str = preg_replace('/^[-\s]*/','', (string)$r['cc']);
            $cc_str = str_replace(['.', ' '], '', $cc_str);
            $cc_try = preg_replace('/^[-]*/','', $cc_str);
            $cc_id = sde_suite_lookup_cc_by_plate($cc_try);
        }

        $jid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$jr_tbl} WHERE ref_code=%s", $ref_code));
        if ($jid){
            $wpdb->update($jr_tbl, ['trn_date'=>$trn_date,'description'=>$description,'ref_code'=>$ref_code,'veh_trx_type'=>'Expense'],
                          ['id'=>intval($jid)],
                          ['%s','%s','%s','%s'], ['%d']);
            $wpdb->query($wpdb->prepare("DELETE FROM {$ln_tbl} WHERE journal_id=%d", $jid));
            $ok1 = $wpdb->insert($ln_tbl, ['journal_id'=>$jid,'account_id'=>$debit_acc_id,'debit'=>$r['amount'],'credit'=>0,'cc'=>$cc_str,'cc_id'=>$cc_id?:null,'created_at'=>current_time('mysql')],
                                  ['%d','%d','%f','%f','%s','%d','%s']);
            $ok2 = $wpdb->insert($ln_tbl, ['journal_id'=>$jid,'account_id'=>$credit_acc_id,'debit'=>0,'credit'=>$r['amount'],'cc'=>$cc_str,'cc_id'=>$cc_id?:null,'created_at'=>current_time('mysql')],
                                  ['%d','%d','%f','%f','%s','%d','%s']);
            if ($ok1 && $ok2) $updated++; else $errs++;
        } else {
            $ok = $wpdb->insert($jr_tbl, ['entry_no'=>sde_suite_next_entry_no(),'trn_date'=>$trn_date,'description'=>$description,'ref_code'=>$ref_code,'veh_trx_type'=>'Expense','created_at'=>current_time('mysql')],
                                ['%s','%s','%s','%s','%s','%s']);
            if (!$ok){ $errs++; continue; }
            $jid = intval($wpdb->insert_id);
            $ok1 = $wpdb->insert($ln_tbl, ['journal_id'=>$jid,'account_id'=>$debit_acc_id,'debit'=>$r['amount'],'credit'=>0,'cc'=>$cc_str,'cc_id'=>$cc_id?:null,'created_at'=>current_time('mysql')],
                                  ['%d','%d','%f','%f','%s','%d','%s']);
            $ok2 = $wpdb->insert($ln_tbl, ['journal_id'=>$jid,'account_id'=>$credit_acc_id,'debit'=>0,'credit'=>$r['amount'],'cc'=>$cc_str,'cc_id'=>$cc_id?:null,'created_at'=>current_time('mysql')],
                                  ['%d','%d','%f','%f','%s','%d','%s']);
            if ($ok1 && $ok2) $inserted++; else $errs++;
        }
    }
    $report = compact('inserted','updated','errs');
}

echo '<div class="wrap">';
echo '<h1>Import Transactions (Expenses)</h1>';
if ($report){
    echo '<div class="notice notice-success"><p><strong>Done.</strong> Inserted: '.intval($report['inserted']).' — Updated: '.intval($report['updated']).' — Errors: '.intval($report['errs']).'</p></div>';
}
echo '<p>Paste rows: Date, Doc#, Description, DebitCode, CreditCode, Amount, [Vehicle/CC]</p>';
echo '<form method="post">'; wp_nonce_field('sde_import_txn');
echo '<textarea name="import_blob" rows="12" style="width:100%; font-family:monospace;"></textarea>';
echo '<p><button class="button button-primary">Import</button></p>';
echo '</form>';
echo '</div>';
