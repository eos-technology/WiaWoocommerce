<?php
/**
 * Plugin Name: Wia Gateway - WooCommerce
 * Description: Wia Ecosistem es el ecosistema de pagos más completos de Criptomonedas y Fiat. Aceptamos más de 20+ criptos y tarjetas de crédito.
 * Version: 1.0.0
 * Author: Wia
 * Author URI: http://wiabank.com
 * Tested up to: 6.4
 * WC requires at least: 7.4
 * WC tested up to: 8.3
 * Text Domain: woo-wia-gateway
 * Domain Path: /i18n/languages/
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verificar que WooCommerce está activo.
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    // Incluir la clase del gateway si WooCommerce está activo.
    add_action( 'plugins_loaded', 'init_wia_gateway_class' );
    function init_wia_gateway_class() {
        flush_rewrite_rules();
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-wia.php';

        $gateway = new WC_Gateway_Wia(); // Asegúrate de que esta línea está presente y se ejecuta.
    }

    // Añadir el gateway a WooCommerce.
    add_filter( 'woocommerce_payment_gateways', 'add_wia_gateway_class' );
    function add_wia_gateway_class( $methods ) {
        $methods[] = 'WC_Gateway_Wia'; // Tu clase de gateway.
        return $methods;
    }

    // Añadir enlace de configuración.
    add_filter("plugin_action_links_" . plugin_basename(__FILE__), 'wia_add_settings_link');
    function wia_add_settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=wia">' . __('Configuración', 'woo-wia-gateway') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    register_activation_hook(__FILE__, 'wia_activate');
    function wia_activate() {
        wia_add_rewrite_rules();
        flush_rewrite_rules();
    }

    register_deactivation_hook(__FILE__, 'wia_deactivate');
    function wia_deactivate() {
        flush_rewrite_rules();
    }

    function wia_add_rewrite_rules() {
        add_rewrite_rule('^wia-payment-webhook/?$', 'index.php?wia_payment_webhook=1', 'top');
    }

    add_action('init', 'wia_add_rewrite_rules');
}

