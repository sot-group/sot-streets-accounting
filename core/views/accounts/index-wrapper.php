<?php
if (!defined('ABSPATH')) exit;
echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">';
echo '<h1 style="margin:0">Accounts</h1>';
echo '<div><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=sde-modular-account-new')).'">Add New Account</a></div>';
echo '</div>';
require_once __DIR__ . '/index.php';
