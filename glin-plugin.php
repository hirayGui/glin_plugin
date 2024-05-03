<?php 

/**
 * Plugin Name: GlinPays Plugin
 * Plugin URI:  https://github.com/hirayGui/glin_plugin
 * Author: Gizo Digital
 * Author URI: https://gizo.com.br/
 * Description: Plugin que permite que o vendedor receba seus pagamentos no woocommerce através de sua conta Glin
 * Version: 1.0.0
 * License:     GPL-2.0+
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: glin-plugin 
 * 
 * Class WC_Glin_Gateway file.
 *
 * @package WooCommerce\glin-plugin 
 */

 if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

//condição verifica se plugin woocommerce está ativo
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

//função permite ativação de plugin
add_action('plugins_loaded', 'glin_init', 11);
add_filter('woocommerce_payment_gateways', 'add_to_woo_glin_gateway');

function glin_init()
{
	if (class_exists('WC_Payment_Gateway')) {
        require_once plugin_dir_path( __FILE__ ) . '/includes/class-glin-gateway.php';
    }
}

function add_to_woo_glin_gateway($gateways){
   $gateways[] = 'WC_Glin_Gateway';
   return $gateways;
}