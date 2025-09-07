<?php
/* Core module loader for SOT Accounting Suite */
if (!defined('ABSPATH')) exit;
define('SDEM_PATH', plugin_dir_path(__FILE__));
define('SDEM_URL', plugin_dir_url(__FILE__));
define('SDEM_VER', '1.1');
spl_autoload_register(function($class){
    if (strpos($class, 'SDEM\\') !== 0) return;
    $rel = str_replace('SDEM\\', '', $class);
    $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel);
    $file = SDEM_PATH . 'includes/' . strtolower($rel) . '.php';
    if (file_exists($file)) require_once $file;
});
register_activation_hook(__FILE__, function(){
    // Guard against any activation-time output (warnings/notices)
    ob_start();
    try {
        require_once SDEM_PATH . 'includes/core/schema.php';
        SDEM\Core\Schema::install();
    } catch (\Throwable $e){
        error_log('[SDE Modular] Activation error: '.$e->getMessage());
    } finally {
        $buf = ob_get_contents();
        if ($buf !== false && strlen(trim($buf))){
            error_log('[SDE Modular] Activation output: '.trim($buf));
        }
        if (ob_get_level() > 0) { ob_end_clean(); }
    }
});
add_action('plugins_loaded', function(){
    require_once SDEM_PATH . 'includes/core/cpt.php';
    require_once SDEM_PATH . 'includes/ui/router.php';
    
    require_once SDEM_PATH . 'includes/sde-cc-mgmt.php';
    require_once SDEM_PATH . 'includes/sde-cc-report.php';
require_once SDEM_PATH . 'includes/sde-key-numbers.php';
require_once SDEM_PATH . 'includes/ui/metabox-vehicle-docs.php';
SDEM\UI\Router::init();
});
