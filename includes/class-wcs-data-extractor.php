<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Data_Extractor {
	public function extract_from_orders(string $date_range): array {
		$statuses = ['wc-processing', 'wc-completed'];
		$page = 1;
		$per_page = 500;

		$customers = [];
		$orders_scanned = 0;

		$prod_rev = [];
		$prod_qty = [];

		$cat_rev = [];
		$cat_orders = [];
		$seen_cat_order = [];

		$product_cat_cache = [];
		$customer_product_agg = [];

		do {
			$args = [
				'status' => $statuses,
				'limit' => $per_page,
				'page' => $page,
				'orderby' => 'date',
				'order' => 'ASC',
				'return' => 'objects',
				'date_created' => $date_range,
			];

			$orders = wc_get_orders($args);
			if (!is_array($orders) || count($orders) === 0) {
				break;
			}

			foreach ($orders as $o) {
				if (!($o instanceof WC_Order)) {
					continue;
				}

				$orders_scanned++;

				$cid = (int) $o->get_customer_id();
				$email = (string) $o->get_billing_email();
				$key = ($cid > 0) ? ('id:' . $cid) : ('email:' . strtolower(trim($email)));

				if ($key === 'email:' || $key === 'id:0') {
					continue;
				}

				if (!isset($customers[$key])) {
					$customers[$key] = [
						'id' => $cid > 0 ? (string) $cid : '',
						'email' => $email,
						'orders' => 0,
						'monetary' => 0.0,
						'last_ts' => 0,
						'products' => [],
						'categories' => [],
						'discount_total' => 0.0,
						'subtotal_total' => 0.0,
					];
				}

				$customers[$key]['orders'] += 1;
				$customers[$key]['monetary'] += (float) $o->get_total();
				$customers[$key]['discount_total'] += (float) $o->get_discount_total();
				$customers[$key]['subtotal_total'] += (float) $o->get_subtotal();

				$dt = $o->get_date_created();
				if ($dt instanceof WC_DateTime) {
					$ts = (int) $dt->getTimestamp();
					if ($ts > (int) $customers[$key]['last_ts']) {
						$customers[$key]['last_ts'] = $ts;
					}
				}

				$order_id = (int) $o->get_id();
				$items = $o->get_items('line_item');

				if (!is_array($items) || empty($items)) {
					continue;
				}

				foreach ($items as $it) {
					if (!($it instanceof WC_Order_Item_Product)) {
						continue;
					}

					$pid = (int) $it->get_product_id();
					if ($pid <= 0) {
						continue;
					}

					$line_total = (float) $it->get_total();
					$qty = (int) $it->get_quantity();

					$customers[$key]['products'][$pid] = 1;

					if (!isset($customer_product_agg[$key])) {
						$customer_product_agg[$key] = [];
					}
					if (!isset($customer_product_agg[$key][$pid])) {
						$customer_product_agg[$key][$pid] = 0.0;
					}
					$customer_product_agg[$key][$pid] += max(0.0, $line_total);

					if (!isset($prod_rev[$pid])) {
						$prod_rev[$pid] = 0.0;
					}
					if (!isset($prod_qty[$pid])) {
						$prod_qty[$pid] = 0;
					}
					$prod_rev[$pid] += max(0.0, $line_total);
					$prod_qty[$pid] += max(0, $qty);

					if (!isset($product_cat_cache[$pid])) {
						$product_cat_cache[$pid] = WCS_Utils::get_product_cat_ids($pid);
					}

					$cat_ids = $product_cat_cache[$pid];
					if (!empty($cat_ids)) {
						$cat_id = (int) $cat_ids[0];
						$customers[$key]['categories'][$cat_id] = 1;

						if (!isset($cat_rev[$cat_id])) {
							$cat_rev[$cat_id] = 0.0;
						}
						$cat_rev[$cat_id] += max(0.0, $line_total);

						$k2 = $cat_id . '|' . $order_id;
						if (!isset($seen_cat_order[$k2])) {
							$seen_cat_order[$k2] = 1;
							if (!isset($cat_orders[$cat_id])) {
								$cat_orders[$cat_id] = 0;
							}
							$cat_orders[$cat_id] += 1;
						}
					}
				}
			}

			$page++;
		} while (count($orders) === $per_page);

		arsort($prod_rev);
		$top_products = [];
		$i = 0;

		foreach ($prod_rev as $pid => $rev) {
			$fmt = WCS_Utils::money_plain((float) $rev);
			$top_products[] = [
				'id' => (int) $pid,
				'name' => WCS_Utils::product_name((int) $pid),
				'revenue' => (float) $rev,
				'revenue_fmt_plain' => $fmt,
				'revenue_fmt' => $fmt,
				'qty' => (int) ($prod_qty[$pid] ?? 0),
			];

			if (++$i >= 10) {
				break;
			}
		}

		arsort($cat_rev);
		$top_categories = [];
		$i = 0;

		foreach ($cat_rev as $cat_id => $rev) {
			$fmt = WCS_Utils::money_plain((float) $rev);
			$top_categories[] = [
				'id' => (int) $cat_id,
				'name' => WCS_Utils::cat_name((int) $cat_id),
				'revenue' => (float) $rev,
				'revenue_fmt_plain' => $fmt,
				'revenue_fmt' => $fmt,
				'orders' => (int) ($cat_orders[$cat_id] ?? 0),
			];

			if (++$i >= 10) {
				break;
			}
		}

		return [
			'customers' => $customers,
			'orders_scanned' => $orders_scanned,
			'top_products' => $top_products,
			'top_categories' => $top_categories,
			'customer_product_agg' => $customer_product_agg,
		];
	}
}