<?php
/*
Plugin Module: SDE CC Management (integrated)
Version: 0.1.1
*/
if (!defined('ABSPATH')) { exit; }

// 1) Extra fields on Vehicles (CPT: sde_cost_center)
add_action('add_meta_boxes', function() {
    if (!post_type_exists('sde_cost_center')) { return; }
    add_meta_box(
        'sde_cc_details_extra',
        'Registration & Insurance',
        'sde_cc_render_extra_fields_mb',
        'sde_cost_center',
        'normal',
        'high'
    );
    add_meta_box(
        'sde_cc_maintenance_panel',
        'Maintenance Log (recent)',
        'sde_cc_render_maintenance_panel',
        'sde_cost_center',
        'normal',
        'low'
    );
});

function sde_cc_render_extra_fields_mb($post) {
    wp_nonce_field('sde_cc_extra_save', 'sde_cc_extra_nonce');

    $reg_date   = get_post_meta($post->ID, 'registration_date', true);
    $reg_exp    = get_post_meta($post->ID, 'registration_expiry_date', true);
    $ins_exp    = get_post_meta($post->ID, 'insurance_expiry_date', true);
    $ins_no     = get_post_meta($post->ID, 'insurance_contract_number', true);
    $mileage    = get_post_meta($post->ID, 'mileage', true);

    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="registration_date">Registration date</label></th><td><input type="date" id="registration_date" name="registration_date" value="'.esc_attr($reg_date).'"/></td></tr>';
    echo '<tr><th><label for="registration_expiry_date">Registration expiry date</label></th><td><input type="date" id="registration_expiry_date" name="registration_expiry_date" value="'.esc_attr($reg_exp).'"/></td></tr>';
    echo '<tr><th><label for="insurance_expiry_date">Insurance expiry date</label></th><td><input type="date" id="insurance_expiry_date" name="insurance_expiry_date" value="'.esc_attr($ins_exp).'"/></td></tr>';
    echo '<tr><th><label for="insurance_contract_number">Insurance contract #</label></th><td><input type="text" class="regular-text" id="insurance_contract_number" name="insurance_contract_number" value="'.esc_attr($ins_no).'" placeholder="e.g., POL-2025-00123"/></td></tr>';
    echo '<tr><th><label for="mileage">Mileage (km)</label></th><td><input type="number" id="mileage" name="mileage" value="'.esc_attr($mileage).'" min="0" step="1"/></td></tr>';
    echo '</tbody></table>';
}

add_action('save_post_sde_cost_center', function($post_id){
    if (!isset($_POST['sde_cc_extra_nonce']) || !wp_verify_nonce($_POST['sde_cc_extra_nonce'], 'sde_cc_extra_save')) { return; }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (!current_user_can('edit_post', $post_id)) { return; }

    $fields = ['registration_date','registration_expiry_date','insurance_expiry_date','insurance_contract_number','mileage'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            update_post_meta($post_id, $f, sanitize_text_field($_POST[$f]));
        }
    }
}, 10, 1);

add_filter('manage_sde_cost_center_posts_columns', function($cols){
    $cols['registration_expiry_date'] = 'Reg. Expiry';
    $cols['insurance_expiry_date']    = 'Ins. Expiry';
    $cols['mileage']                  = 'Mileage';
    return $cols;
});
add_action('manage_sde_cost_center_posts_custom_column', function($col, $post_id){
    if ($col === 'registration_expiry_date') {
        echo esc_html(get_post_meta($post_id, 'registration_expiry_date', true));
    } elseif ($col === 'insurance_expiry_date') {
        echo esc_html(get_post_meta($post_id, 'insurance_expiry_date', true));
    } elseif ($col === 'mileage') {
        echo esc_html(get_post_meta($post_id, 'mileage', true));
    }
}, 10, 2);

// 2) Maintenance Log (CPT) + Service Tags taxonomy
add_action('init', function() {
    register_post_type('sde_cc_maintenance', [
        'label' => 'Maintenance',
        'labels' => [
            'name' => 'Maintenance',
            'singular_name' => 'Maintenance',
            'add_new_item' => 'Add Maintenance',
            'edit_item' => 'Edit Maintenance',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title','editor','author','custom-fields','revisions'],
        'capability_type' => 'post',
    ]);

    register_taxonomy('sde_service_tag', 'sde_cc_maintenance', [
        'labels' => ['name' => 'Service Tags', 'singular_name' => 'Service Tag'],
        'public' => false,
        'show_ui' => true,
        'hierarchical' => false,
        'show_admin_column' => true,
    ]);
});

// Place Maintenance under Accounting menu
add_action('admin_menu', function(){
    add_submenu_page(
        'sde-modular',
        'Maintenance',
        'Maintenance',
        'manage_options',
        'edit.php?post_type=sde_cc_maintenance'
    );
}, 20);

// Maintenance meta box
add_action('add_meta_boxes', function() {
    add_meta_box('sde_cc_maint_fields', 'Maintenance Details', 'sde_cc_render_maint_mb', 'sde_cc_maintenance', 'normal', 'high');
});

function sde_cc_render_maint_mb($post) {
    global $wpdb;
    wp_nonce_field('sde_cc_maint_save', 'sde_cc_maint_nonce');

    $cc_id     = get_post_meta($post->ID, 'cc_id', true);
    $date      = get_post_meta($post->ID, 'maint_date', true);
    $garage    = get_post_meta($post->ID, 'garage', true);
    $mechanic  = get_post_meta($post->ID, 'mechanic', true);
    $journal_id= get_post_meta($post->ID, 'journal_id', true);
    $mileage   = get_post_meta($post->ID, 'mileage', true);

    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="cc_id">Vehicle</label></th><td>';
    $ccs = get_posts(['post_type'=>'sde_cost_center','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
    echo '<select id="cc_id" name="cc_id"><option value="">— Select —</option>';
    foreach ($ccs as $cc) {
        $code = get_post_meta($cc->ID, 'code', true);
        if (!$code) { $code = $cc->post_title; }
        $sel = selected($cc_id, $cc->ID, false);
        echo '<option value="'.$cc->ID.'" '.$sel.'>'.esc_html($code.' — '.$cc->post_title).'</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th><label for="maint_date">Date</label></th><td><input type="date" id="maint_date" name="maint_date" value="'.esc_attr($date).'"/></td></tr>';
    echo '<tr><th><label for="garage">Garage</label></th><td><input type="text" class="regular-text" id="garage" name="garage" value="'.esc_attr($garage).'" placeholder="e.g., Dili Motors"/></td></tr>';
    echo '<tr><th><label for="mechanic">Who</label></th><td><input type="text" class="regular-text" id="mechanic" name="mechanic" value="'.esc_attr($mechanic).'" placeholder="Name or team"/></td></tr>';
    echo '<tr><th><label for="journal_id">Transaction # (journal_id)</label></th><td><input type="number" id="journal_id" name="journal_id" value="'.esc_attr($journal_id).'" min="1" step="1"/></td></tr>';
    echo '<tr><th><label for="mileage">Mileage (km)</label></th><td><input type="number" id="mileage" name="mileage" value="'.esc_attr($mileage).'" min="0" step="1"/></td></tr>';
    echo '</tbody></table>';

    // Preview amount if journal_id provided
    if ($journal_id) {
        $lines_table = $wpdb->prefix . 'sde_journal_lines';
        $sum = $wpdb->get_var($wpdb->prepare("SELECT SUM(debit) FROM {$lines_table} WHERE journal_id = %d", $journal_id));
        if ($sum) { echo '<p><em>Linked transaction amount: ' . esc_html(number_format((float)$sum,2)) . '</em></p>'; }
    }
}

add_action('save_post_sde_cc_maintenance', function($post_id){
    if (!isset($_POST['sde_cc_maint_nonce']) || !wp_verify_nonce($_POST['sde_cc_maint_nonce'], 'sde_cc_maint_save')) { return; }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (!current_user_can('edit_post', $post_id)) { return; }
    $fields = ['cc_id','maint_date','garage','mechanic','journal_id','mileage'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            update_post_meta($post_id, $f, sanitize_text_field($_POST[$f]));
        }
    }
}, 10, 1);

// Maintenance list columns
add_filter('manage_sde_cc_maintenance_posts_columns', function($cols){
    $cols_new = [];
    foreach ($cols as $k=>$v) {
        $cols_new[$k] = $v;
        if ($k==='title') {
            $cols_new['cc'] = 'CC';
            $cols_new['maint_date'] = 'Date';
            $cols_new['amount'] = 'Amount';
        }
    }
    return $cols_new;
});
add_action('manage_sde_cc_maintenance_posts_custom_column', function($col, $post_id){
    global $wpdb;
    if ($col==='cc') {
        $cc_id = get_post_meta($post_id, 'cc_id', true);
        if ($cc_id) {
            $code = get_post_meta($cc_id, 'code', true);
            $title = get_the_title($cc_id);
            echo esc_html(($code?:'') . ($title? ' — '.$title : ''));
        }
    } elseif ($col==='maint_date') {
        echo esc_html(get_post_meta($post_id, 'maint_date', true));
    } elseif ($col==='amount') {
        $jid = get_post_meta($post_id, 'journal_id', true);
        if ($jid) {
            $sum = $wpdb->get_var($wpdb->prepare("SELECT SUM(debit) FROM {$wpdb->prefix}sde_journal_lines WHERE journal_id=%d", $jid));
            if ($sum) { echo esc_html(number_format((float)$sum,2)); }
        }
    }
}, 10, 2);

// Row action: quick Add Maintenance from CC list
add_filter('post_row_actions', function($actions, $post){
    if ($post->post_type === 'sde_cost_center') {
        $url = admin_url('post-new.php?post_type=sde_cc_maintenance&cc_prefill='.$post->ID);
        $actions['sde_add_maint'] = '<a href="'.esc_url($url).'">Add Maintenance</a>';
    }
    return $actions;
}, 10, 2);

// Prefill CC when opening new Maintenance
add_action('load-post-new.php', function(){
    if (isset($_GET['post_type']) && $_GET['post_type']==='sde_cc_maintenance' && !empty($_GET['cc_prefill'])) {
        add_action('admin_footer', function(){
            $cc = intval($_GET['cc_prefill']);
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("cc_id"); if(s){ s.value="'.esc_js($cc).'"; } });</script>';
        });
    }
});

// CC edit screen: recent Maintenance panel
function sde_cc_render_maintenance_panel($post) {
    $cc_id = intval($post->ID);
    $add_url = admin_url('post-new.php?post_type=sde_cc_maintenance&cc_prefill='.$cc_id);
    echo '<p><a class="button button-primary" href="'.esc_url($add_url).'">Add Maintenance</a></p>';

    $items = get_posts([
        'post_type'=>'sde_cc_maintenance',
        'meta_key'=>'maint_date',
        'orderby'=>'meta_value',
        'order'=>'DESC',
        'meta_query'=>[ ['key'=>'cc_id','value'=>$cc_id,'compare'=>'='] ],
        'posts_per_page'=>10,
    ]);
    if (!$items) { echo '<p><em>No maintenance logged yet.</em></p>'; return; }

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Title</th><th>Tags</th><th>Amount</th></tr></thead><tbody>';
    global $wpdb;
    foreach ($items as $m) {
        $date = get_post_meta($m->ID,'maint_date',true);
        $tags = wp_get_post_terms($m->ID, 'sde_service_tag', ['fields'=>'names']);
        $jid  = get_post_meta($m->ID,'journal_id',true);
        $sum='';
        if ($jid) {
            $sumv = $wpdb->get_var($wpdb->prepare("SELECT SUM(debit) FROM {$wpdb->prefix}sde_journal_lines WHERE journal_id=%d", $jid));
            if ($sumv) { $sum = number_format((float)$sumv,2); }
        }
        echo '<tr><td>'.esc_html($date).'</td><td><a href="'.esc_url(get_edit_post_link($m->ID)).'">'.esc_html(get_the_title($m)).'</a></td><td>'.esc_html(implode(', ',$tags)).'</td><td>'.esc_html($sum).'</td></tr>';
    }
    echo '</tbody></table>';
}

// Admin notice: expiries within 30 days
add_action('admin_notices', function(){
    if (!current_user_can('manage_options')) { return; }
    $soon = [];
    $ccs = get_posts(['post_type'=>'sde_cost_center','posts_per_page'=>-1,'post_status'=>'publish']);
    $today = date('Y-m-d');
    $tsoon = date('Y-m-d', strtotime('+30 days'));
    foreach ($ccs as $cc) {
        $row = ['title'=>$cc->post_title];
        $reg = get_post_meta($cc->ID,'registration_expiry_date', true);
        $ins = get_post_meta($cc->ID,'insurance_expiry_date', true);
        if ($reg && $reg <= $tsoon && $reg >= $today) { $row['reg'] = $reg; }
        if ($ins && $ins <= $tsoon && $ins >= $today) { $row['ins'] = $ins; }
        if (!empty($row['reg']) || !empty($row['ins'])) {
            $soon[] = $row;
        }
    }
    if ($soon) {
        echo '<div class="notice notice-warning"><p><strong>Upcoming expiries (30 days):</strong></p><ul>';
        foreach ($soon as $r) {
            $bits = [];
            if (!empty($r['reg'])) { $bits.append('Reg: ' . esc_html($r['reg'])); }
            if (!empty($r['ins'])) { $bits[] = 'Ins: ' . esc_html($r['ins']); }
            echo '<li>'.esc_html($r['title']).' — '.implode(' | ', $bits).'</li>';
        }
        echo '</ul></div>';
    }
});
