<?php declare(strict_types=1);
namespace ProbeCompare;
use function PHPStan\dumpType;

/** @param numeric-string $ns */
function operands(float $f, int $i, int|float $n, string $ns, ?float $nf, bool $b, mixed $m, float $g): void
{
	// float vs int constant
	if ($f > 1) { dumpType($f); } else { dumpType($f); }
	// float vs float variable (may be NaN)
	if ($f < $g) { dumpType($f); dumpType($g); } else { dumpType($f); dumpType($g); }
	// float vs int variable
	if ($f < $i) { dumpType($f); dumpType($i); } else { dumpType($f); dumpType($i); }
	// int|float vs constant
	if ($n > 0) { dumpType($n); } else { dumpType($n); }
	if ($n >= 0.5) { dumpType($n); } else { dumpType($n); }
	// numeric-string vs float constant
	if ($ns > 0.5) { dumpType($ns); } else { dumpType($ns); }
	// nullable float
	if ($nf > 0.0) { dumpType($nf); } else { dumpType($nf); }
	if ($nf < 0.0) { dumpType($nf); } else { dumpType($nf); }
	// bool vs float constant
	if ($b > 0.5) { dumpType($b); } else { dumpType($b); }
	if ($b < 0.5) { dumpType($b); } else { dumpType($b); }
	// mixed vs float constant
	if ($m > 0.5) { dumpType($m); } else { dumpType($m); }
	if ($m < 0.0) { dumpType($m); } else { dumpType($m); }
	// equality
	if ($f == 0.5) { dumpType($f); } else { dumpType($f); }
	if ($f != 0.5) { dumpType($f); } else { dumpType($f); }
	if ($f == 0) { dumpType($f); } else { dumpType($f); }
	if ($f == null) { dumpType($f); } else { dumpType($f); }
	if ($f == false) { dumpType($f); } else { dumpType($f); }
	if ($f == true) { dumpType($f); } else { dumpType($f); }
	if ($f == '0') { dumpType($f); } else { dumpType($f); }
	if ($f == 'abc') { dumpType($f); } else { dumpType($f); }
	if ($f === 0.5) { dumpType($f); } else { dumpType($f); }
	if ($f !== 0.5) { dumpType($f); } else { dumpType($f); }
	if (!$f) { dumpType($f); } else { dumpType($f); }
	if ($f) { dumpType($f); } else { dumpType($f); }
	dumpType($f <=> 0.5);
	if (($f <=> 0.5) === 1) { dumpType($f); }
	// float vs NaN-capable expression on the right
	if ($f < $g + 1.0) { dumpType($f); } else { dumpType($f); }
	// reversed operand order with constant on the left
	if (0.5 < $f) { dumpType($f); } else { dumpType($f); }
	if (0.5 >= $f) { dumpType($f); } else { dumpType($f); }
	// float against INF/-INF constants
	if ($f > INF) { dumpType($f); } else { dumpType($f); }
	if ($f >= INF) { dumpType($f); } else { dumpType($f); }
	if ($f < -INF) { dumpType($f); } else { dumpType($f); }
	if ($f <= -INF) { dumpType($f); } else { dumpType($f); }
	if ($f === -INF) { dumpType($f); } else { dumpType($f); }
	// NaN on the right, else side
	if ($f < NAN) { dumpType($f); } else { dumpType($f); }
	if ($f == NAN) { dumpType($f); } else { dumpType($f); }
	if ($f === NAN) { dumpType($f); } else { dumpType($f); }
	if ($f !== NAN) { dumpType($f); } else { dumpType($f); }
	// chained
	if ($f > 0.0 && $f < 1.0) { dumpType($f); } else { dumpType($f); }
	if ($f < 0.0 || $f > 1.0) { dumpType($f); } else { dumpType($f); }
	// non-NaN operand on the right after is_nan guard
	if (!is_nan($g)) {
		if ($f < $g) { dumpType($f); } else { dumpType($f); }
		if ($g > 0.0) { dumpType($g); } else { dumpType($g); }
	}
}

/** @param float<0.0, 1.0> $u @param float<0.0, 1.0, closed-open> $h */
function rangeOnRight(float $f, int $i, $u, $h, mixed $m): void
{
	if ($f > $u) { dumpType($f); } else { dumpType($f); }
	if ($f >= $u) { dumpType($f); } else { dumpType($f); }
	if ($f < $u) { dumpType($f); } else { dumpType($f); }
	if ($f <= $u) { dumpType($f); } else { dumpType($f); }
	if ($i > $u) { dumpType($i); } else { dumpType($i); }
	if ($i < $h) { dumpType($i); } else { dumpType($i); }
	if ($i <= $h) { dumpType($i); } else { dumpType($i); }
	if ($m > $u) { dumpType($m); } else { dumpType($m); }
	if ($u > $h) { dumpType($u); dumpType($h); } else { dumpType($u); dumpType($h); }
	if ($u == $h) { dumpType($u); } else { dumpType($u); }
	if ($u === $h) { dumpType($u); } else { dumpType($u); }
	if ($u > 0.5) { dumpType($u); } else { dumpType($u); }
	if ($u > 2.0) { dumpType($u); } else { dumpType($u); }
	if ($u >= 1.0) { dumpType($u); } else { dumpType($u); }
	if ($u > 1.0) { dumpType($u); } else { dumpType($u); }
	if ($h >= 1.0) { dumpType($h); } else { dumpType($h); }
	if ($h < 1.0) { dumpType($h); } else { dumpType($h); }
	if ($u > true) { dumpType($u); } else { dumpType($u); }
	if ($u > null) { dumpType($u); } else { dumpType($u); }
	if ($u) { dumpType($u); } else { dumpType($u); }
	if ($h) { dumpType($h); } else { dumpType($h); }
}
