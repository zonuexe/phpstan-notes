<?php declare(strict_types=1);
namespace ProbeZeroLoopInt;
use function PHPStan\dumpType;

/** @param float<0.0, 1.0> $u */
function negZero(float $f, $u): void
{
	dumpType(-0.0);
	dumpType(-0.0 === 0.0);
	takesUnit(-0.0);
	takesUnit(0);
	takesUnit(1);
	takesUnit(2);
	takesUnit(1.0000000000000002);
	if ($f !== 0.0) {
		dumpType($f);
		if ($f === -0.0) { dumpType($f); }
		dumpType((string) $f);
	}
	if ($f >= 0.0) {
		dumpType($f);
		dumpType((string) $f);
		dumpType((bool) $f);
	}
	if ($f > 0.0) {
		dumpType((string) $f);
		dumpType((bool) $f);
	}
	$z = -0.0;
	dumpType($z);
	if ($u === -0.0) { dumpType($u); } else { dumpType($u); }
	if ($u !== 0.0) { dumpType($u); dumpType(1 / $u); }
}

/** @param float<0.0, 1.0> $u */
function takesUnit($u): void {}

function loops(bool $c): void
{
	$f = 0.0;
	while ($c) {
		$f += 0.1;
		dumpType($f);
		if ($f > 1.0) {
			break;
		}
		dumpType($f);
	}
	dumpType($f);

	for ($g = 0.0; $g < 1.0; $g += 0.25) {
		dumpType($g);
	}
	dumpType($g);

	$h = 0.5;
	for ($n = 0; $n < 100; $n++) {
		if ($h > 0.9) { $h = 0.1; } else { $h = $h * 1.5; }
		dumpType($h);
	}
	dumpType($h);
}

function manyPoints(float $f): void
{
	if ($f !== 0.1 && $f !== 0.2 && $f !== 0.3 && $f !== 0.4 && $f !== 0.5 && $f !== 0.6 && $f !== 0.7 && $f !== 0.8) {
		dumpType($f);
	}
	if (!in_array($f, [0.1, 0.2, 0.3], true)) {
		dumpType($f);
	}
}

/** @param float<0.0, 1.0> $u @param float<1.0, 1.0> $one @param float<min, max> $nonNan @param float<0.0, inf> $nonNeg */
function intInteraction(float $f, int $i, int|float $n, $u, $one, $nonNan, $nonNeg, array $arr): void
{
	takesInt($u);
	takesInt($one);
	takesFloat($u);
	takesUnit($f);
	takesUnit($i);
	takesNonNan($f);
	takesNonNan($u);
	takesNonNan(1.5);
	takesNonNan($i);
	takesNonNeg($f);
	takesFloat($nonNan);
	$arr[$u] = 1;
	dumpType($arr);
	$k = [$u => 1];
	dumpType($k);
	if ($n > 0) { dumpType($n); takesInt($n); }
	if ($n === 1.0) { dumpType($n); }
	if (is_float($n) && $n > 0) { dumpType($n); }
	if (is_int($n) || $n > 0.5) { dumpType($n); }
	dumpType($u + $i);
	dumpType(max($i, $u));
	/** @var int<0, 1> $ir */
	$ir = 0;
	takesUnit($ir);
	/** @var int<0, 2> $ir2 */
	$ir2 = 0;
	takesUnit($ir2);
}
function takesInt(int $i): void {}
function takesFloat(float $f): void {}
/** @param float<min, max> $f */
function takesNonNan($f): void {}
/** @param float<0.0, inf> $f */
function takesNonNeg($f): void {}
