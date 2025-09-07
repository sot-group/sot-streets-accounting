<?php
if (!defined('ABSPATH')) exit;

/**
 * Vehicle Report (replaces old CC Report)
 * - Menu: S.O.T. Accounting → Vehicle Report
 * - Also appears as a meta box on each Vehicle (CPT: sde_cost_center) with same format
 */

add_action('admin_menu', function(){
    $parent_slug = 'sde-modular'; // top-level slug from router
    add_submenu_page(
        $parent_slug,
        'Vehicle Report',
        'Vehicle Report',
        'manage_options',
        'sde-cc-report', // keep slug stable
        'sde_vehicle_report_render_page'
    );
}, 20);

// Meta box in Vehicle CPT
add_action('add_meta_boxes', function(){
    add_meta_box('sde_vehicle_report_box', 'Vehicle Report', 'sde_vehicle_report_metabox', 'sde_cost_center', 'normal', 'default');
});

function sde_vehicle_report_metabox($post){
    // Render same report but fixed to this vehicle
    $args = [
        'vehicle_id' => intval($post->ID),
        'context'    => 'metabox',
    ];
    sde_vehicle_report_render($args);
}

function sde_vehicle_report_render_page(){
    $vehicle_id = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : 0;
    sde_vehicle_report_render([ 'vehicle_id' => $vehicle_id, 'context'=>'page' ]);
}

function sde_vehicle_report_render($args){
    global $wpdb;
    $jr_tbl = $wpdb->prefix . 'sde_journals';
    $ln_tbl = $wpdb->prefix . 'sde_journal_lines';

    $context    = isset($args['context']) ? $args['context'] : 'page';
    $vehicle_id = isset($args['vehicle_id']) ? intval($args['vehicle_id']) : 0;

    // Filters (fresh UI)
    $cur_year = date('Y', current_time('timestamp'));
$default_from = '2020-01-01';
$default_to   = $cur_year . '-12-31';

    $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : $default_from;
    $to   = isset($_GET['to'])   ? sanitize_text_field($_GET['to'])   : $default_to;

    $all_types = [
        'purchase'     => 'Purchase',
        'income'       => 'Income',
        'maintenance'  => 'Maintenance/Rep',
        'insurance'    => 'Insurance',
        'registration' => 'Registration',
        'sale'         => 'Sale',
    ];
    $sel_types = isset($_GET['types']) && is_array($_GET['types']) ? array_map('sanitize_text_field', $_GET['types']) : array_keys($all_types);

    // Vehicles list (CPT)
    $vehicles = [];
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
    foreach ($rows as $r){
        $vehicles[intval($r->ID)] = $r->code ? $r->code : $r->post_title;
    }
    if ($context==='metabox'){
        // In metabox, force current vehicle, hide dropdown
        if ($vehicle_id<=0 && !empty($rows)){
            $vehicle_id = intval($rows[0]->ID);
        }
    } else {
        // In page context: allow selection via GET
        $vehicle_id = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : $vehicle_id;
    }

    $export = (isset($_GET['export']) && $_GET['export']==='csv');

    echo '<div class="wrap">';
    if ($context==='page'){
        echo '<h1 class="wp-heading-inline">Vehicle Report</h1>';
    }

    // Filter form
    echo '<form method="get" style="margin:12px 0; padding:10px; background:#fff;border:1px solid #ddd; border-radius:6px">';
    echo '<input type="hidden" name="page" value="sde-cc-report" />';
    echo '<div style="display:grid; gap:12px; grid-template-columns: '.($context==='page'?'22% 15% 15% 48%':'20% 20% 60%').'">';

    if ($context==='page'){
        echo '<div><label style="display:block; font-weight:600; margin-bottom:4px">Vehicle</label>';
        echo '<select name="vehicle_id" style="width:100%">';
        echo '<option value="">— Select —</option>';
        foreach ($vehicles as $vid=>$label){
            $sel = ($vehicle_id===$vid) ? ' selected' : '';
            echo '<option value="'.intval($vid).'"'.$sel.'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
    } else {
        $label = isset($vehicles[$vehicle_id]) ? $vehicles[$vehicle_id] : '(Vehicle)';
        echo '<div><label style="display:block; font-weight:600; margin-bottom:4px">Vehicle</label>';
        echo '<div>'.esc_html($label).'</div></div>';
        echo '<input type="hidden" name="vehicle_id" value="'.intval($vehicle_id).'" />';
    }

    echo '<div><label style="display:block; font-weight:600; margin-bottom:4px">From</label><input type="date" name="from" value="'.esc_attr($from).'" /></div>';
    echo '<div><label style="display:block; font-weight:600; margin-bottom:4px">To</label><input type="date" name="to" value="'.esc_attr($to).'" /></div>';

    /* categories removed */

    echo '</div>'; // grid
    echo '<p style="margin-top:10px"><button class="button button-primary">Apply</button> ';
    echo '<a class="button" href="'.esc_url(add_query_arg(['export'=>'csv'])).'">Export CSV</a></p>';
    echo '</form>';

    if ($vehicle_id <= 0){
        echo '<p style="color:#666">Select a vehicle and click Apply.</p></div>';
        return;
    }

    // Fetch vehicle code for legacy cc string matching (if any older data)
    $veh_code = get_post_meta($vehicle_id, 'sde_cc_code', true);
    $veh_code = $veh_code ? $veh_code : '';

    // Build WHERE
    $where = $wpdb->prepare("j.trn_date BETWEEN %s AND %s", $from, $to);
    $where .= $wpdb->prepare(" AND (l.cc_id=%d", $vehicle_id);
    if ($veh_code){
        $where .= $wpdb->prepare(" OR (IFNULL(l.cc_id,0)=0 AND l.cc=%s)", $veh_code);
    }
    $where .= ")";
    /* type filter removed */

    // Query rows aggregated per journal
    $sql = "
        SELECT j.id, j.entry_no, j.trn_date, j.description, COALESCE(j.veh_trx_type,'') AS veh_trx_type,
       SUM(COALESCE(l.debit,0))  AS dr,
       SUM(COALESCE(l.credit,0)) AS cr,
       GREATEST(SUM(COALESCE(l.debit,0)), SUM(COALESCE(l.credit,0))) AS amt
          FROM {$jr_tbl} j
          JOIN {$ln_tbl} l ON l.journal_id=j.id
         WHERE {$where}
         GROUP BY j.id, j.entry_no, j.trn_date, j.description, j.veh_trx_type
         ORDER BY j.trn_date ASC, j.entry_no ASC
    ";
    $rows = $wpdb->get_results($sql);

    // Grouping and ranking
    $rank = function($type){
        $t = strtolower(trim($type));
        if ($t==='purchase') return 1;
        if ($t==='income') return 2;
        if (in_array($t, ['maintenance','insurance','registration'], true)) return 3;
        if ($t==='sale') return 4;
        return 5;
    };
    $group_name = function($type){
        $t = strtolower(trim($type));
        if ($t==='purchase') return 'Purchase';
        if ($t==='income') return 'Income';
        if (in_array($t, ['maintenance','insurance','registration'], true)) return 'Expenses';
        if ($t==='sale') return 'Sale';
        return 'Other';
    };

    $data = [];
    foreach ($rows as $r){
        $g = $group_name($r->veh_trx_type);
        if (!isset($data[$g])) $data[$g] = [];
        $data[$g][] = $r;
    }

    // CSV export
    if ($export){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vehicle-report-' . intval($vehicle_id) . '-' . $from . '_to_' . $to . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Group','Date','Doc #','Description','Type','Amount']);
        uksort($data, function($a,$b){
            $order = ['Purchase'=>1,'Income'=>2,'Expenses'=>3,'Sale'=>4,'Other'=>5];
            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });
        foreach ($data as $g=>$items){
            foreach ($items as $r){
                $amt = floatval($r->amt);
                fputcsv($out, [$g, $r->trn_date, $r->entry_no, $r->description, $r->veh_trx_type, number_format($r->amt,2,'.','')]);
            }
        }
         // append totals
        $total_purchases = $__group_totals['Purchase'] ?? 0;
        $total_income    = $__group_totals['Income'] ?? 0;
        $total_expenses  = $__group_totals['Expenses'] ?? 0;
        $total_sale      = $__group_totals['Sale'] ?? 0;
        $total_profit = ($total_purchases) - ($total_income) + ($total_expenses) - ($total_sale);
        fputcsv($out, []);
        fputcsv($out, ['TOTALS']);
        fputcsv($out, ['Total Purchases','','','','', number_format($total_purchases,2,'.','')]);
        fputcsv($out, ['Total Income','','','','', number_format($total_income,2,'.','')]);
        fputcsv($out, ['Total Expenses','','','','', number_format($total_expenses,2,'.','')]);
        fputcsv($out, ['Total Sale','','','','', number_format($total_sale,2,'.','')]);
        fputcsv($out, ['Total Profit','','','','', number_format($total_profit,2,'.','')]);
        fclose($out);
        exit;
    }

    // Render grouped tables in required order
    $order = ['Purchase','Income','Expenses','Sale','Other'];
    $__group_totals = [];
    foreach ($order as $sec){
        if (empty($data[$sec])) continue;
        echo '<h2 style="margin-top:24px">'.esc_html($sec).'</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th style="width:14%">Date</th><th style="width:14%">Doc #</th><th>Description</th><th style="width:16%">Type</th><th style="width:16%; text-align:right">Amount</th></tr></thead><tbody>';
        $sub_amt = 0.0;
        foreach ($data[$sec] as $r){
            $amt = floatval($r->amt);
            $sub_amt += $amt;
echo '<tr>';
            echo '<td>'.esc_html($r->trn_date).'</td>';
            echo '<td>'.esc_html($r->entry_no).'</td>';
            echo '<td>'.esc_html($r->description).'</td>';
            echo '<td>'.esc_html(ucwords(str_replace('_',' ', $r->veh_trx_type))).'</td>';
            echo '<td style="text-align:right">'.number_format($amt,2).'</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="4" style="text-align:right; font-weight:600">Subtotal</td><td style="text-align:right">'.number_format($sub_amt,2).'</td></tr>';
        echo '</tbody></table>';
$__group_totals[$sec] = ($__group_totals[$sec] ?? 0) + ($sub_amt);
    }

    
    /* __TOTALS__ */
    $total_purchases = $__group_totals['Purchase'] ?? 0;
    $total_income    = $__group_totals['Income'] ?? 0;
    $total_expenses  = $__group_totals['Expenses'] ?? 0;
    $total_sale      = $__group_totals['Sale'] ?? 0;
    $total_profit = ($total_purchases) - ($total_income) + ($total_expenses) - ($total_sale);

    echo '<div style="margin-top:24px; padding:12px; background:#fff; border:1px solid #ddd; border-radius:8px">';
    echo '<table class="widefat"><tbody>';
    echo '<tr><td style="width:60%; text-align:right; font-weight:600">Total Purchases</td><td style="text-align:right">'.number_format($total_purchases,2).'</td></tr>';
    echo '<tr><td style="text-align:right; font-weight:600">Total Income</td><td style="text-align:right">'.number_format($total_income,2).'</td></tr>';
    echo '<tr><td style="text-align:right; font-weight:600">Total Expenses (Maint/Rep + Insurance + Registration)</td><td style="text-align:right">'.number_format($total_expenses,2).'</td></tr>';
    echo '<tr><td style="text-align:right; font-weight:600">Total Sale</td><td style="text-align:right">'.number_format($total_sale,2).'</td></tr>';
    echo '<tr><td style="text-align:right; font-size:1.1em; font-weight:700">Total Profit</td><td style="text-align:right; font-size:1.1em; font-weight:700">'.number_format($total_profit,2).'</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '</div>'; // wrap
}
