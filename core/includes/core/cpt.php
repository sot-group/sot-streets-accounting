<?php

if (!defined('ABSPATH')) exit;

class CCPT {
    public static function init(){
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_sde_cost_center', [__CLASS__, 'save_meta']);
    }
    public static function register_cpt(){
        $labels = [
            'name' => 'Vehicles',
            'singular_name' => 'Vehicle',
            'menu_name' => 'Vehicles',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Cost Center',
            'edit_item' => 'Edit Cost Center',
            'new_item' => 'New Cost Center',
            'view_item' => 'View Cost Center',
            'search_items' => 'Search Cost Centers',
        ];
        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // we link via Router submenu
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => ['title','thumbnail','excerpt'],
        ];
        register_post_type('sde_cost_center', $args);
    }
    public static function meta_boxes(){
        add_meta_box('sde_cc_meta', 'Vehicle Details', [__CLASS__,'render_meta'], 'sde_cost_center', 'normal', 'high');
    }
    public static function render_meta($post){
        wp_nonce_field('sde_cc_save', '_sde_cc_nonce');
        $code = get_post_meta($post->ID, 'sde_cc_code', true);
        $type = get_post_meta($post->ID, 'sde_cc_type', true);
        $active = get_post_meta($post->ID, 'sde_cc_active', true);
        $price = get_post_meta($post->ID, 'sde_cc_purchase_price', true);
        $pdate = get_post_meta($post->ID, 'sde_cc_purchase_date', true);
        $depacc = get_post_meta($post->ID, 'sde_cc_dep_account', true);
        if (!$depacc) $depacc = '3320';
        ?>
        <style>.sde-cc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}</style>
        <div class="sde-cc-grid">
            <p><label><strong>Code</strong><br/>
                <input type="text" name="sde_cc_code" value="<?php echo esc_attr($code); ?>" required />
            </label></p>
            <p><label><strong>Type</strong><br/>
                <select name="sde_cc_type">
                    <?php
                    $types = ['Car'=>'Car','Motorbike'=>'Motorbike','Starlink'=>'Starlink','Other'=>'Other'];
                    foreach ($types as $k=>$v){
                        $sel = selected($type, $k, false);
                        echo "<option value=\"".esc_attr($k)."\" {$sel}>".esc_html($v)."</option>";
                    }
                    ?>
                </select>
            </label></p>
            <p><label><strong>Status</strong><br/>
                <select name="sde_cc_active">
                    <option value="1" <?php selected($active, '1'); ?>>Active</option>
                    <option value="0" <?php selected($active, '0'); ?>>Inactive</option>
                </select>
            </label></p>
            <p><label><strong>Purchase Price</strong><br/>
                <input type="number" step="0.01" min="0" name="sde_cc_purchase_price" value="<?php echo esc_attr($price); ?>" />
            </label></p>
            <p><label><strong>Purchase Date</strong><br/>
                <input type="date" name="sde_cc_purchase_date" value="<?php echo esc_attr($pdate); ?>" />
            </label></p>
            <p><label><strong>Default Depreciation Account</strong><br/>
                <input type="text" name="sde_cc_dep_account" value="<?php echo esc_attr($depacc); ?>" />
            </label></p>
        
<p><label><strong>Exclude from accounting</strong><br/>
    <label><input type="checkbox" name="sde_cc_exclude" value="1" <?php checked(get_post_meta($post->ID, 'sde_cc_exclude', true), '1'); ?> /> Do not include this Cost Center in accounting forms/reports.</label>
</label></p>

        </div>
        <?php
    }
    public static function save_meta($post_id){
        if (!isset($_POST['_sde_cc_nonce']) || !wp_verify_nonce($_POST['_sde_cc_nonce'], 'sde_cc_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $fields = [
            'sde_cc_code' => 'text',
            'sde_cc_type' => 'text',
            'sde_cc_active' => 'text',
            'sde_cc_purchase_price' => 'float',
            'sde_cc_purchase_date' => 'text',
            'sde_cc_dep_account' => 'text',
            'sde_cc_exclude' => 'check',
        ];
        foreach ($fields as $k=>$t){
                        $v = $_POST[$k] ?? '';
            if ($t==='float') $v = (string) floatval($v);
            elseif ($t==='check') $v = $v ? '1' : '0';
            else $v = sanitize_text_field($v);
            update_post_meta($post_id, $k, $v);
        }
    }
}
CCPT::init();
