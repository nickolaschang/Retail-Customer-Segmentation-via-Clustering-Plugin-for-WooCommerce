<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WCS_Clustering {
	public function kmeans(array $X, int $k, int $max_iters): array {
		$n = count($X);
		$d = count($X[0]);
		mt_srand(1337);

		$chosen = [];
		$centroids = [];

		while (count($centroids) < $k) {
			$idx = mt_rand(0, $n - 1);
			if (isset($chosen[$idx])) {
				continue;
			}
			$chosen[$idx] = 1;
			$centroids[] = $X[$idx];
		}

		$labels = array_fill(0, $n, 0);

		for ($iter = 0; $iter < $max_iters; $iter++) {
			$changed = 0;

			for ($i = 0; $i < $n; $i++) {
				$best_k = 0;
				$best_dist = INF;

				for ($c = 0; $c < $k; $c++) {
					$dist = $this->sqdist($X[$i], $centroids[$c]);
					if ($dist < $best_dist) {
						$best_dist = $dist;
						$best_k = $c;
					}
				}

				if ($labels[$i] !== $best_k) {
					$labels[$i] = $best_k;
					$changed++;
				}
			}

			$newC = array_fill(0, $k, array_fill(0, $d, 0.0));
			$cnt = array_fill(0, $k, 0);

			for ($i = 0; $i < $n; $i++) {
				$c = $labels[$i];
				$cnt[$c] += 1;
				for ($j = 0; $j < $d; $j++) {
					$newC[$c][$j] += (float) $X[$i][$j];
				}
			}

			for ($c = 0; $c < $k; $c++) {
				if ($cnt[$c] > 0) {
					for ($j = 0; $j < $d; $j++) {
						$newC[$c][$j] /= (float) $cnt[$c];
					}
				} else {
					$newC[$c] = $X[mt_rand(0, $n - 1)];
				}
			}

			$centroids = $newC;

			if ($changed === 0 && $iter > 1) {
				break;
			}
		}

		return [
			'k' => $k,
			'labels' => $labels,
			'centroids' => $centroids,
		];
	}

	public function dbscan(array $X, float $eps, int $minPts): array {
		$n = count($X);
		$labels = array_fill(0, $n, null);
		$cluster_id = 0;

		for ($i = 0; $i < $n; $i++) {
			if ($labels[$i] !== null) {
				continue;
			}

			$neighbors = $this->region_query($X, $i, $eps);
			if (count($neighbors) < $minPts) {
				$labels[$i] = -1;
				continue;
			}

			$labels[$i] = $cluster_id;
			$seed = $neighbors;
			$seen = [];

			foreach ($seed as $p) {
				$seen[$p] = 1;
			}

			for ($s = 0; $s < count($seed); $s++) {
				$p = $seed[$s];

				if ($labels[$p] === null) {
					$labels[$p] = $cluster_id;
					$nbs = $this->region_query($X, $p, $eps);

					if (count($nbs) >= $minPts) {
						foreach ($nbs as $q) {
							if (!isset($seen[$q])) {
								$seen[$q] = 1;
								$seed[] = $q;
							}
						}
					}
				} elseif ($labels[$p] === -1) {
					$labels[$p] = $cluster_id;
				}
			}

			$cluster_id++;
		}

		for ($i = 0; $i < $n; $i++) {
			if ($labels[$i] === null) {
				$labels[$i] = -1;
			}
		}

		return [
			'k' => $cluster_id,
			'labels' => $labels,
		];
	}

	public function compute_centroids(array $X, array $labels, bool $include_noise): array {
		$n = count($X);
		$d = count($X[0]);

		$centroids = [];
		$cnt = [];

		for ($i = 0; $i < $n; $i++) {
			$sid = (int) $labels[$i];

			if ($sid === -1 && !$include_noise) {
				continue;
			}

			if (!isset($centroids[$sid])) {
				$centroids[$sid] = array_fill(0, $d, 0.0);
				$cnt[$sid] = 0;
			}

			$cnt[$sid] += 1;

			for ($j = 0; $j < $d; $j++) {
				$centroids[$sid][$j] += (float) $X[$i][$j];
			}
		}

		foreach ($centroids as $sid => $vec) {
			$c = max(1, (int) ($cnt[$sid] ?? 1));
			for ($j = 0; $j < $d; $j++) {
				$centroids[$sid][$j] /= (float) $c;
			}
		}

		return $centroids;
	}

	private function sqdist(array $a, array $b): float {
		$sum = 0.0;
		$d = min(count($a), count($b));

		for ($i = 0; $i < $d; $i++) {
			$z = (float) $a[$i] - (float) $b[$i];
			$sum += $z * $z;
		}

		return $sum;
	}

	private function region_query(array $X, int $i, float $eps): array {
		$out = [];
		$eps2 = $eps * $eps;
		$xi = $X[$i];
		$n = count($X);

		for ($j = 0; $j < $n; $j++) {
			if ($this->sqdist($xi, $X[$j]) <= $eps2) {
				$out[] = $j;
			}
		}

		return $out;
	}
}