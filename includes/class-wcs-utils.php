<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Utils {
	public static function money_plain(float $amount): string {
		$currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
		$symbol = function_exists('get_woocommerce_currency_symbol') ? (string) get_woocommerce_currency_symbol($currency) : '$';
		$formatted = number_format_i18n($amount, 2);
		$pos = function_exists('get_option') ? (string) get_option('woocommerce_currency_pos', 'left') : 'left';

		switch ($pos) {
			case 'left_space':
				return $symbol . ' ' . $formatted;
			case 'right':
				return $formatted . $symbol;
			case 'right_space':
				return $formatted . ' ' . $symbol;
			case 'left':
			default:
				return $symbol . $formatted;
		}
	}

	public static function format_feature_value(string $key, float $value): string {
		switch ($key) {
			case 'recency_days':
			case 'frequency':
			case 'product_variety':
			case 'category_variety':
				return (string) round($value, 1);

			case 'monetary':
			case 'aov':
				return self::money_plain($value);

			case 'discount_reliance':
				return (string) round($value, 3);

			default:
				return (string) round($value, 4);
		}
	}

	public static function percentile(array $values, float $p): float {
		$vals = [];
		foreach ($values as $v) {
			if (is_numeric($v)) {
				$vals[] = (float) $v;
			}
		}

		$c = count($vals);
		if ($c === 0) {
			return 0.0;
		}

		sort($vals);
		$p = max(0.0, min(1.0, $p));
		$idx = (int) floor(($c - 1) * $p);

		return (float) $vals[$idx];
	}

	public static function product_name(int $product_id): string {
		$p = wc_get_product($product_id);
		if ($p) {
			$n = (string) $p->get_name();
			return $n !== '' ? $n : ('#' . $product_id);
		}

		$title = get_the_title($product_id);
		return $title ? (string) $title : ('#' . $product_id);
	}

	public static function cat_name(int $cat_id): string {
		$t = get_term($cat_id, 'product_cat');
		if ($t && !is_wp_error($t) && !empty($t->name)) {
			return (string) $t->name;
		}

		return '#' . $cat_id;
	}

	public static function get_product_cat_ids(int $product_id): array {
		$ids = [];

		if (function_exists('wc_get_product_term_ids')) {
			$ids = wc_get_product_term_ids($product_id, 'product_cat');
		} else {
			$terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
			if (is_array($terms)) {
				$ids = $terms;
			}
		}

		$out = [];
		if (is_array($ids)) {
			foreach ($ids as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$out[] = $id;
				}
			}
		}

		return $out;
	}

	public static function centroid_looks_normalized($vec): bool {
		if (!is_array($vec) || empty($vec)) {
			return false;
		}

		$in = 0;
		$tot = 0;

		foreach ($vec as $v) {
			$tot++;
			$v = (float) $v;
			if ($v >= -0.01 && $v <= 1.01) {
				$in++;
			}
		}

		return $tot > 0 && ($in / $tot) > 0.8;
	}
}