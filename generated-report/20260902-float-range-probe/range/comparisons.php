<?php
namespace ProbeCompare;
use function PHPStan\dumpType;

/**
 * @param int|float $n
 * @param numeric-string $ns
 * @param float<0.0, 1.0> $u
 * @param float<min, max> $nonNan
 * @param float<2.0, 3.0> $two
 */
function c(float $f, int $i, $n, $ns, ?float $nf, bool $b, mixed $m, $u, string $s, $nonNan, $two): void {
	if ($f > $i) { dumpType($f); dumpType($i); } else { dumpType($f); dumpType($i); }
	if ($f < $n) { dumpType($f); dumpType($n); } else { dumpType($f); dumpType($n); }
	if ($f > $ns) { dumpType($f); dumpType($ns); } else { dumpType($f); dumpType($ns); }
	if ($f > $nf) { dumpType($f); dumpType($nf); } else { dumpType($f); dumpType($nf); }
	if ($f > $b) { dumpType($f); dumpType($b); } else { dumpType($f); dumpType($b); }
	if ($f > $m) { dumpType($f); dumpType($m); } else { dumpType($f); dumpType($m); }
	if ($f > $s) { dumpType($f); dumpType($s); } else { dumpType($f); dumpType($s); }
	if ($f == $i) { dumpType($f); dumpType($i); } else { dumpType($f); dumpType($i); }
	if ($f === $i) { dumpType($f); } else { dumpType($f); }
	if ($f != 1) { dumpType($f); } else { dumpType($f); }
	if ($f == 1) { dumpType($f); } else { dumpType($f); }
	if ($f == "1") { dumpType($f); } else { dumpType($f); }
	if ($f == "abc") { dumpType($f); } else { dumpType($f); }
	if ($f == "") { dumpType($f); } else { dumpType($f); }
	if ($f == true) { dumpType($f); } else { dumpType($f); }
	if ($f == null) { dumpType($f); } else { dumpType($f); }
	if ($f) { dumpType($f); } else { dumpType($f); }
	if (!$f) { dumpType($f); } else { dumpType($f); }
	$c = $f <=> 1.0; dumpType($c);
	if (($f <=> 1.0) === 1) { dumpType($f); } else { dumpType($f); }
	if (($f <=> 1.0) === 0) { dumpType($f); } else { dumpType($f); }
	dumpType($u <=> 0.5); dumpType($u <=> 2.0); dumpType($u <=> $two); dumpType($u <=> $f); dumpType($nonNan <=> $nonNan);
	if ($u > $f) { dumpType($u); dumpType($f); } else { dumpType($u); dumpType($f); }
	if ($f > $u) { dumpType($u); dumpType($f); } else { dumpType($u); dumpType($f); }
	if ($nonNan > $f) { dumpType($nonNan); dumpType($f); } else { dumpType($nonNan); dumpType($f); }
	if ($nonNan > $nonNan) { dumpType($nonNan); } else { dumpType($nonNan); }
	if ($u < 0.5) { dumpType($u); } else { dumpType($u); }
	if ($u == 0.5) { dumpType($u); } else { dumpType($u); }
	if ($u === 2.0) { dumpType($u); } else { dumpType($u); }
	if ($u == 2) { dumpType($u); } else { dumpType($u); }
	if ($u != 1) { dumpType($u); } else { dumpType($u); }
	if ($u < $two) { dumpType($u); } else { dumpType($u); }
	if ($u > $two) { dumpType($u); } else { dumpType($u); }
	if ($u == $two) { dumpType($u); } else { dumpType($u); }
	if ($u === $two) { dumpType($u); } else { dumpType($u); }
	if ($f > 1 && $f < 0) { dumpType($f); }
	if ($f > 0 || $f <= 0) { dumpType($f); } else { dumpType($f); }
	if (!($f > 0) && !($f <= 0)) { dumpType($f); }
	if ($f > $f) { dumpType($f); } else { dumpType($f); }
	if ($f != $f) { dumpType($f); } else { dumpType($f); }
	if ($f !== $f) { dumpType($f); } else { dumpType($f); }
	if ($f === $f) { dumpType($f); } else { dumpType($f); }
	if ($f >= 0 && $f < 1) { dumpType($f); } else { dumpType($f); }
	if ($n > 0) { dumpType($n); } else { dumpType($n); }
	if ($nf > 0) { dumpType($nf); } else { dumpType($nf); }
	if ($nf >= 0) { dumpType($nf); } else { dumpType($nf); }
	if ($m > $f) { dumpType($m); dumpType($f); } else { dumpType($m); dumpType($f); }
	if ($m >= 0.0) { dumpType($m); } else { dumpType($m); }
	if ($m == 0.5) { dumpType($m); } else { dumpType($m); }
	if ($m === 0.5) { dumpType($m); } else { dumpType($m); }
	if ($f > 0.0) { } else { if (is_nan($f)) { dumpType($f); } else { dumpType($f); } }
	if ($f > 0.0) { } elseif ($f <= 0.0) { dumpType($f); } else { dumpType($f); }
	if ($f > INF) { dumpType($f); } else { dumpType($f); }
	if ($f >= INF) { dumpType($f); } else { dumpType($f); }
	if ($f < -INF) { dumpType($f); } else { dumpType($f); }
	if ($f > -INF) { dumpType($f); } else { dumpType($f); }
	if ($f == INF) { dumpType($f); } else { dumpType($f); }
	if ($f == NAN) { dumpType($f); } else { dumpType($f); }
	if ($f === NAN) { dumpType($f); } else { dumpType($f); }
	if ($f != NAN) { dumpType($f); } else { dumpType($f); }
	if ($f > 9007199254740993) { dumpType($f); } else { dumpType($f); }
	if ($i > 9007199254740992.0) { dumpType($i); } else { dumpType($i); }
	if ($f > PHP_INT_MAX) { dumpType($f); } else { dumpType($f); }
	if ($f > 1e400) { dumpType($f); } else { dumpType($f); }
	$g = $f; if ($g > 0.5 && $g > 0.7 && $g < 0.9 && $g !== 0.8) { dumpType($g); }
}

function matchExhaustive(float $f): int {
	return match (true) {
		$f > 0.5 => 1,
		$f <= 0.5 => 2,
	};
}
/** @param float<0.0, 1.0> $u */
function matchExhaustiveRange($u): int {
	return match (true) {
		$u > 0.5 => 1,
		$u <= 0.5 => 2,
	};
}
function alwaysTrue(float $f): void {
	if ($f > 0.0) { if ($f > 0.0) { } }
	if ($f > 0.0) { if ($f <= 0.0) { } }
	if ($f > 0.0 && $f > 0.0) { }
	if ($f > 0.0 && $f <= 0.0) { }
	if ($f !== $f) { }
	if ($f === $f) { }
	if ($f == $f) { }
	/** @var float<0.0, 1.0> $u */
	$u = $f;
	if ($u > 1.0) { }
	if ($u >= 0.0) { }
	if ($u === 2.0) { }
	if ($u > 0.5 || $u <= 0.5) { }
	if (is_nan($u)) { }
	if (is_nan($f)) { }
	if ($u !== $u) { }
}
