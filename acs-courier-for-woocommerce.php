<?php
/**
 * Plugin Name:       ACS Courier for WooCommerce
 * Plugin URI:        https://kdvassiliougroup.com/products/acs-courier-for-woocommerce/
 * Description:       Create ACS Courier vouchers and track shipments from WooCommerce. Supports Greece and Cyprus.
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            K.D. Vassiliou Group
 * Author URI:        https://kdvassiliougroup.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acs-courier-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package AcsCourier
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! class_exists( '\AcsCourier\Plugin' ) ) {
	return;
}

\AcsCourier\Plugin::boot( __FILE__ );
