<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Plugin {
	private static ?self $instance = null;

	private string $plugin_file;
	private string $plugin_url;
	private WCS_Admin_Page $admin_page;

	private function __construct(string $plugin_file, string $plugin_url) {
		$this->plugin_file = $plugin_file;
		$this->plugin_url = $plugin_url;
		$this->admin_page = new WCS_Admin_Page($plugin_url);
	}

	public static function boot(string $plugin_file, string $plugin_url): void {
		if (self::$instance instanceof self) {
			return;
		}

		self::$instance = new self($plugin_file, $plugin_url);
		self::$instance->register_hooks();
	}

	private function register_hooks(): void {
		add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
		add_action('admin_menu', [$this->admin_page, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this->admin_page, 'enqueue_admin_assets']);

		add_action('admin_post_wcs_save_settings', [$this->admin_page, 'save_settings']);
		add_action('admin_post_wcs_run_clustering', [$this->admin_page, 'run_clustering']);
		add_action('admin_post_wcs_export_csv', [$this->admin_page, 'export_csv']);
	}

	public function declare_hpos_compatibility(): void {
		if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				$this->plugin_file,
				true
			);
		}
	}
}