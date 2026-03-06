<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Settings {
	public const CAP = 'manage_woocommerce';
	public const OPT = 'wcs_settings';
	public const OPT_LASTRUN = 'wcs_last_run';
	public const OPT_LASTERR = 'wcs_last_error';

	public const NONCE_ACTION = 'wcs_nonce_action';
	public const NONCE_NAME = 'wcs_nonce';

	public const CHARTJS_CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
	public const DBSCAN_MAX_LOOKBACK_DAYS = 50;

	public static function defaults(): array {
		return [
			'lookback_days' => 120,
			'algorithm' => 'kmeans',
			'kmeans_k' => 5,
			'kmeans_max_iters' => 60,
			'dbscan_eps' => 0.35,
			'dbscan_minpts' => 8,
			'normalize' => true,
			'features' => [
				'recency_days',
				'frequency',
				'monetary',
				'aov',
				'product_variety',
				'discount_reliance',
			],
		];
	}

	public static function feature_defs(): array {
		return [
			'recency_days' => [
				'label' => __('Recency (days)', 'woo-customer-seg'),
				'tip'   => __('Days since last order in the window. Lower means more recent.', 'woo-customer-seg'),
			],
			'frequency' => [
				'label' => __('Frequency (orders)', 'woo-customer-seg'),
				'tip'   => __('Number of orders placed in the window.', 'woo-customer-seg'),
			],
			'monetary' => [
				'label' => __('Monetary (total $)', 'woo-customer-seg'),
				'tip'   => __('Total spend across orders in the window (order total).', 'woo-customer-seg'),
			],
			'aov' => [
				'label' => __('Avg Order Value ($)', 'woo-customer-seg'),
				'tip'   => __('Average order value: total spend divided by number of orders.', 'woo-customer-seg'),
			],
			'product_variety' => [
				'label' => __('Product Variety', 'woo-customer-seg'),
				'tip'   => __('Count of unique products purchased in the window.', 'woo-customer-seg'),
			],
			'category_variety' => [
				'label' => __('Category Variety', 'woo-customer-seg'),
				'tip'   => __('Count of unique product categories purchased in the window.', 'woo-customer-seg'),
			],
			'discount_reliance' => [
				'label' => __('Discount Reliance (0–1)', 'woo-customer-seg'),
				'tip'   => __('Share of discount vs. subtotal. Higher means more deal-driven.', 'woo-customer-seg'),
			],
		];
	}

	public static function get(): array {
		$defaults = self::defaults();
		$opt = get_option(self::OPT, []);

		if (!is_array($opt)) {
			$opt = [];
		}

		$merged = array_merge($defaults, $opt);

		$merged['lookback_days'] = max(14, min(730, (int) $merged['lookback_days']));
		$merged['algorithm'] = in_array((string) $merged['algorithm'], ['kmeans', 'dbscan'], true) ? (string) $merged['algorithm'] : 'kmeans';
		$merged['kmeans_k'] = max(2, min(20, (int) $merged['kmeans_k']));
		$merged['kmeans_max_iters'] = max(5, min(500, (int) $merged['kmeans_max_iters']));
		$merged['dbscan_eps'] = max(0.01, min(10.0, (float) $merged['dbscan_eps']));
		$merged['dbscan_minpts'] = max(2, min(100, (int) $merged['dbscan_minpts']));
		$merged['normalize'] = (bool) $merged['normalize'];

		$defs = self::feature_defs();
		if (!is_array($merged['features'])) {
			$merged['features'] = $defaults['features'];
		}

		$clean = [];
		foreach ($merged['features'] as $f) {
			$f = sanitize_text_field((string) $f);
			if (isset($defs[$f])) {
				$clean[] = $f;
			}
		}

		$clean = array_values(array_unique($clean));
		if (count($clean) === 0) {
			$clean = $defaults['features'];
		}

		$merged['features'] = $clean;

		return $merged;
	}

	public static function sanitize_from_post(array $post): array {
		$current = self::get();

		$lookback = isset($post['lookback_days']) ? (int) $post['lookback_days'] : $current['lookback_days'];
		$lookback = max(14, min(730, $lookback));

		$algo = isset($post['algorithm']) ? sanitize_text_field((string) $post['algorithm']) : $current['algorithm'];
		if (!in_array($algo, ['kmeans', 'dbscan'], true)) {
			$algo = 'kmeans';
		}

		$k = isset($post['kmeans_k']) ? (int) $post['kmeans_k'] : $current['kmeans_k'];
		$k = max(2, min(20, $k));

		$max_iters = isset($post['kmeans_max_iters']) ? (int) $post['kmeans_max_iters'] : $current['kmeans_max_iters'];
		$max_iters = max(5, min(500, $max_iters));

		$eps = isset($post['dbscan_eps']) ? (float) $post['dbscan_eps'] : (float) $current['dbscan_eps'];
		$eps = max(0.01, min(10.0, $eps));

		$minpts = isset($post['dbscan_minpts']) ? (int) $post['dbscan_minpts'] : $current['dbscan_minpts'];
		$minpts = max(2, min(100, $minpts));

		$normalize = isset($post['normalize']) && (string) $post['normalize'] === '1';

		$features = [];
		if (isset($post['features']) && is_array($post['features'])) {
			foreach ($post['features'] as $f) {
				$features[] = sanitize_text_field((string) $f);
			}
		}

		$valid = array_keys(self::feature_defs());
		$features = array_values(array_unique(array_values(array_filter($features, static function ($f) use ($valid) {
			return in_array($f, $valid, true);
		}))));

		if (count($features) === 0) {
			$features = self::defaults()['features'];
		}

		return [
			'lookback_days' => $lookback,
			'algorithm' => $algo,
			'kmeans_k' => $k,
			'kmeans_max_iters' => $max_iters,
			'dbscan_eps' => $eps,
			'dbscan_minpts' => $minpts,
			'normalize' => $normalize,
			'features' => $features,
		];
	}
}