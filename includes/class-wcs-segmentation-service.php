<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Segmentation_Service {
	private WCS_Data_Extractor $extractor;
	private WCS_Clustering $clustering;

	public function __construct(?WCS_Data_Extractor $extractor = null, ?WCS_Clustering $clustering = null) {
		$this->extractor = $extractor ?: new WCS_Data_Extractor();
		$this->clustering = $clustering ?: new WCS_Clustering();
	}

	public function build_segmentation_payload(array $settings): array {
		$algorithm = (string) $settings['algorithm'];
		$lookback_days = (int) $settings['lookback_days'];

		if ($algorithm === 'dbscan' && $lookback_days > WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS) {
			throw new RuntimeException(
				'DBSCAN is limited to ' . WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS . ' lookback days for safety (to prevent slow execution in PHP). ' .
				'Please switch to K-Means or reduce the lookback window to ' . WCS_Settings::DBSCAN_MAX_LOOKBACK_DAYS . ' days or less.'
			);
		}

		$tz = wp_timezone();
		$now = new DateTimeImmutable('now', $tz);

		$start = $now->sub(new DateInterval('P' . $lookback_days . 'D'));
		$start_local = $start->setTime(0, 0, 0)->setTimezone($tz)->format('Y-m-d H:i:s');
		$end_local = $now->setTime(23, 59, 59)->setTimezone($tz)->format('Y-m-d H:i:s');
		$date_range = $start_local . '...' . $end_local;

		$extracted = $this->extractor->extract_from_orders($date_range);

		$customers_raw = $extracted['customers'];
		$order_count = $extracted['orders_scanned'];

		$feature_defs = WCS_Settings::feature_defs();
		$selected = $settings['features'];

		$feature_labels = [];
		foreach ($selected as $k) {
			$feature_labels[] = $feature_defs[$k]['label'];
		}

		$customer_keys = array_keys($customers_raw);
		$vectors = [];
		$customer_list = [];

		foreach ($customer_keys as $ck) {
			$c = $customers_raw[$ck];
			$freq = (int) $c['orders'];

			if ($freq <= 0) {
				continue;
			}

			$mon = (float) $c['monetary'];
			$aov = $freq > 0 ? ($mon / $freq) : 0.0;

			$last_ts = (int) $c['last_ts'];
			$recency_days = ($last_ts > 0)
				? max(0.0, floor((($now->getTimestamp() - $last_ts) / 86400.0)))
				: (float) $lookback_days;

			$product_var = (float) count($c['products']);
			$cat_var = (float) count($c['categories']);

			$discount_sum = (float) $c['discount_total'];
			$subtotal_sum = max(0.0, (float) $c['subtotal_total']);
			$disc_rel = ($subtotal_sum > 0.0) ? ($discount_sum / $subtotal_sum) : 0.0;
			$disc_rel = max(0.0, min(1.0, $disc_rel));

			$map = [
				'recency_days' => (float) $recency_days,
				'frequency' => (float) $freq,
				'monetary' => (float) $mon,
				'aov' => (float) $aov,
				'product_variety' => (float) $product_var,
				'category_variety' => (float) $cat_var,
				'discount_reliance' => (float) $disc_rel,
			];

			$vec = [];
			foreach ($selected as $k) {
				$vec[] = (float) $map[$k];
			}

			$vectors[] = $vec;
			$customer_list[] = [
				'key' => (string) $ck,
				'id' => (string) $c['id'],
				'email' => (string) $c['email'],
			];
		}

		$n = count($vectors);
		if ($n < 2) {
			throw new RuntimeException('Not enough customers in the selected window to cluster.');
		}

		$vectors_norm = $vectors;
		$mins = array_fill(0, count($selected), INF);
		$maxs = array_fill(0, count($selected), -INF);

		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < count($selected); $j++) {
				$v = (float) $vectors[$i][$j];
				if ($v < $mins[$j]) {
					$mins[$j] = $v;
				}
				if ($v > $maxs[$j]) {
					$maxs[$j] = $v;
				}
			}
		}

		if (!empty($settings['normalize'])) {
			for ($i = 0; $i < $n; $i++) {
				for ($j = 0; $j < count($selected); $j++) {
					$den = ($maxs[$j] - $mins[$j]);
					$norm = ($den > 1e-12) ? (($vectors[$i][$j] - $mins[$j]) / $den) : 0.0;

					if ($selected[$j] === 'recency_days') {
						$norm = 1.0 - $norm;
					}

					$norm = max(0.0, min(1.0, $norm));
					$vectors_norm[$i][$j] = $norm;
				}
			}
		}

		if ($algorithm === 'dbscan') {
			if ($n > 2500) {
				throw new RuntimeException('DBSCAN too slow for ' . $n . ' customers in this PHP implementation. Use K-Means or reduce the window.');
			}

			$res = $this->clustering->dbscan($vectors_norm, (float) $settings['dbscan_eps'], (int) $settings['dbscan_minpts']);
			$cluster_ids = $res['labels'];
			$cluster_count = $res['k'];
			$centroids_norm = $this->clustering->compute_centroids($vectors_norm, $cluster_ids, true);
			$centroids_raw = $this->clustering->compute_centroids($vectors, $cluster_ids, true);
		} else {
			$k = min((int) $settings['kmeans_k'], $n);
			$res = $this->clustering->kmeans($vectors_norm, $k, (int) $settings['kmeans_max_iters']);
			$cluster_ids = $res['labels'];
			$cluster_count = $res['k'];
			$centroids_norm = $res['centroids'];
			$centroids_raw = $this->clustering->compute_centroids($vectors, $cluster_ids, true);
		}

		$segments_out = $this->build_segments_output(
			$customer_list,
			$vectors,
			$vectors_norm,
			$selected,
			$feature_labels,
			$cluster_ids,
			$centroids_raw,
			$centroids_norm,
			$extracted['customer_product_agg']
		);

		$charts = $this->build_charts($segments_out['list'], $feature_labels);
		$rankings = $this->build_rankings(
			$segments_out['list'],
			$selected,
			$feature_labels,
			$extracted['top_categories'],
			$extracted['top_products']
		);

		$top_cat = $rankings['top_categories'][0] ?? null;
		$top_prod = $rankings['top_products'][0] ?? null;
		$winner = $this->pick_behaviour_winner($segments_out['list'], $selected, !empty($settings['normalize']));

		$tiles = [
			[
				'label' => __('Total customers clustered', 'woo-customer-seg'),
				'value' => number_format_i18n((int) $n),
				'sub' => $cluster_count > 0 ? ('Segments: ' . $cluster_count) : 'Segments: 0',
			],
			[
				'label' => __('Top category', 'woo-customer-seg'),
				'value' => $top_cat ? $top_cat['name'] : __('N/A', 'woo-customer-seg'),
				'sub' => $top_cat ? (($top_cat['revenue_fmt'] ?? '') . ' • ' . ($top_cat['orders'] ?? 0) . ' orders') : '',
			],
			[
				'label' => __('Top product', 'woo-customer-seg'),
				'value' => $top_prod ? $top_prod['name'] : __('N/A', 'woo-customer-seg'),
				'sub' => $top_prod ? (($top_prod['revenue_fmt'] ?? '') . ' • ' . ($top_prod['qty'] ?? 0) . ' qty') : '',
			],
			[
				'label' => __('Behaviour Winner', 'woo-customer-seg'),
				'value' => $winner ? $winner['name'] : __('N/A', 'woo-customer-seg'),
				'sub' => $winner ? $winner['badge'] : '',
			],
		];

		$customers_export = [];
		for ($i = 0; $i < $n; $i++) {
			$sid = (int) $cluster_ids[$i];
			$sname = $segments_out['by_id'][$sid]['name'] ?? (($sid === -1) ? 'Noise' : ('Segment ' . $sid));

			$feat_vals = [];
			for ($j = 0; $j < count($selected); $j++) {
				$feat_vals[] = WCS_Utils::format_feature_value($selected[$j], (float) $vectors[$i][$j]);
			}

			$feat_norm = [];
			if (!empty($settings['normalize'])) {
				for ($j = 0; $j < count($selected); $j++) {
					$feat_norm[] = (string) round((float) $vectors_norm[$i][$j], 4);
				}
			}

			$customers_export[] = [
				'key' => $customer_list[$i]['key'],
				'id' => $customer_list[$i]['id'],
				'email' => $customer_list[$i]['email'],
				'segment_id' => (string) $sid,
				'segment_name' => (string) $sname,
				'features' => $feat_vals,
				'features_norm' => $feat_norm,
			];
		}

		$algo_label = ($algorithm === 'dbscan')
			? ('DBSCAN (eps=' . (float) $settings['dbscan_eps'] . ', minPts=' . (int) $settings['dbscan_minpts'] . ')')
			: ('K-Means (k=' . (int) $settings['kmeans_k'] . ', iters=' . (int) $settings['kmeans_max_iters'] . ')');

		return [
			'meta' => [
				'customers' => $n,
				'orders' => $order_count,
				'lookback_days' => (int) $lookback_days,
				'algorithm' => $algo_label,
				'normalize' => !empty($settings['normalize']),
				'feature_labels' => $feature_labels,
			],
			'tiles' => $tiles,
			'charts' => $charts,
			'rankings' => $rankings,
			'segments' => $segments_out['list'],
			'customers' => $customers_export,
		];
	}

	private function build_segments_output(
		array $customer_list,
		array $vectors_raw,
		array $vectors_norm,
		array $feature_keys,
		array $feature_labels,
		array $cluster_ids,
		array $centroids_raw_byid,
		array $centroids_norm_byid,
		array $customer_product_agg
	): array {
		$n = count($customer_list);

		$seg_members = [];
		for ($i = 0; $i < $n; $i++) {
			$sid = (int) $cluster_ids[$i];
			if (!isset($seg_members[$sid])) {
				$seg_members[$sid] = [];
			}
			$seg_members[$sid][] = $i;
		}

		$seg_ids = array_keys($seg_members);
		usort($seg_ids, static function ($a, $b) use ($seg_members) {
			if ((int) $a === -1 && (int) $b !== -1) {
				return 1;
			}
			if ((int) $b === -1 && (int) $a !== -1) {
				return -1;
			}
			return count($seg_members[$b]) <=> count($seg_members[$a]);
		});

		$idx = array_flip($feature_keys);

		$all_mon = [];
		$all_freq = [];
		$all_rec = [];
		$all_disc = [];
		$all_aov = [];
		$all_pvar = [];
		$all_cvar = [];

		foreach ($centroids_raw_byid as $sid2 => $c2) {
			if (isset($idx['monetary'])) {
				$all_mon[] = (float) $c2[$idx['monetary']];
			}
			if (isset($idx['frequency'])) {
				$all_freq[] = (float) $c2[$idx['frequency']];
			}
			if (isset($idx['recency_days'])) {
				$all_rec[] = (float) $c2[$idx['recency_days']];
			}
			if (isset($idx['discount_reliance'])) {
				$all_disc[] = (float) $c2[$idx['discount_reliance']];
			}
			if (isset($idx['aov'])) {
				$all_aov[] = (float) $c2[$idx['aov']];
			}
			if (isset($idx['product_variety'])) {
				$all_pvar[] = (float) $c2[$idx['product_variety']];
			}
			if (isset($idx['category_variety'])) {
				$all_cvar[] = (float) $c2[$idx['category_variety']];
			}
		}

		$mon_hi  = WCS_Utils::percentile($all_mon, 0.75);
		$mon_lo  = WCS_Utils::percentile($all_mon, 0.25);
		$freq_hi = WCS_Utils::percentile($all_freq, 0.75);
		$freq_lo = WCS_Utils::percentile($all_freq, 0.25);
		$rec_hi  = WCS_Utils::percentile($all_rec, 0.75);
		$rec_lo  = WCS_Utils::percentile($all_rec, 0.25);
		$disc_hi = WCS_Utils::percentile($all_disc, 0.75);
		$aov_hi  = WCS_Utils::percentile($all_aov, 0.75);
		$pvar_hi = WCS_Utils::percentile($all_pvar, 0.75);
		$cvar_hi = WCS_Utils::percentile($all_cvar, 0.75);

		$list = [];
		$by_id = [];

		foreach ($seg_ids as $sid) {
			$members = $seg_members[$sid];
			$count = count($members);

			$raw_cent  = $centroids_raw_byid[$sid] ?? array_fill(0, count($feature_keys), 0.0);
			$norm_cent = $centroids_norm_byid[$sid] ?? array_fill(0, count($feature_keys), 0.0);

			$name = ($sid === -1) ? 'Noise' : ('Segment ' . $sid);
			$suggestion = ($sid === -1)
				? 'Review manually: sparse / irregular behaviour.'
				: 'General targeting: test messaging & offers.';

			$rec_days = isset($idx['recency_days']) ? (float) $raw_cent[$idx['recency_days']] : 0.0;
			$freq_v   = isset($idx['frequency']) ? (float) $raw_cent[$idx['frequency']] : 0.0;
			$mon_v    = isset($idx['monetary']) ? (float) $raw_cent[$idx['monetary']] : 0.0;
			$disc_v   = isset($idx['discount_reliance']) ? (float) $raw_cent[$idx['discount_reliance']] : 0.0;
			$aov_v    = isset($idx['aov']) ? (float) $raw_cent[$idx['aov']] : 0.0;
			$pvar_v   = isset($idx['product_variety']) ? (float) $raw_cent[$idx['product_variety']] : 0.0;
			$cvar_v   = isset($idx['category_variety']) ? (float) $raw_cent[$idx['category_variety']] : 0.0;

			$has_rec = isset($idx['recency_days']);
			$has_freq = isset($idx['frequency']);

			$is_at_risk = ($sid !== -1 && $has_rec && $has_freq && ($rec_days >= $rec_hi) && ($freq_v <= $freq_hi));
			$is_loyal_hv = ($sid !== -1 && $has_rec && $has_freq && isset($idx['monetary']) && ($mon_v >= $mon_hi) && ($freq_v >= $freq_hi) && ($rec_days <= $rec_lo));
			$is_disc_driven = ($sid !== -1 && isset($idx['discount_reliance']) && $has_freq && ($disc_v >= $disc_hi) && ($freq_v >= $freq_hi));
			$is_newish = ($sid !== -1 && $has_rec && $has_freq && ($rec_days <= $rec_lo) && ($freq_v <= $freq_hi));
			$is_frequent_lowspend = ($sid !== -1 && isset($idx['monetary']) && $has_freq && ($freq_v >= $freq_hi) && ($mon_v <= $mon_hi));

			if ($sid !== -1) {
				if ($is_loyal_hv) {
					$name = 'Loyal High-Value';
					$suggestion = 'VIP perks, early access, premium bundles, referral prompts.';
				} elseif ($is_at_risk) {
					if (isset($idx['monetary']) && $mon_v >= $mon_hi) {
						$name = 'At-Risk High-Value';
						$suggestion = 'High-value win-back: personalized outreach, concierge support, premium incentives.';
					} elseif (isset($idx['discount_reliance']) && $disc_v >= $disc_hi) {
						$name = 'At-Risk Deal-Driven';
						$suggestion = 'Win-back with targeted offers; test threshold coupons to protect margin.';
					} elseif ((isset($idx['product_variety']) && $pvar_v >= $pvar_hi) || (isset($idx['category_variety']) && $cvar_v >= $cvar_hi)) {
						$name = 'At-Risk Variety-Seeker';
						$suggestion = 'Re-engage with fresh arrivals, recommendations, browse-based retargeting.';
					} else {
						$name = 'At-Risk Low-Engagement';
						$suggestion = 'Simple win-back: reminder flows, replenishment nudges, limited-time offer.';
					}
				} elseif ($is_disc_driven) {
					if (isset($idx['monetary']) && $mon_v >= $mon_hi) {
						$name = 'Discount-Driven High-Value';
						$suggestion = 'Targeted promos + loyalty perks; manage discount intensity carefully.';
					} else {
						$name = 'Discount-Driven';
						$suggestion = 'Deal cadence + targeted coupons; test thresholds to protect margin.';
					}
				} elseif ($is_frequent_lowspend) {
					$name = 'Frequent Low-Spend';
					$suggestion = 'Upsell bundles and cross-sell; free-shipping thresholds.';
				} elseif ($is_newish) {
					if (isset($idx['aov']) && $aov_v >= $aov_hi) {
						$name = 'New High-AOV';
						$suggestion = 'Onboard with premium recommendations and retention hooks.';
					} else {
						$name = 'New / Emerging';
						$suggestion = 'Onboarding series, product education, personalized recommendations.';
					}
				} else {
					if (isset($idx['monetary']) && $mon_v >= $mon_hi) {
						$name = 'Core High-Value';
						$suggestion = 'Personalized recommendations; protect retention with light perks.';
					} elseif (isset($idx['monetary']) && $mon_v <= $mon_lo && $has_freq && $freq_v <= $freq_lo) {
						$name = 'Core Low-Value';
						$suggestion = 'Nurture with education, low-friction offers, and product discovery.';
					} else {
						$name = 'Core';
						$suggestion = 'Maintain: targeted recommendations and light lifecycle optimization.';
					}
				}
			}

			$mem_lines = [];
			foreach ($members as $ci) {
				$ckey = $customer_list[$ci]['key'];
				$email = $customer_list[$ci]['email'];
				$mem_lines[] = $ckey . ' (' . $email . ')';
			}

			$preview = array_slice($mem_lines, 0, 10);
			$rest = array_slice($mem_lines, 10);

			$seg_prod_rev = [];
			foreach ($members as $ci) {
				$ck = $customer_list[$ci]['key'];
				if (isset($customer_product_agg[$ck]) && is_array($customer_product_agg[$ck])) {
					foreach ($customer_product_agg[$ck] as $pid => $rev) {
						$pid = (int) $pid;
						if (!isset($seg_prod_rev[$pid])) {
							$seg_prod_rev[$pid] = 0.0;
						}
						$seg_prod_rev[$pid] += (float) $rev;
					}
				}
			}

			arsort($seg_prod_rev);
			$top_chips = [];
			$cchip = 0;
			foreach ($seg_prod_rev as $pid => $rev) {
				$top_chips[] = WCS_Utils::product_name((int) $pid);
				if (++$cchip >= 5) {
					break;
				}
			}

			$centroid_fmt = [];
			for ($j = 0; $j < count($feature_keys); $j++) {
				$centroid_fmt[] = WCS_Utils::format_feature_value($feature_keys[$j], (float) $raw_cent[$j]);
			}

			$item = [
				'id' => (int) $sid,
				'name' => (string) $name,
				'count' => (int) $count,
				'members_preview' => $preview,
				'members_rest' => $rest,
				'top_products' => $top_chips,
				'centroid_raw' => $raw_cent,
				'centroid_norm' => $norm_cent,
				'centroid' => $centroid_fmt,
				'suggestion' => (string) $suggestion,
			];

			$list[] = $item;
			$by_id[$sid] = $item;
		}

		return [
			'list' => $list,
			'by_id' => $by_id,
		];
	}

	private function build_charts(array $segments, array $feature_labels): array {
		$seg_names = [];
		$seg_sizes = [];
		$radar_datasets = [];

		$total = 0;
		foreach ($segments as $s) {
			$total += (int) $s['count'];
		}

		foreach ($segments as $s) {
			$seg_names[] = (string) $s['name'];
			$seg_sizes[] = (float) $s['count'];

			$data = [];
			$use_norm = WCS_Utils::centroid_looks_normalized($s['centroid_norm']);
			$vec = $use_norm ? $s['centroid_norm'] : $s['centroid_raw'];

			foreach ($vec as $v) {
				$data[] = (float) $v;
			}

			$radar_datasets[] = [
				'label' => (string) $s['name'],
				'data' => $data,
			];
		}

		$mix = [];
		$top_seg = null;
		$top_pct = 0.0;

		for ($i = 0; $i < count($seg_sizes); $i++) {
			$pct = ($total > 0) ? (100.0 * $seg_sizes[$i] / $total) : 0.0;
			$mix[] = (float) round($pct, 2);

			if ($pct > $top_pct) {
				$top_pct = $pct;
				$top_seg = $seg_names[$i] ?? null;
			}
		}

		$caption = $top_seg ? ('Top segment: ' . $top_seg . ' (' . round($top_pct, 1) . '%)') : 'No segments.';

		return [
			'feature_labels' => $feature_labels,
			'segment_names' => $seg_names,
			'segment_sizes' => $seg_sizes,
			'segment_mix_percent' => $mix,
			'mix_caption' => $caption,
			'radar_datasets' => $radar_datasets,
		];
	}

	private function build_rankings(array $segments, array $feature_keys, array $feature_labels, array $top_categories, array $top_products): array {
		$leaders = [];

		for ($j = 0; $j < count($feature_keys); $j++) {
			$best = null;
			$best_val = -INF;

			foreach ($segments as $s) {
				$use_norm = WCS_Utils::centroid_looks_normalized($s['centroid_norm']);
				$cent = $use_norm ? $s['centroid_norm'] : $s['centroid_raw'];
				$v = (float) ($cent[$j] ?? 0.0);

				if ($feature_keys[$j] === 'recency_days' && !$use_norm) {
					$v = -$v;
				}

				if ($v > $best_val) {
					$best_val = $v;
					$best = $s;
				}
			}

			$leaders[] = [
				'behaviour' => (string) $feature_labels[$j],
				'segment' => $best ? (string) $best['name'] : 'N/A',
				'value' => $best ? (string) $best['centroid'][$j] : 'N/A',
			];
		}

		$cats = [];
		foreach ($top_categories as $r) {
			$fmt = $r['revenue_fmt'] ?? ($r['revenue_fmt_plain'] ?? '');
			$cats[] = [
				'name' => (string) $r['name'],
				'revenue_fmt' => (string) $fmt,
				'revenue_fmt_plain' => (string) $fmt,
				'orders' => (int) ($r['orders'] ?? 0),
			];
		}

		$prods = [];
		foreach ($top_products as $r) {
			$fmt = $r['revenue_fmt'] ?? ($r['revenue_fmt_plain'] ?? '');
			$prods[] = [
				'name' => (string) $r['name'],
				'revenue_fmt' => (string) $fmt,
				'revenue_fmt_plain' => (string) $fmt,
				'qty' => (int) ($r['qty'] ?? 0),
			];
		}

		return [
			'top_categories' => $cats,
			'top_products' => $prods,
			'behaviour_leaders' => $leaders,
		];
	}

	private function pick_behaviour_winner(array $segments, array $feature_keys, bool $normalized): ?array {
		if (empty($segments)) {
			return null;
		}

		$idx = array_flip($feature_keys);
		$best = null;
		$best_score = -INF;

		foreach ($segments as $s) {
			$use_norm = $normalized && WCS_Utils::centroid_looks_normalized($s['centroid_norm']);
			$cent = $use_norm ? $s['centroid_norm'] : $s['centroid_raw'];

			$score = 0.0;

			if (isset($idx['recency_days'])) {
				$v = (float) $cent[$idx['recency_days']];
				if (!$use_norm) {
					$v = -$v;
				}
				$score += 1.0 * $v;
			}
			if (isset($idx['frequency'])) {
				$score += 1.0 * (float) $cent[$idx['frequency']];
			}
			if (isset($idx['monetary'])) {
				$score += 1.2 * (float) $cent[$idx['monetary']];
			}
			if (isset($idx['discount_reliance'])) {
				$score += -0.4 * (float) $cent[$idx['discount_reliance']];
			}

			$score += 0.01 * (float) $s['count'];

			if ($score > $best_score) {
				$best_score = $score;
				$best = $s;
			}
		}

		if (!$best) {
			return null;
		}

		return [
			'name' => (string) $best['name'],
			'badge' => 'Leads composite behaviour score',
		];
	}
}