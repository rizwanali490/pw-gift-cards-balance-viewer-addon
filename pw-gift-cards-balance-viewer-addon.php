<?php
/**
 * Plugin Name: PW Gift Cards Balance Viewer Addon
 * Description: Adds a WooCommerce My Account endpoint "gift-cards" where customers can view their own PW WooCommerce Gift Cards card number, original amount, current balance, used balance, created date, and expiry date.
 * Version: 1.3.0
 * Author: Rizwan Ilyas
 * Author URI: https://rizwandevs.com
 * Plugin URI: https://github.com/rizwanali490/pw-gift-cards-balance-viewer-addon
 * Requires Plugins: woocommerce, pw-woocommerce-gift-cards
 * Text Domain: riz-pwgc-account
 */

if (!defined('ABSPATH')) exit;

define('RIZ_PWGC_ACCOUNT_FILE', __FILE__);
define('RIZ_PWGC_ACCOUNT_PATH', plugin_dir_path(__FILE__));
define('RIZ_PWGC_ACCOUNT_URL', plugin_dir_url(__FILE__));

require_once RIZ_PWGC_ACCOUNT_PATH . 'includes/class-pwgc-account.php';

register_activation_hook(__FILE__, array('RIZ_PWGC_Account', 'activate'));
register_deactivation_hook(__FILE__, array('RIZ_PWGC_Account', 'deactivate'));

add_action('plugins_loaded', function () {
    RIZ_PWGC_Account::instance();
});
