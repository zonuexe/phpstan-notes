<?php declare(strict_types = 1);

namespace P5;

use function PHPStan\dumpType;

/**
 * @param float|int $fi
 * @param float|null $fn
 * @param float|string $fs
 */
function fold(float $f, $fi, $fn, $fs, mixed $m): void
{
	// does narrowing and re-merging give back plain `float`?
	if (is_nan($f)) {
		$a = $f;
	} else {
		$a = $f;
	}
	dumpType($a);

	$b = is_nan($f) ? $f : $f;
	dumpType($b);

	if (is_finite($f)) {
		$c = $f;
	} elseif (is_infinite($f)) {
		$c = $f;
	} else {
		$c = $f;
	}
	dumpType($c);

	if (is_infinite($f)) {
		$d = $f;
	} else {
		$d = $f;
	}
	dumpType($d);

	if ($f === INF) {
		$e = $f;
	} else {
		$e = $f;
	}
	dumpType($e);

	if ($f === INF || $f === -INF) {
		$g = $f;
	} else {
		$g = $f;
	}
	dumpType($g);

	// union member narrowing
	if (is_nan($fi)) { dumpType($fi); } else { dumpType($fi); }
	if (is_nan($fn)) { dumpType($fn); } else { dumpType($fn); }
	if (is_nan($fs)) { dumpType($fs); } else { dumpType($fs); }
	if (is_float($m) && !is_nan($m)) { dumpType($m); }
	if (!is_nan($m) && is_float($m)) { dumpType($m); }
	if (!is_nan($m)) { dumpType($m); if (is_float($m)) { dumpType($m); } if (is_scalar($m)) { dumpType($m); } }

	// nested predicates
	if (!is_nan($f)) {
		if (!is_infinite($f)) {
			dumpType($f);
			if ($f !== 0.0) {
				dumpType($f);
			}
		}
	}
	// arrays of narrowed floats
	$arr = [];
	if (!is_nan($f)) {
		$arr[] = $f;
	}
	$arr[] = 1.5;
	dumpType($arr);
	// closures capturing narrowed
	if (!is_nan($f)) {
		$fn2 = fn () => $f;
		dumpType($fn2());
		$fn3 = function () use ($f): float { return $f; };
		dumpType($fn3());
	}
	// is_nan then loop generalisation
	$acc = $f;
	for ($i = 0; $i < 10; $i++) {
		if (is_nan($acc)) {
			$acc = 0.0;
		}
		dumpType($acc);
		$acc = $acc * 1.5;
	}
	dumpType($acc);
}
