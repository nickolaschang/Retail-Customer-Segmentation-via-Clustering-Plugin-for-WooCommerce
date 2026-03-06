<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Admin_Page {
	private string $plugin_url;
	private WCS_Segmentation_Service $service;

	public function __construct(string $plugin_url, ?WCS_Segmentation_Service $service = null) {
		$this->plugin_url = $plugin_url;
		$this->service = $service ?: new WCS_Segmentation_Service();
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__('Customer Segmentation', 'woo-customer-seg'),
			__('Customer Segmentation', 'woo-customer-seg'),
			WCS_Settings::CAP,
			'wcs-segmentation',
			[$this, 'render_page']
		);
	}

	public function enqueue_admin_assets(string $hook): void {
		if ($hook !== 'woocommerce_page_wcs-segmentation') {
			return;
		}
		if (!current_user_can(WCS_Settings::CAP)) {
			return;
		}
		if (!class_exists('WooCommerce')) {
			return;
		}

		wp_enqueue_style(
			'wcs-admin',
			$this->plugin_url . 'assets/admin.css',
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'wcs-chartjs',
			WCS_Settings::CHARTJS_CDN,
			[],
			'4.4.1',
			true
		);

		wp_enqueue_script(
			'wcs-admin',
			$this->plugin_url . 'assets/admin.js',
			[],
			'1.0.0',
			true
		);
	}

	public function render_page(): void {
		if (!current_user_can(WCS_Settings::CAP)) {
			wp_die(esc_html__('Permission denied.', 'woo-customer-seg'));
		}

		if (!class_exists('WooCommerce')) {
			echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is not active.', 'woo-customer-seg') . '</p></div>';
			return;
		}

		$settings = WCS_Settings::get();
		$last_run = get_option(WCS_Settings::OPT_LASTRUN, []);
		if (!is_array($last_run)) {
			$last_run = [];
		}

		$payload = null;
		if (isset($last_run['payload']) && is_array($last_run['payload'])) {
			$payload = $last_run['payload'];
		}

		$error = get_option(WCS_Settings::OPT_LASTERR, '');
		if (!is_string($error)) {
			$error = '';
		}
		if ($error !== '') {
			delete_option(WCS_Settings::OPT_LASTERR);
		}

		$export_url = $this->build_export_url();

		echo '<div id="wcs_spinner"><div class="box"><div class="row"><div class="spin"></div><div><strong>'
			. esc_html__('Processing…', 'woo-customer-seg')
			. '</strong><div class="wcs_spinner_sub">'
			. esc_html__('Computing features and clustering customers.', 'woo-customer-seg')
			. '</div></div></div></div></div>';

		echo '<div id="wcs_app" class="wcs_container">';
		echo '<div class="wcs_header">';
		echo '<div>';
		echo '<h1>' . esc_html__('Customer Segmentation', 'woo-customer-seg') . '</h1>';
		echo '<div class="wcs_sub">' . esc_html__('RFM+ customer clustering from WooCommerce orders. Server-rendered UI, no JSON.parse, no AJAX.', 'woo-customer-seg') . '</div>';
		echo '</div>';
		echo '<div class="wcs_header_actions">';
		echo '<a class="button" href="' . esc_url($export_url) . '">' . esc_html__('Export CSV', 'woo-customer-seg') . '</a>';
		echo '</div>';
		echo '</div>';

		if (isset($_GET['wcs_saved']) && $_GET['wcs_saved'] === '1') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'woo-customer-seg') . '</p></div>';
		}
		if (isset($_GET['wcs_ran']) && $_GET['wcs_ran'] === '1') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Clustering completed.', 'woo-customer-seg') . '</p></div>';
		}
		if ($error !== '') {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__('Run failed:', 'woo-customer-seg') . '</strong> ' . esc_html($error) . '</p></div>';
		}

		$this->render_controls($settings);

		if (!$payload) {
			echo '<div class="wcs_card wcs_mt12">';
			echo '<strong>' . esc_html__('No results yet.', 'woo-customer-seg') . '</strong> ';
			echo esc_html__('Click “Run Clustering” to compute segments for the selected window and features.', 'woo-customer-seg');
			echo '</div>';
			echo '</div>';
			return;
		}

		$this->render_headline_tiles($payload);
		$this->render_tabs($payload);

		echo '</div>';
	}

	public function save_settings(): void {
		if (!current_user_can(WCS_Settings::CAP)) {
			wp_die(esc_html__('Permission denied.', 'woo-customer-seg'));
		}

		check_admin_referer(WCS_Settings::NONCE_ACTION, WCS_Settings::NONCE_NAME);

		$new = WCS_Settings::sanitize_from_post($_POST);
		update_option(WCS_Settings::OPT, $new, false);

		wp_safe_redirect(add_query_arg(['page' => 'wcs-segmentation', 'wcs_saved' => '1'], admin_url('admin.php')));
		exit;
	}

	public function run_clustering(): void {
		if (!current_user_can(WCS_Settings::CAP)) {
			wp_die(esc_html__('Permission denied.', 'woo-customer-seg'));
		}

		check_admin_referer(WCS_Settings::NONCE_ACTION, WCS_Settings::NONCE_NAME);

		$settings = WCS_Settings::get();

		try {
			$payload = $this->service->build_segmentation_payload($settings);
			$last_run = [
				'time' => time(),
				'settings' => $settings,
				'payload' => $payload,
			];

			update_option(WCS_Settings::OPT_LASTRUN, $last_run, false);
			delete_option(WCS_Settings::OPT_LASTERR);

			wp_safe_redirect(add_query_arg(['page' => 'wcs-segmentation', 'wcs_ran' => '1'], admin_url('admin.php')));
			exit;
		} catch (Throwable $e) {
			update_option(WCS_Settings::OPT_LASTERR, $e->getMessage(), false);
			wp_safe_redirect(add_query_arg(['page' => 'wcs-segmentation'], admin_url('admin.php')));
			exit;
		}
	}

	public function export_csv(): void {
		if (!current_user_can(WCS_Settings::CAP)) {
			wp_die(esc_html__('Permission denied.', 'woo-customer-seg'));
		}

		$nonce = isset($_GET[WCS_Settings::NONCE_NAME]) ? sanitize_text_field((string) $_GET[WCS_Settings::NONCE_NAME]) : '';
		if (!wp_verify_nonce($nonce, WCS_Settings::NONCE_ACTION)) {
			wp_die(esc_html__('Invalid nonce.', 'woo-customer-seg'));
		}

		$last_run = get_option(WCS_Settings::OPT_LASTRUN, []);
		if (!is_array($last_run) || empty($last_run['payload']) || !is_array($last_run['payload'])) {
			wp_die(esc_html__('No run results available. Run clustering first.', 'woo-customer-seg'));
		}

		$payload = $last_run['payload'];
		$filename = 'customer-segmentation-' . gmdate('Y-m-d-His') . '.csv';

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		$out = fopen('php://output', 'w');
		if ($out === false) {
			wp_die(esc_html__('Unable to open output stream.', 'woo-customer-seg'));
		}

		$meta = $payload['meta'];

		fputcsv($out, ['Generated (UTC)', gmdate('c')]);
		fputcsv($out, ['Lookback days', (string) $meta['lookback_days']]);
		fputcsv($out, ['Algorithm', (string) $meta['algorithm']]);
		fputcsv($out, ['Normalize', !empty($meta['normalize']) ? 'yes' : 'no']);
		fputcsv($out, ['Features', implode('|', $meta['feature_labels'])]);
		fputcsv($out, []);

		$headers = ['customer_key', 'customer_id', 'email', 'segment_id', 'segment_name'];
		foreach ($meta['feature_labels'] as $lbl) {
			$headers[] = $lbl;
		}
		if (!empty($payload['meta']['normalize'])) {
			foreach ($meta['feature_labels'] as $lbl) {
				$headers[] = $lbl . ' (norm)';
			}
		}
		fputcsv($out, $headers);

		foreach ($payload['customers'] as $c) {
			$row = [$c['key'], $c['id'], $c['email'], $c['segment_id'], $c['segment_name']];
			foreach ($c['features'] as $v) {
				$row[] = $v;
			}
			if (!empty($payload['meta']['normalize'])) {
				foreach ($c['features_norm'] as $v) {
					$row[] = $v;
				}
			}
			fputcsv($out, $row);
		}

		fclose($out);
		exit;
	}

	private function build_export_url(): string {
		return add_query_arg(
			[
				'action' => 'wcs_export_csv',
				WCS_Settings::NONCE_NAME => wp_create_nonce(WCS_Settings::NONCE_ACTION),
			],
			admin_url('admin-post.php')
		);
	}

	private function render_controls(array $settings): void {
		$algo = (string) $settings['algorithm'];

		echo '<div class="wcs_card">';
		echo '<h2>' . esc_html__('Control Panel', 'woo-customer-seg') . '</h2>';

		echo '<form id="wcs_form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="wcs_save_settings" />';
		wp_nonce_field(WCS_Settings::NONCE_ACTION, WCS_Settings::NONCE_NAME);

		echo '<div class="wcs_grid">';

		echo '<div class="wcs_field">';
		echo '<label for="wcs_lookback">' . esc_html__('Data window (lookback days)', 'woo-customer-seg') . '</label>';
		echo '<input id="wcs_lookback" name="lookback_days" type="number" min="14" max="730" step="1" value="' . esc_attr((string) $settings['lookback_days']) . '" />';
		echo '<div class="wcs_help">' . esc_html__('How far back to scan orders for customer behaviour.', 'woo-customer-seg') . '</div>';
		echo '</div>';

		echo '<div class="wcs_field">';
		echo '<label for="wcs_algo">' . esc_html__('Algorithm', 'woo-customer-seg') . '</label>';
		echo '<select id="wcs_algo" name="algorithm">';
		echo '<option value="kmeans"' . selected($algo, 'kmeans', false) . '>' . esc_html__('K-Means', 'woo-customer-seg') . '</option>';
		echo '<option value="dbscan"' . selected($algo, 'dbscan', false) . '>' . esc_html__('DBSCAN', 'woo-customer-seg') . '</option>';
		echo '</select>';
		echo '<div class="wcs_help">' . esc_html__('K-Means is best for larger datasets. DBSCAN is slower in PHP.', 'woo-customer-seg') . '</div>';
		echo '</div>';

		echo '<div class="wcs_field">';
		echo '<label title="' . esc_attr__('Number of clusters (K).', 'woo-customer-seg') . '">' . esc_html__('K', 'woo-customer-seg') . '</label>';
		echo '<input name="kmeans_k" type="number" min="2" max="20" step="1" value="' . esc_attr((string) $settings['kmeans_k']) . '" />';
		echo '</div>';

		echo '<div class="wcs_field">';
		echo '<label title="' . esc_attr__('Maximum optimization iterations.', 'woo-customer-seg') . '">' . esc_html__('Max Iters', 'woo-customer-seg') . '</label>';
		echo '<input name="kmeans_max_iters" type="number" min="5" max="500" step="1" value="' . esc_attr((string) $settings['kmeans_max_iters']) . '" />';
		echo '</div>';

		echo '<div class="wcs_field">';
		echo '<label title="' . esc_attr__('Neighborhood radius (epsilon).', 'woo-customer-seg') . '">' . esc_html__('Eps', 'woo-customer-seg') . '</label>';
		echo '<input name="dbscan_eps" type="number" min="0.01" max="10" step="0.01" value="' . esc_attr((string) $settings['dbscan_eps']) . '" />';
		echo '</div>';

		echo '<div class="wcs_field">';
		echo '<label title="' . esc_attr__('Minimum points to form a dense region.', 'woo-customer-seg') . '">' . esc_html__('MinPts', 'woo-customer-seg') . '</label>';
		echo '<input name="dbscan_minpts" type="number" min="2" max="100" step="1" value="' . esc_attr((string) $settings['dbscan_minpts']) . '" />';
		echo '</div>';

		echo '<div class="wcs_field wcs_field_wide">';
		$checked_norm = !empty($settings['normalize']) ? 'checked' : '';
		echo '<label title="' . esc_attr__('Min-max scales features to 0–1 for fair clustering. Recency is inverted so “more recent” scores higher.', 'woo-customer-seg') . '">';
		echo '<input type="checkbox" name="normalize" value="1" ' . $checked_norm . ' /> ';
		echo esc_html__('Normalize features (0–1) + recency inversion', 'woo-customer-seg');
		echo '</label>';
		echo '</div>';

		echo '</div>';

		if ($algo === 'dbscan' && (int) $settings['lookback_days'] > WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS) {
			echo '<div class="wcs_warnbox"><strong>'
				. esc_html__('Safety notice:', 'woo-customer-seg')
				. '</strong> '
				. esc_html(sprintf(
					'DBSCAN runs are limited to %d lookback days in this module. Reduce lookback to %d or switch to K-Means.',
					WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS,
					WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS
				))
				. '</div>';
		}

		echo '<div class="wcs_features">';
		echo '<div class="wcs_features_title">' . esc_html__('Features', 'woo-customer-seg') . '</div>';
		echo '<div class="wcs_featrow">';

		$feature_defs = WCS_Settings::feature_defs();
		foreach ($feature_defs as $key => $def) {
			$checked = in_array($key, $settings['features'], true) ? 'checked' : '';
			echo '<label title="' . esc_attr($def['tip']) . '">';
			echo '<input type="checkbox" name="features[]" value="' . esc_attr($key) . '" ' . $checked . ' />';
			echo esc_html($def['label']);
			echo '</label>';
		}

		echo '</div>';
		echo '</div>';

		echo '<div class="wcs_actions">';
		echo '<button class="button" type="submit">' . esc_html__('Save settings', 'woo-customer-seg') . '</button>';
		echo '<span class="wcs_action_note">' . esc_html__('Save applies changes. Run uses the saved settings.', 'woo-customer-seg') . '</span>';
		echo '</div>';

		echo '</form>';

		echo '<form id="wcs_run_form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wcs_run_form">';
		echo '<input type="hidden" name="action" value="wcs_run_clustering" />';
		wp_nonce_field(WCS_Settings::NONCE_ACTION, WCS_Settings::NONCE_NAME);
		echo '<button class="button wcs_primary" id="wcs_run_btn" type="submit">' . esc_html__('Run Clustering', 'woo-customer-seg') . '</button>';
		echo '<span class="wcs_action_note wcs_action_note_ml">' . esc_html__('Run recomputes from orders in the selected window.', 'woo-customer-seg') . '</span>';
		echo '</form>';

		echo '</div>';
	}

	private function render_headline_tiles(array $payload): void {
		$tiles = $payload['tiles'];

		echo '<div class="wcs_card wcs_mt12">';
		echo '<h2>' . esc_html__('Summary', 'woo-customer-seg') . '</h2>';
		echo '<div class="wcs_kpis">';

		foreach ($tiles as $t) {
			echo '<div class="wcs_card wcs_kpi_card">';
			echo '<div class="wcs_kpi_label">' . esc_html((string) $t['label']) . '</div>';
			echo '<div class="wcs_kpi_value">' . esc_html((string) $t['value']) . '</div>';
			if (!empty($t['sub'])) {
				echo '<div class="wcs_kpi_sub">' . esc_html((string) $t['sub']) . '</div>';
			}
			echo '</div>';
		}

		echo '</div>';

		echo '<div class="wcs_summary_meta">';
		echo esc_html(
			'Customers: ' . $payload['meta']['customers'] .
			' • Orders scanned: ' . $payload['meta']['orders'] .
			' • Lookback: ' . $payload['meta']['lookback_days'] .
			' days • Algorithm: ' . $payload['meta']['algorithm'] .
			' • Features: ' . implode(', ', $payload['meta']['feature_labels'])
		);
		echo '</div>';

		echo '</div>';
	}

	private function render_tabs(array $payload): void {
		$charts = $payload['charts'];
		$rankings = $payload['rankings'];
		$segments = $payload['segments'];

		echo '<div class="wcs_tabs">';
		echo '<div class="wcs_tab is_active" data-tab="charts">' . esc_html__('Charts', 'woo-customer-seg') . '</div>';
		echo '<div class="wcs_tab" data-tab="rankings">' . esc_html__('Rankings', 'woo-customer-seg') . '</div>';
		echo '<div class="wcs_tab" data-tab="segments">' . esc_html__('Segments', 'woo-customer-seg') . '</div>';
		echo '<div class="wcs_tab" data-tab="about">' . esc_html__('About', 'woo-customer-seg') . '</div>';
		echo '</div>';

		echo '<div class="wcs_panels">';

		echo '<div class="wcs_panel" id="wcs_panel_charts">';
		echo '<div class="wcs_chartgrid">';
		echo '<div class="wcs_card"><h3>' . esc_html__('Segment Profiles (Radar)', 'woo-customer-seg') . '</h3><div class="wcs_canvaswrap"><canvas id="wcs_radar"></canvas></div><div class="wcs_help">' . esc_html__('Centroids across the selected feature set (normalized if enabled).', 'woo-customer-seg') . '</div></div>';
		echo '<div class="wcs_card"><h3>' . esc_html__('Segment Sizes (Bar)', 'woo-customer-seg') . '</h3><div class="wcs_canvaswrap"><canvas id="wcs_bar"></canvas></div><div class="wcs_help">' . esc_html__('Absolute customer count per segment.', 'woo-customer-seg') . '</div></div>';
		echo '<div class="wcs_card"><h3>' . esc_html__('Segment Mix (Donut)', 'woo-customer-seg') . '</h3><div class="wcs_canvaswrap"><canvas id="wcs_donut"></canvas></div><div class="wcs_help">' . esc_html((string) $charts['mix_caption']) . '</div></div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wcs_panel" id="wcs_panel_rankings" style="display:none;">';
		echo '<div class="wcs_chartgrid">';

		echo '<div class="wcs_card"><h3>' . esc_html__('Top Categories', 'woo-customer-seg') . '</h3>';
		if (empty($rankings['top_categories'])) {
			echo '<em>' . esc_html__('No category data.', 'woo-customer-seg') . '</em>';
		} else {
			echo '<ol class="wcs_list_ol">';
			foreach ($rankings['top_categories'] as $row) {
				$rev = $row['revenue_fmt'] ?? ($row['revenue_fmt_plain'] ?? '');
				echo '<li>' . esc_html($row['name']) . ' — ' . esc_html($rev) . ' • ' . esc_html($row['orders']) . ' ' . esc_html__('orders', 'woo-customer-seg') . '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';

		echo '<div class="wcs_card"><h3>' . esc_html__('Top Products', 'woo-customer-seg') . '</h3>';
		if (empty($rankings['top_products'])) {
			echo '<em>' . esc_html__('No product data.', 'woo-customer-seg') . '</em>';
		} else {
			echo '<ol class="wcs_list_ol">';
			foreach ($rankings['top_products'] as $row) {
				$rev = $row['revenue_fmt'] ?? ($row['revenue_fmt_plain'] ?? '');
				echo '<li>' . esc_html($row['name']) . ' — ' . esc_html($rev) . ' • ' . esc_html($row['qty']) . ' ' . esc_html__('qty', 'woo-customer-seg') . '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';

		echo '<div class="wcs_card"><h3>' . esc_html__('Behaviour Leaders', 'woo-customer-seg') . '</h3>';
		if (empty($rankings['behaviour_leaders'])) {
			echo '<em>' . esc_html__('No leaders computed.', 'woo-customer-seg') . '</em>';
		} else {
			echo '<ul class="wcs_list_ul">';
			foreach ($rankings['behaviour_leaders'] as $row) {
				echo '<li><strong>' . esc_html($row['behaviour']) . ':</strong> ' . esc_html($row['segment']) . ' (' . esc_html($row['value']) . ')</li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '</div>';
		echo '</div>';

		echo '<div class="wcs_panel" id="wcs_panel_segments" style="display:none;">';
		echo '<div class="wcs_seg_note">' . esc_html__('Sorted by size. Centroid values reflect averages for the selected features.', 'woo-customer-seg') . '</div>';

		foreach ($segments as $seg) {
			$mid = 'wcs_mem_' . esc_attr($seg['id']);

			echo '<div class="wcs_segcard">';
			echo '<div class="wcs_segheader">';
			echo '<div>';
			echo '<div class="wcs_segname">' . esc_html($seg['name']) . ' <span class="wcs_segcount">(' . esc_html($seg['count']) . ' ' . esc_html__('customers', 'woo-customer-seg') . ')</span></div>';
			echo '<div class="wcs_segmeta">' . esc_html($seg['suggestion']) . '</div>';
			echo '</div>';
			echo '</div>';

			echo '<div class="wcs_row">';

			echo '<div class="wcs_col">';
			echo '<div class="wcs_section_label">' . esc_html__('Members', 'woo-customer-seg') . '</div>';
			echo '<div class="wcs_mono">' . esc_html(implode("\n", $seg['members_preview'])) . '</div>';
			if (!empty($seg['members_rest'])) {
				echo '<div id="' . $mid . '" class="wcs_mono wcs_mt8" style="display:none;">' . esc_html(implode("\n", $seg['members_rest'])) . '</div>';
				echo '<button type="button" class="button wcs_mt8" onclick="(function(){var el=document.getElementById(\'' . $mid . '\'); if(!el)return; el.style.display=(el.style.display===\'none\'?\'block\':\'none\');})()">' . esc_html__('Show more / less', 'woo-customer-seg') . '</button>';
			}
			echo '</div>';

			echo '<div class="wcs_col">';
			echo '<div class="wcs_section_label">' . esc_html__('Top Products', 'woo-customer-seg') . '</div>';
			if (empty($seg['top_products'])) {
				echo '<em>' . esc_html__('No product data.', 'woo-customer-seg') . '</em>';
			} else {
				foreach ($seg['top_products'] as $p) {
					echo '<span class="wcs_chip">' . esc_html($p) . '</span>';
				}
			}
			echo '</div>';

			echo '<div class="wcs_col wcs_col_large">';
			echo '<div class="wcs_section_label">' . esc_html__('Centroid values', 'woo-customer-seg') . '</div>';
			echo '<div class="wcs_tablewrap"><table class="wcs_table"><thead><tr>';
			foreach ($payload['meta']['feature_labels'] as $lbl) {
				echo '<th>' . esc_html($lbl) . '</th>';
			}
			echo '</tr></thead><tbody><tr>';
			foreach ($seg['centroid'] as $val) {
				echo '<td>' . esc_html($val) . '</td>';
			}
			echo '</tr></tbody></table></div>';
			echo '</div>';

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		echo '<div class="wcs_panel" id="wcs_panel_about" style="display:none;">';
		echo '<div class="wcs_card">';
		echo '<h3>' . esc_html__('Algorithm & integration notes', 'woo-customer-seg') . '</h3>';
		echo '<p class="wcs_about_p">' . esc_html__('This module computes customer behaviour features from WooCommerce orders in the selected window. Features can be normalized (0–1). Recency is inverted under normalization so “more recent” customers score higher.', 'woo-customer-seg') . '</p>';
		echo '<ul class="wcs_about_ul">';
		echo '<li>' . esc_html__('K-Means uses Euclidean distance. Random init is deterministic per run.', 'woo-customer-seg') . '</li>';
		echo '<li>' . esc_html__('DBSCAN is O(n²) in this simple PHP implementation; best for smaller cohorts.', 'woo-customer-seg') . '</li>';
		echo '<li>' . esc_html__('No AJAX and no JSON.parse. Charts are built from PHP-emitted JS literals.', 'woo-customer-seg') . '</li>';
		echo '</ul>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		$this->render_charts_js($payload['charts']);
	}

	private function render_charts_js(array $charts): void {
		$feature_labels = $charts['feature_labels'];
		$seg_names = $charts['segment_names'];
		$seg_sizes = $charts['segment_sizes'];
		$seg_mix = $charts['segment_mix_percent'];
		$radar_datasets = $charts['radar_datasets'];

		$radarSets = [];
		foreach ($radar_datasets as $ds) {
			$radarSets[] = [
				'label' => (string) $ds['label'],
				'data' => array_map('floatval', (array) $ds['data']),
				'tension' => 0.15,
				'pointRadius' => 0,
			];
		}

		$jsRadarLabels = wp_json_encode(array_values($feature_labels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$jsSegNames = wp_json_encode(array_values($seg_names), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$jsSegSizes = wp_json_encode(array_values(array_map('floatval', $seg_sizes)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$jsSegMix = wp_json_encode(array_values(array_map('floatval', $seg_mix)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$jsRadarSets = wp_json_encode($radarSets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		echo '<script type="text/javascript">
		(function(){
			if (typeof Chart === "undefined") {
				console.error("WCS: Chart.js not available.");
				return;
			}

			var radarLabels = ' . $jsRadarLabels . ';
			var segNames    = ' . $jsSegNames . ';
			var segSizes    = ' . $jsSegSizes . ';
			var segMix      = ' . $jsSegMix . ';
			var radarSets   = ' . $jsRadarSets . ';

			var charts = { radar:null, bar:null, donut:null };

			function destroyAll(){
				try{ if(charts.radar){ charts.radar.destroy(); } }catch(e){}
				try{ if(charts.bar){ charts.bar.destroy(); } }catch(e){}
				try{ if(charts.donut){ charts.donut.destroy(); } }catch(e){}
				charts = { radar:null, bar:null, donut:null };
			}

			function buildRadar(){
				var el = document.getElementById("wcs_radar");
				if(!el) return null;
				return new Chart(el, {
					type: "radar",
					data: { labels: radarLabels, datasets: radarSets },
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: { legend: { position: "top" } },
						scales: { r: { beginAtZero: true } }
					}
				});
			}

			function buildBar(){
				var el = document.getElementById("wcs_bar");
				if(!el) return null;
				return new Chart(el, {
					type: "bar",
					data: { labels: segNames, datasets: [{ label: "Customers", data: segSizes }] },
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: { legend: { display: false } },
						scales: { y: { beginAtZero: true } }
					}
				});
			}

			function buildDonut(){
				var el = document.getElementById("wcs_donut");
				if(!el) return null;
				return new Chart(el, {
					type: "doughnut",
					data: { labels: segNames, datasets: [{ label: "Mix %", data: segMix }] },
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							legend: { position: "top" },
							tooltip: {
								callbacks: {
									label: function(ctx){
										var v = ctx.raw;
										return ctx.label + ": " + Number(v).toFixed(1) + "%";
									}
								}
							}
						}
					}
				});
			}

			function initCharts(){
				destroyAll();
				try{
					charts.radar = buildRadar();
					charts.bar = buildBar();
					charts.donut = buildDonut();
				}catch(e){
					console.error("WCS: chart init exception", e);
				}
			}

			initCharts();

			window.wcsCharts = {
				resizeAll: function(){
					try{ if(charts.radar) charts.radar.resize(); }catch(e){}
					try{ if(charts.bar) charts.bar.resize(); }catch(e){}
					try{ if(charts.donut) charts.donut.resize(); }catch(e){}
				},
				rebuild: initCharts
			};
		})();
		</script>';
	}
}