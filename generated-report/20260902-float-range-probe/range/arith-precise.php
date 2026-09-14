<?php
namespace ProbeArithPrecise;
use function PHPStan\dumpType;

/**
 * @param float<0.0, 1.0, open-closed>|float<2.0, 3.0> $u
 * @param float<0.0, 1.0>|int<5, 6> $mr
 * @param float<0.0, 1.0> $unit
 * @param float<-1.0, 1.0>|int<-1, 1> $sym
 */
function d(float $f, $u, $mr, $unit, $sym): void {
	if ($f !== 0.0) {
		dumpType(1 / $f);
		dumpType($f + 1.0);
		dumpType($f * 2);
		dumpType(2 / $f);
		dumpType($f / 2);
		dumpType(-$f);
		dumpType($f - 1.0);
		dumpType($f + $f);
		dumpType($f * 0.0);
		dumpType($f * $unit);
		dumpType($f ** 2);
		dumpType(abs($f));
		dumpType($f + 0);
		dumpType(0 + $f);
		dumpType($f . '');
	}
	dumpType(1 / $u);
	dumpType($u + 1.0);
	dumpType($u * 2);
	dumpType($u - $u);
	dumpType($u + 1);
	dumpType($u * 0);
	dumpType($u / 2);
	dumpType($mr + 1);
	dumpType($mr * 2);
	dumpType(-$mr);
	dumpType($mr - 1);
	dumpType($mr / 2);
	dumpType(-$sym);
	dumpType($sym + 1);
	dumpType($sym * -1);
	dumpType(max(0.0, $f));
	dumpType(max($f, 0.0));
	dumpType(min(1.0, $f));
	dumpType(min($f, 1.0));
	dumpType(max(0.0, min(1.0, $f)));
	dumpType(min(1.0, max(0.0, $f)));
	dumpType(max($f, $unit));
	dumpType(max($unit, $f));
	dumpType(min($f, $unit));
	dumpType(max(0.0, $unit));
	dumpType(max([0.0, $f]));
	dumpType(max([$f, 0.0]));
}
function alwaysFalseBug(float $f): void {
	if ($f !== 0.0) {
		$g = $f + 1.0;
		if ($g === 0.0) { echo "reachable at runtime for \$f = -1.0"; }
		$h = 1 / $f;
		if ($h === 0.0) { echo "reachable at runtime for \$f = INF"; }
		$k = $f / 2;
		if ($k === 0.0) { echo "reachable at runtime for \$f = 5.0E-324"; }
	}
}
/** @param float<0.0, 1.0>|int<5, 6> $x */
function alwaysFalseBug2($x): void {
	$y = -$x;
	if ($y === -5) { echo "reachable at runtime for \$x = 5"; }
	$z = $x + 1;
	if ($z === 1.5) { echo "reachable at runtime for \$x = 0.5"; }
}
function clampIdiom(float $f): void {
	$c = max(0.0, min(1.0, $f));
	if (is_nan($c)) { echo "reachable at runtime for \$f = NAN"; }
	echo $c; // PHP 8.5 warns for NAN here
}
