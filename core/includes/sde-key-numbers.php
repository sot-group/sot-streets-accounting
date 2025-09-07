<?php
if (!defined('ABSPATH')) exit;

/**
 * Key Numbers Dashboard
 * - Shows Purchases, Income, Expenses (Maint/Ins/Reg), Sales and Net Profit
 * - Periods: Week-to-date, Month-to-date, Quarter-to-date, Year-to-date
 * - Filters: Vehicle (All or one), Export CSV
 */

add_action('admin_menu', function(){
    add_submenu_page(
        'sde-modular',
        'Key Numbers',
        'Key Numbers',
        'manage_options',
        'sde-key-numbers',
        'sde_key_numbers_render'
    );
}, 21);

function sde_key_numbers_render(){
    global $wpdb;
    $jr_tbl = $wpdb->prefix . 'sde_journals';
    $ln_tbl = $wpdb->prefix . 'sde_journal_lines';
    $acc_tbl = $wpdb->prefix . 'sde_accounts';
    /* ensure account flag columns */
    foreach (['is_cash','is_ar','is_ap'] as $__c){
        $__col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$acc_tbl} LIKE %s", $__c));
        if (!$__col){
            $wpdb->query("ALTER TABLE {$acc_tbl} ADD COLUMN {$__c} TINYINT(1) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE {$acc_tbl} ADD KEY {$__c} ({$__c})");
        }
    }

// safety: ensure veh_trx_type column exists
$__col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$jr_tbl} LIKE %s", 'veh_trx_type'));
if (!$__col){
  $wpdb->query("ALTER TABLE {$jr_tbl} ADD COLUMN veh_trx_type VARCHAR(32) NULL DEFAULT NULL");
  $wpdb->query("ALTER TABLE {$jr_tbl} ADD KEY veh_trx_type (veh_trx_type)");
}


    // Vehicle list (All + CPT sde_cost_center)
    $vehicles = ['0' => 'All Vehicles'];
    $posts_tbl = $wpdb->posts;
    $meta_tbl  = $wpdb->postmeta;
    $rows = $wpdb->get_results("
        SELECT p.ID, p.post_title,
               MAX(CASE WHEN pm.meta_key='sde_cc_code' THEN pm.meta_value END) AS code
          FROM {$posts_tbl} p
     LEFT JOIN {$meta_tbl} pm ON pm.post_id = p.ID
         WHERE p.post_type='sde_cost_center' AND p.post_status IN ('publish','draft')
      GROUP BY p.ID, p.post_title
      ORDER BY p.post_title ASC
    ");
    foreach ($rows as $r){
        $vehicles[(string)intval($r->ID)] = $r->code ? $r->code : $r->post_title;
    }

    $vehicle_id = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : 0;
    $export = (isset($_GET['export']) && $_GET['export']==='csv');

    // Helper: ranges (Week, Month, Quarter, Year)
    $now_ts = current_time('timestamp');
    $today  = date('Y-m-d', $now_ts);

    // Start of week (use WP start_of_week, default Monday=1; WordPress stores 0=Sunday)
    $start_of_week = intval(get_option('start_of_week', 1));
    // Compute start of this week
    $wday = intval(date('w', $now_ts)); // 0=Sun .. 6=Sat
    $target = $start_of_week % 7;
    $diff = ($wday - $target + 7) % 7;
    $week_start_ts = strtotime("-{$diff} days", strtotime(date('Y-m-d', $now_ts)));
    $week_from = date('Y-m-d', $week_start_ts);
    $week_to   = $today;

    // Month
    $month_from = date('Y-m-01', $now_ts);
    $month_to   = $today;

    // Quarter
    $m = intval(date('n', $now_ts));
    $q_start_month = [1,1,1, 4,4,4, 7,7,7, 10,10,10][$m-1];
    $quarter_from = date('Y-' . str_pad($q_start_month, 2, '0', STR_PAD_LEFT) . '-01', $now_ts);
    $quarter_to   = $today;

    // Year
    $year_from = date('Y-01-01', $now_ts);
    $year_to   = $today;

    /* ACCT_METRICS helpers */
$acct_metrics = function($from,$to) use ($wpdb){
    $jr_tbl = $wpdb->prefix . 'sde_journals';
    $ln_tbl = $wpdb->prefix . 'sde_journal_lines';
    $acc_tbl = $wpdb->prefix . 'sde_accounts';
    /* ensure account flag columns */
    foreach (['is_cash','is_ar','is_ap'] as $__c){
        $__col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$acc_tbl} LIKE %s", $__c));
        if (!$__col){
            $wpdb->query("ALTER TABLE {$acc_tbl} ADD COLUMN {$__c} TINYINT(1) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE {$acc_tbl} ADD KEY {$__c} ({$__c})");
        }
    }

    $acc_tbl = $wpdb->prefix . 'sde_accounts';
    $rev = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(COALESCE(l.credit,0)-COALESCE(l.debit,0)) FROM {$ln_tbl} l JOIN {$jr_tbl} j ON j.id=l.journal_id JOIN {$acc_tbl} a ON a.id=l.account_id WHERE j.trn_date BETWEEN %s AND %s AND a.type='revenue'", $from, $to));
    $exp = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(COALESCE(l.debit,0)-COALESCE(l.credit,0)) FROM {$ln_tbl} l JOIN {$jr_tbl} j ON j.id=l.journal_id JOIN {$acc_tbl} a ON a.id=l.account_id WHERE j.trn_date BETWEEN %s AND %s AND a.type='expense'", $from, $to));
    $cash_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE is_cash=1");
    if (empty($cash_ids)){
        $cash_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE type='asset' AND (name LIKE '%Cash%' OR name LIKE '%Bank%' OR code LIKE 'CASH%' OR code LIKE 'BANK%')");
    }
    $in = $cash_ids ? implode(',', array_map('intval',$cash_ids)) : '0';
    $cash_in  = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(COALESCE(l.debit,0)) FROM {$ln_tbl} l JOIN {$jr_tbl} j ON j.id=l.journal_id WHERE j.trn_date BETWEEN %s AND %s AND l.account_id IN ({$in})", $from, $to));
    $cash_out = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(COALESCE(l.credit,0)) FROM {$ln_tbl} l JOIN {$jr_tbl} j ON j.id=l.journal_id WHERE j.trn_date BETWEEN %s AND %s AND l.account_id IN ({$in})", $from, $to));
    $cash_end = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(COALESCE(l.debit,0)-COALESCE(l.credit,0)) FROM {$ln_tbl} l JOIN {$jr_tbl} j ON j.id=l.journal_id WHERE j.trn_date <= %s AND l.account_id IN ({$in})", $to));
    $ar_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE is_ar=1");
    if (empty($ar_ids)) { $ar_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE type='asset' AND (name LIKE '%Receivable%' OR code LIKE 'AR%')"); }
    $ap_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE is_ap=1");
    if (empty($ap_ids)) { $ap_ids = $wpdb->get_col("SELECT id FROM {$acc_tbl} WHERE type='liability' AND (name LIKE '%Payable%' OR code LIKE 'AP%')"); }
    $in_ar = $ar_ids ? implode(',', array_map('intval',$ar_ids)) : '0';
    $in_ap = $ap_ids ? implode(',', array_map('intval',$ap_ids)) : '0';
    $ar_bal = (float) $wpdb->get_var("SELECT SUM(COALESCE(l.debit,0)-COALESCE(l.credit,0)) FROM {$ln_tbl} l WHERE l.account_id IN ({$in_ar})");
    $ap_bal = (float) $wpdb->get_var("SELECT SUM(COALESCE(l.credit,0)-COALESCE(l.debit,0)) FROM {$ln_tbl} l WHERE l.account_id IN ({$in_ap})");
    return [
        'revenue'=>$rev,
        'expenses'=>$exp,
        'net_income'=>($rev - $exp),
        'cash_in'=>$cash_in,
        'cash_out'=>$cash_out,
        'net_cash'=>$cash_in - $cash_out,
        'cash_end'=>$cash_end,
        'ar'=>$ar_bal,
        'ap'=>$ap_bal,
    ];
};

$periods = [
        'week'    => ['label'=>'Week-to-date',    'from'=>$week_from,    'to'=>$week_to],
        'month'   => ['label'=>'Month-to-date',   'from'=>$month_from,   'to'=>$month_to],
        'quarter' => ['label'=>'Quarter-to-date', 'from'=>$quarter_from, 'to'=>$quarter_to],
        'year'    => ['label'=>'Year-to-date',    'from'=>$year_from,    'to'=>$year_to],
    ];

    // Fetch sums by category for a given range
    $fetch = function($from, $to) use ($wpdb, $jr_tbl, $ln_tbl, $vehicle_id){
        // Build WHERE: date and (vehicle_id OR legacy cc string match)
        $where = $wpdb->prepare("j.trn_date BETWEEN %s AND %s", $from, $to);
        if ($vehicle_id > 0){
            $veh_code = get_post_meta($vehicle_id, 'sde_cc_code', true);
            $where .= $wpdb->prepare(" AND (l.cc_id=%d", $vehicle_id);
            if ($veh_code){
                $where .= $wpdb->prepare(" OR (IFNULL(l.cc_id,0)=0 AND l.cc=%s)", $veh_code);
            }
            $where .= ")";
        }
        // Subquery per journal to compute single amount (equal to entered amount)
        $sql = "
            SELECT t.veh_trx_type AS type,
                   SUM(amt) AS total
            FROM (
                SELECT j.id,
                       COALESCE(j.veh_trx_type,'') AS veh_trx_type,
                       GREATEST(SUM(COALESCE(l.debit,0)), SUM(COALESCE(l.credit,0))) AS amt
                  FROM {$jr_tbl} j
                  JOIN {$ln_tbl} l ON l.journal_id=j.id
                 WHERE {$where}
                 GROUP BY j.id, j.veh_trx_type
            ) t
            GROUP BY t.veh_trx_type
        ";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [
            'purchase'     => 0.0,
            'income'       => 0.0,
            'maintenance'  => 0.0,
            'insurance'    => 0.0,
            'registration' => 0.0,
            'sale'         => 0.0,
        ];
        foreach ($rows as $r){
            $k = strtolower(trim($r['type']));
            if (isset($out[$k])) $out[$k] = floatval($r['total']);
        }
        $out['expenses'] = $out['maintenance'] + $out['insurance'] + $out['registration'];
        $out['profit'] = $out['income'] - $out['expenses'];
        return $out;
    };

    // Compute all periods
    $data = [];
    foreach ($periods as $key=>$p){
        $data[$key] = $fetch($p['from'], $p['to']);
        $acct[$key] = $acct_metrics($p['from'], $p['to']);
    }

    // Export CSV?
    if ($export){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=key-numbers-' . ($vehicle_id>0?$vehicle_id:'all') . '-' . date('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Period','From','To','Purchases','Income','Expenses','Sales','Profit (Costcenter)','Revenue','Expenses (Acct)','Net Income','Cash In','Cash Out','Net Cash','Ending Cash','AR','AP']);
        foreach ($periods as $key=>$p){
            $row = $data[$key];
            fputcsv($out, [$p['label'], $p['from'], $p['to'], number_format($row['purchase'],2,'.',''), number_format($row['sale'],2,'.',''), number_format($row['income'],2,'.',''), number_format($row['expenses'],2,'.',''), number_format($row['profit'],2,'.',''), 
                number_format($acct[$key]['revenue'],2,'.',''),
                number_format($acct[$key]['expenses'],2,'.',''),
                number_format($acct[$key]['net_income'],2,'.',''),
                number_format($acct[$key]['cash_in'],2,'.',''),
                number_format($acct[$key]['cash_out'],2,'.',''),
                number_format($acct[$key]['net_cash'],2,'.',''),
                number_format($acct[$key]['cash_end'],2,'.',''),
                number_format($acct[$key]['ar'],2,'.',''),
                number_format($acct[$key]['ap'],2,'.','')
            ]);
        }
        fclose($out);
        exit;
    }

    // Render page
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Key Numbers</h1>';

    // Filters row
    echo '<form method="get" style="margin:12px 0; padding:10px; background:#fff;border:1px solid #ddd; border-radius:6px">';
    echo '<input type="hidden" name="page" value="sde-key-numbers" />';
    echo '<div style="display:grid; gap:12px; grid-template-columns: 30% 1fr;">';
    echo '<div><label style="display:block; font-weight:600; margin-bottom:4px">Vehicle</label><select name="vehicle_id" style="width:100%">';
    foreach ($vehicles as $vid=>$label){
        $sel = (intval($vid) === $vehicle_id) ? ' selected' : '';
        echo '<option value="'.esc_attr($vid).'"'.$sel.'>'.esc_html($label).'</option>';
    }
    echo '</select></div>';
    echo '<div style="align-self:end; text-align:right"><button class="button button-primary">Apply</button> ';
    echo '<a class="button" href="'.esc_url(add_query_arg(['export'=>'csv'])).'">Export CSV</a></div>';
    echo '</div>';
    echo '</form>';

    // Tiles grid
    echo '<style>.sde-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.sde-card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px}.sde-card h3{margin:0 0 8px 0;font-size:14px;color:#555;text-transform:uppercase;letter-spacing:.04em}.sde-metric{font-size:22px;font-weight:700;margin:2px 0 10px}.sde-row{display:flex;justify-content:space-between;color:#444;margin:4px 0}.sde-row span:last-child{font-variant-numeric:tabular-nums}</style>';

    echo '<div class="sde-tiles">';
    foreach ($periods as $key=>$p){
        $row = $data[$key];
        echo '<div class="sde-card">';
        echo '<h3>'.esc_html($p['label']).'</h3>';
        echo '<div class="sde-row"><span>From</span><span>'.esc_html($p['from']).'</span></div>';
        echo '<div class="sde-row"><span>To</span><span>'.esc_html($p['to']).'</span></div>';
        echo '<hr />';
        echo '<div class="sde-row"><span>New Purchases</span><span>'.number_format($row['purchase'],2).'</span></div>';
        echo '<div class="sde-row"><span>Income</span><span>'.number_format($row['income'],2).'</span></div>';
        echo '<div class="sde-row"><span>Expenses</span><span>'.number_format($row['expenses'],2).'</span></div>';
        echo '<div class="sde-row"><span>Sales of Vehicles</span><span>'.number_format($row['sale'],2).'</span></div>';
        echo '<div class="sde-metric">Profit (Costcenter): '.number_format($row['profit'],2).'</div>';
        $a = $acct[$key]; echo '<hr />';
        echo '<div class="sde-row"><span>Revenue</span><span>'.number_format($a['revenue'],2).'</span></div>';
        echo '<div class="sde-row"><span>Expenses</span><span>'.number_format($a['expenses'],2).'</span></div>';
        echo '<div class="sde-row"><span>Net Income</span><span>'.number_format($a['net_income'],2).'</span></div>';
        echo '<div class="sde-row"><span>Cash In / Out</span><span>'.number_format($a['cash_in'],2).' / '.number_format($a['cash_out'],2).'</span></div>';
        echo '<div class="sde-row"><span>Net Cash Flow</span><span>'.number_format($a['net_cash'],2).'</span></div>';
        echo '<div class="sde-row"><span>Ending Cash</span><span>'.number_format($a['cash_end'],2).'</span></div>';
        echo '<div class="sde-row"><span>AR / AP</span><span>'.number_format($a['ar'],2).' / '.number_format($a['ap'],2).'</span></div>';
        echo '</div>';
    }
    echo '</div>'; // tiles

    echo '</div>'; // wrap
}
