
<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$acc_tbl = $wpdb->prefix.'sde_accounts';
$ln_tbl  = $wpdb->prefix.'sde_journal_lines';

echo '<div class="wrap"><h1>Accounts</h1>';
$__msg = sanitize_text_field($_GET['msg'] ?? '');
if ($__msg==='acc_deleted') echo '<div class="notice notice-warning"><p>Account deleted.</p></div>';
if ($__msg==='acc_updated') echo '<div class="notice notice-success"><p>Account updated.</p></div>'; if ($__msg==='acc_created') echo '<div class="notice notice-success"><p>Account created.</p></div>';

// Fetch accounts with sums
$rows = $wpdb->get_results("
    SELECT a.id, a.code, a.name, a.type,
           COALESCE((SELECT SUM(l1.debit)  FROM {$ln_tbl} l1 WHERE l1.account_id=a.id),0) AS debit_sum,
           COALESCE((SELECT SUM(l2.credit) FROM {$ln_tbl} l2 WHERE l2.account_id=a.id),0) AS credit_sum
    FROM {$acc_tbl} a
    ORDER BY a.code
");

$groups = ['Asset'=>'Assets','Equity'=>'Equity','Revenue'=>'Revenue','Expense'=>'Expense'];
$normalize_type = function($t, $code){
    $t = trim((string)$t); $u = strtoupper($t);
    if (in_array($u,['ASSET','ASSETS'])) return 'Asset';
    if (in_array($u,['EQUITY','OWNER\'S EQUITY','OWNERS EQUITY','CAPITAL'])) return 'Equity';
    if (in_array($u,['REVENUE','INCOME','SALES'])) return 'Revenue';
    if (in_array($u,['EXPENSE','EXPENSES','COST','COSTS'])) return 'Expense';
    $digits = preg_replace('/[^0-9]/','',(string)$code);
    $lead = strlen($digits)?$digits[0]:'';
    if ($lead==='1') return 'Asset';
    if ($lead==='2') return 'Equity';
    if ($lead==='3') return 'Revenue';
    if ($lead==='4') return 'Expense';
    return 'Other';
};

$data = ['Asset'=>[],'Equity'=>[],'Revenue'=>[],'Expense'=>[],'Other'=>[]];
foreach ((array)$rows as $r){ $key = $normalize_type($r->type,$r->code); $data[$key][] = $r; }

$fmt = function($n){ return number_format((float)$n, 2, '.', ','); };
$acc_url = function($id){
    return esc_url( add_query_arg(['page'=>'sde-modular-account','acc'=>intval($id)], admin_url('admin.php')) );
};

foreach ($groups as $key=>$title){
    echo '<h2 style="margin-top:18px">'.esc_html($title).'</h2>';
    $list = $data[$key] ?? [];
    usort($list, function($a,$b){ return strcmp($a->code,$b->code); });
    $sumD=0; $sumC=0; $sumB=0;
    echo '<table class="widefat fixed striped" style="table-layout:fixed;width:100%">';
    echo '<colgroup><col style="width:12%"><col style="width:38%"><col style="width:16%"><col style="width:16%"><col style="width:18%"></colgroup>';
    echo '<thead><tr><th>Code</th><th>Name</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance (D−C)</th></tr></thead><tbody>';
    foreach ($list as $r){
        $d=(float)$r->debit_sum; $c=(float)$r->credit_sum; $b=$d-$c;
        $sumD+=$d; $sumC+=$c; $sumB+=$b;
        echo '<tr>';
        echo '<td><a target="_blank" rel="noopener" href="'.$acc_url($r->id).'">'.esc_html($r->code).'</a></td>';
        echo '<td><a target="_blank" rel="noopener" href="'.$acc_url($r->id).'">'.esc_html($r->name).'</a></td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($d)).'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($c)).'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($b)).'</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:600;background:#f6f7f7"><td colspan="2">Total '.esc_html($title).'</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumD)).'</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumC)).'</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumB)).'</td></tr>';
    echo '</tbody></table><div style="height:12px"></div>';
    if ($key==='Revenue'){ echo '<h1 style="margin-top:22px">Profit &amp; Loss Statement</h1>'; }
}

if (!empty($data['Other'])){
    echo '<h2 style="margin-top:18px">Other</h2>';
    $list = $data['Other']; usort($list, function($a,$b){ return strcmp($a->code,$b->code); });
    $sumD=0; $sumC=0; $sumB=0;
    echo '<table class="widefat fixed striped" style="table-layout:fixed;width:100%">';
    echo '<colgroup><col style="width:12%"><col style="width:38%"><col style="width:16%"><col style="width:16%"><col style="width:18%"></colgroup>';
    echo '<thead><tr><th>Code</th><th>Name</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance (D−C)</th></tr></thead><tbody>';
    foreach ($list as $r){
        $d=(float)$r->debit_sum; $c=(float)$r->credit_sum; $b=$d-$c;
        $sumD+=$d; $sumC+=$c; $sumB+=$b;
        echo '<tr>';
        echo '<td><a target="_blank" rel="noopener" href="'.$acc_url($r->id).'">'.esc_html($r->code).'</a></td>';
        echo '<td><a target="_blank" rel="noopener" href="'.$acc_url($r->id).'">'.esc_html($r->name).'</a></td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($d)).'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($c)).'</td>';
        echo '<td class="num" style="text-align:right">'.esc_html($fmt($b)).'</td>';
        echo '</tr>';
    }
    echo '<tr style="font-weight:600;background:#f6f7f7"><td colspan="2">Total Other</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumD)).'</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumC)).'</td>';
    echo '<td class="num" style="text-align:right">'.esc_html($fmt($sumB)).'</td></tr>';
    echo '</tbody></table>';
}

echo '</div>';
