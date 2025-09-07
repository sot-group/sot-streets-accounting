<?php
if (!defined('ABSPATH')) exit;

/**
 * Vehicle Documents meta box for CPT sde_cost_center
 * - Upload/store one PDF for STMK
 * - Upload/store one PDF for Inspection
 * Stores attachment IDs in post meta:
 *   - sde_cc_doc_stmk
 *   - sde_cc_doc_inspection
 */

add_action('add_meta_boxes', function(){
    add_meta_box(
        'sde_vehicle_docs',
        __('Vehicle Documents', 'sdem'),
        'sde_vehicle_docs_render',
        'sde_cost_center',
        'normal',
        'default'
    );
}, 12); // run after details box

function sde_vehicle_docs_render($post){
    wp_nonce_field('sde_vehicle_docs_save', '_sde_vehicle_docs_nonce');
    // Ensure media modal is available
    wp_enqueue_media();

    $stmk_id = intval(get_post_meta($post->ID, 'sde_cc_doc_stmk', true));
    $insp_id = intval(get_post_meta($post->ID, 'sde_cc_doc_inspection', true));

    $stmk_url = $stmk_id ? wp_get_attachment_url($stmk_id) : '';
    $insp_url = $insp_id ? wp_get_attachment_url($insp_id) : '';

    ?>
    <style>
      .sde-doc-row{ display:grid; grid-template-columns: 180px 1fr auto auto; align-items:center; gap:10px; margin-bottom:10px; }
      .sde-doc-row .label{ font-weight: 600; }
      .sde-doc-row .file{ color:#555; }
    </style>

    <div class="sde-doc-row">
      <div class="label"><?php echo esc_html__('STMK (PDF)', 'sdem'); ?></div>
      <div class="file">
        <input type="hidden" name="sde_cc_doc_stmk" id="sde_cc_doc_stmk" value="<?php echo esc_attr($stmk_id); ?>" />
        <span id="sde_cc_doc_stmk_name"><?php echo $stmk_url ? esc_html(basename(parse_url($stmk_url, PHP_URL_PATH))) : esc_html__('No file selected', 'sdem'); ?></span>
        <?php if ($stmk_url): ?>
          &nbsp;—&nbsp;<a href="<?php echo esc_url($stmk_url); ?>" target="_blank"><?php esc_html_e('View', 'sdem'); ?></a>
        <?php endif; ?>
      </div>
      <div><button type="button" class="button" id="btn_pick_stmk"><?php esc_html_e('Choose PDF', 'sdem'); ?></button></div>
      <div><button type="button" class="button" id="btn_clear_stmk"><?php esc_html_e('Clear', 'sdem'); ?></button></div>
    </div>

    <div class="sde-doc-row">
      <div class="label"><?php echo esc_html__('Inspection (PDF)', 'sdem'); ?></div>
      <div class="file">
        <input type="hidden" name="sde_cc_doc_inspection" id="sde_cc_doc_inspection" value="<?php echo esc_attr($insp_id); ?>" />
        <span id="sde_cc_doc_inspection_name"><?php echo $insp_url ? esc_html(basename(parse_url($insp_url, PHP_URL_PATH))) : esc_html__('No file selected', 'sdem'); ?></span>
        <?php if ($insp_url): ?>
          &nbsp;—&nbsp;<a href="<?php echo esc_url($insp_url); ?>" target="_blank"><?php esc_html_e('View', 'sdem'); ?></a>
        <?php endif; ?>
      </div>
      <div><button type="button" class="button" id="btn_pick_insp"><?php esc_html_e('Choose PDF', 'sdem'); ?></button></div>
      <div><button type="button" class="button" id="btn_clear_insp"><?php esc_html_e('Clear', 'sdem'); ?></button></div>
    </div>

    <script>
    (function($){
        function openPdfPicker(cb){
            const frame = wp.media({
                title: 'Select PDF',
                button: { text: 'Use this file' },
                multiple: false,
                library: { type: 'application/pdf' }
            });
            frame.on('select', function(){
                const sel = frame.state().get('selection').first();
                const id = sel.get('id');
                const url = sel.get('url');
                cb(id, url);
            });
            frame.open();
        }

        $('#btn_pick_stmk').on('click', function(e){
            e.preventDefault();
            openPdfPicker(function(id, url){
                $('#sde_cc_doc_stmk').val(id);
                $('#sde_cc_doc_stmk_name').text(url ? url.split('/').pop() : 'PDF selected');
            });
        });
        $('#btn_clear_stmk').on('click', function(){
            $('#sde_cc_doc_stmk').val('');
            $('#sde_cc_doc_stmk_name').text('No file selected');
        });

        $('#btn_pick_insp').on('click', function(e){
            e.preventDefault();
            openPdfPicker(function(id, url){
                $('#sde_cc_doc_inspection').val(id);
                $('#sde_cc_doc_inspection_name').text(url ? url.split('/').pop() : 'PDF selected');
            });
        });
        $('#btn_clear_insp').on('click', function(){
            $('#sde_cc_doc_inspection').val('');
            $('#sde_cc_doc_inspection_name').text('No file selected');
        });
    })(jQuery);
    </script>
    <?php
}

add_action('save_post', function($post_id){
    if (!isset($_POST['_sde_vehicle_docs_nonce']) || !wp_verify_nonce($_POST['_sde_vehicle_docs_nonce'], 'sde_vehicle_docs_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (get_post_type($post_id)!=='sde_cost_center') return;
    if (!current_user_can('edit_post', $post_id)) return;

    $stmk = isset($_POST['sde_cc_doc_stmk']) ? intval($_POST['sde_cc_doc_stmk']) : 0;
    $insp = isset($_POST['sde_cc_doc_inspection']) ? intval($_POST['sde_cc_doc_inspection']) : 0;

    // Optional: validate mime types are PDFs
    if ($stmk){
        $mime = get_post_mime_type($stmk);
        if ($mime !== 'application/pdf'){ $stmk = 0; }
    }
    if ($insp){
        $mime = get_post_mime_type($insp);
        if ($mime !== 'application/pdf'){ $insp = 0; }
    }

    update_post_meta($post_id, 'sde_cc_doc_stmk', $stmk);
    update_post_meta($post_id, 'sde_cc_doc_inspection', $insp);
}, 10, 1);
