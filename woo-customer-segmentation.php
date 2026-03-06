<?php
/**
 * Plugin Name: Woo Customer Segmentation
 * Description: Customer segmentation dashboard for WooCommerce using RFM+ features with K-Means and DBSCAN clustering.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: woo-customer-seg
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WCS_PLUGIN_FILE', __FILE__);
define('WCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WCS_PLUGIN_DIR . 'includes/class-wcs-settings.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-utils.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-clustering.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-data-extractor.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-segmentation-service.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-admin-page.php';
require_once WCS_PLUGIN_DIR . 'includes/class-wcs-plugin.php';

WCS_Plugin::boot(WCS_PLUGIN_FILE, WCS_PLUGIN_URL);