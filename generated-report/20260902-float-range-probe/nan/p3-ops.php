<?php declare(strict_types = 1);

namespace P3;

use function PHPStan\dumpType;

function ops(float $f, int $i): void
{
	if (is_nan($f)) {
		return;
	}
	dumpType($f);
	dumpType(-$f);
	dumpType(+$f);
	dumpType($f + 1);
	dumpType($f + $f);
	dumpType($f - 1.0);
	dumpType($f * 2);
	dumpType($f / 2);
	dumpType($f % 2);
	dumpType($f ** 2);
	dumpType(2 ** $f);
	dumpType($f ** 0.5);
	dumpType($f <=> 1.0);
	dumpType($f . '');
	dumpType("$f");
	dumpType((int) $f);
	dumpType((string) $f);
	dumpType((bool) $f);
	dumpType((array) $f);
	dumpType(abs($f));
	dumpType(round($f));
	dumpType(floor($f));
	dumpType(sqrt($f));
	dumpType(max($f, 1.0));
	dumpType(min($f, $i));
	dumpType(intdiv($i, 2) + $f);
	dumpType(array_sum([$f, 1]));
	dumpType($f == 0);
	dumpType($f === 0.0);
	dumpType($f > 0);
	dumpType($f < INF);
	dumpType($f <= INF);
	dumpType($f >= -INF);
	dumpType($f > -INF);
	dumpType(~$f);
	dumpType($f & 1);
	dumpType($f | 1);
	dumpType($f << 1);
	dumpType(!$f);
	dumpType($f ?: 'z');
	dumpType($f ?? 'z');
	dumpType([$f => 1]);
	$x = $f;
	$x++;
	dumpType($x);
	$y = $f;
	$y--;
	dumpType($y);
	$z = $f;
	$z += 1;
	dumpType($z);
	dumpType(sprintf('%d', $f));
	dumpType(number_format($f));
	dumpType(is_float($f));
	dumpType(is_int($f));
	dumpType(is_numeric($f));
	dumpType(gettype($f));
	dumpType(var_export($f, true));
	dumpType(json_encode($f));
	dumpType(settype($f, 'int'));
	dumpType(range(0, $f));
	dumpType(array_fill(0, 3, $f));
	dumpType(array_key_exists($f, []));
	dumpType(str_repeat('x', $f));
}

function finite(float $f): void
{
	if (!is_finite($f)) {
		return;
	}
	dumpType($f);
	dumpType(-$f);
	dumpType($f * 2);
	dumpType((int) $f);
	dumpType(abs($f));
	dumpType($f + INF);
	dumpType($f > 10.0);
	if ($f > 10.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f >= 0.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f === 1.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f != 0.0) {
		dumpType($f);
	}
	if ($f) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f == 0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
}

function nonNanCompare(float $f): void
{
	if (is_nan($f)) {
		return;
	}
	if ($f > 0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f > 10.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f < 0.5) {
		dumpType($f);
	}
	if ($f >= 1) {
		dumpType($f);
	}
	if ($f) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f === INF) {
		dumpType($f);
	} else {
		dumpType($f);
	}
}
