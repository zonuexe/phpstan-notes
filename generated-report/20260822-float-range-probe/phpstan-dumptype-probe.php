<?php declare(strict_types = 1);

namespace FloatProbe;

use function PHPStan\dumpType;

/** @param float<0.0, 1.0> $r */
function phpdocRange(float $f, int $i, $r, mixed $m): void
{
	dumpType($r);
	dumpType(NAN);
	dumpType(INF);
	dumpType(-INF);
	dumpType(PHP_FLOAT_MAX);
	dumpType(PHP_FLOAT_MIN);
	dumpType(PHP_FLOAT_EPSILON);
	dumpType(-0.0);
	dumpType(0.0);
	dumpType(1e308 * 10);
	dumpType(PHP_FLOAT_MAX + PHP_FLOAT_MAX);
	dumpType(INF - INF);
	dumpType(PHP_INT_MAX + 1);
	dumpType(1 / 3);
	dumpType(sqrt(-1.0));
	dumpType(fdiv(1, 0));
	dumpType(abs($f));
	dumpType(-$f);
	dumpType($f * $f);
	dumpType($f ** 2);
	dumpType(round($f));
	dumpType(floor($f));
	dumpType((int) $f);
	dumpType((string) $f);
	dumpType((bool) $f);
	dumpType($f === NAN);
	dumpType(NAN === NAN);
	dumpType(NAN == NAN);
	dumpType(NAN < 1.0);
	dumpType(INF > PHP_FLOAT_MAX);
	dumpType(-0.0 === 0.0);
	dumpType(0.1 + 0.2 == 0.3);
	dumpType(min($f, 1.0));
	dumpType(max($f, 0.0));
	dumpType($i / 2);
	dumpType($i * 1.5);
	dumpType(random_int(1, 10) / 2.0);
	dumpType(range(0.0, 1.0, 0.25));
	dumpType(array_sum([1.5, 2.5]));

	if (is_nan($f)) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if (is_finite($f)) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if (is_infinite($f)) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f > 0.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f >= 0.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f < 0.5) {
		dumpType($f);
	}
	if ($f === 0.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f == 0) {
		dumpType($f);
	}
	if ($f !== NAN) {
		dumpType($f);
	}
	if ($f === NAN) {
		dumpType($f);
	}
	if ($f === INF) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f < INF) {
		dumpType($f);
	}
	if ($f !== $f) {
		dumpType($f);
	}
	if ($f == $f) {
		dumpType($f);
	}
	if ($m > 0.5) {
		dumpType($m);
	}
	if ($m < 0.5) {
		dumpType($m);
	}
	if ($i > 0.5) {
		dumpType($i);
	}
	if ($i < 0.5) {
		dumpType($i);
	}
	if ($f) {
		dumpType($f);
	} else {
		dumpType($f);
	}
}
