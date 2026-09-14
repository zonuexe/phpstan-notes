<?php
namespace ProbeArith;
use function PHPStan\dumpType;

/**
 * @param float<0.0, 1.0, open-closed>|float<2.0, 3.0> $u
 * @param float<0.0, 1.0> $unit
 * @param float<0.0, 1.0>|int<5, 6> $mixedRange
 */
function d(float $f, $u, $unit, $mixedRange): void {
	if ($f !== 0.0) {
		dumpType($f);
		dumpType(1 / $f); dumpType($f + 1.0); dumpType($f * 2); dumpType(2 / $f); dumpType($f / 2); dumpType(-$f); dumpType($f - 1.0); dumpType($f ** 2); dumpType($f % 3); dumpType(abs($f));
	}
	if ($f > 0.0) {
		dumpType(1 / $f); dumpType($f + 1.0); dumpType($f - 1.0); dumpType($f * $f); dumpType(-$f);
	}
	dumpType($u); dumpType(1 / $u); dumpType($u + 1.0); dumpType($u * 2); dumpType(-$u); dumpType(abs($u)); dumpType($u - $u); dumpType((int) $u); dumpType((string) $u); dumpType((bool) $u);
	dumpType($unit + 1.0); dumpType($unit * 2); dumpType($unit / 2); dumpType(1 / $unit);
	dumpType($mixedRange); dumpType(-$mixedRange); dumpType($mixedRange + 1); dumpType($mixedRange * 2); dumpType(abs($mixedRange)); dumpType((int) $mixedRange);
	dumpType(max(0.0, $f)); dumpType(max($f, 0.0)); dumpType(min(1.0, $f)); dumpType(max(0.0, $unit)); dumpType(min($f, $unit)); dumpType(max(0.0, min(1.0, $f)));
	dumpType(abs($f)); dumpType(sqrt($unit)); dumpType(intdiv(1, 1) / $unit);
	dumpType(PHP_INT_MAX); dumpType(PHP_INT_SIZE); dumpType(PHP_FLOAT_MAX); dumpType(PHP_FLOAT_MIN);
	dumpType(filter_var($f, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]]));
	dumpType(filter_var("x", FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]]));
	dumpType(filter_var($f, FILTER_VALIDATE_FLOAT));
	dumpType(filter_var($f, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0]]));
	dumpType(filter_var($f, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 0]]));
	dumpType(is_finite($unit)); dumpType(is_infinite($unit)); dumpType(is_nan($unit));
	if (is_finite($f)) { dumpType($f); } else { dumpType($f); }
}
function nanEq(float $f): void {
	if ($f == NAN) { dumpType($f); } else { dumpType([$f]); }
	if ($f != NAN) { dumpType($f); } else { dumpType([$f]); }
	if ($f === NAN) { dumpType($f); } else { dumpType([$f]); }
	if ($f !== NAN) { dumpType($f); } else { dumpType([$f]); }
	if (NAN == $f) { dumpType($f); } else { dumpType([$f]); }
	if ($f == true) { dumpType($f); } else { dumpType([$f]); }
	if ($f == "") { dumpType($f); } else { dumpType([$f]); }
	if ($f == 1) { dumpType($f); } else { dumpType([$f]); }
	if ($f != 1) { dumpType($f); } else { dumpType([$f]); }
	if ($f == "1") { dumpType($f); } else { dumpType([$f]); }
	if ($f === 1.0 || $f === NAN) { dumpType($f); } else { dumpType([$f]); }
	$x = NAN; if ($x == $x) { dumpType($x); } else { dumpType([$x]); }
	if (in_array($f, [NAN], true)) { dumpType($f); }
	if (in_array($f, [0.5, 1.5], true)) { dumpType($f); } else { dumpType([$f]); }
	if (in_array($f, [0.5, 1.5], false)) { dumpType($f); } else { dumpType([$f]); }
}
/** @param int<0, 1> $i01 @param float<0.0, 1.0> $u */
function intRangeParity($i01, $u, float $f, int $i): void {
	dumpType($i01 <=> 2); dumpType($u <=> 2.0); dumpType($i01 == 2); dumpType($u == 2.0); dumpType($i01 == 2.0); dumpType($u == 2);
	if ($i01 == 2) { dumpType($i01); } if ($u == 2.0) { dumpType($u); }
	if ($i > $i) { dumpType($i); }
	dumpType(match (true) { $i01 > 0 => 'a', $i01 <= 0 => 'b' });
	dumpType(match (true) { $u > 0.5 => 'a', $u <= 0.5 => 'b' });
	dumpType(match (true) { $f > 0.5 => 'a', $f <= 0.5 => 'b', default => 'c' });
}
