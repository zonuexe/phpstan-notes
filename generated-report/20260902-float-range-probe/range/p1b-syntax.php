<?php declare(strict_types=1);
namespace ProbeSyntaxB;
use function PHPStan\dumpType;

/**
 * @param float<inf, inf> $a
 * @param float<max, max> $b
 * @param float<0.0, min> $c
 * @param float<min, min> $d
 * @param float<0.0, \INF> $e
 * @param float<\PHP_FLOAT_MAX, 1e308> $f
 * @param float<\PHP_FLOAT_EPSILON, 1.0> $g
 * @param float<0.0, \M_PI> $h
 * @param float<5.0E-324, 1.0> $i
 * @param float<-1.7976931348623157E+308, 0.0> $j
 * @param int<5, 1> $k
 * @param float<1e3, 1E-3> $l
 * @param float<1E-3, 1e3> $m
 * @param float<0, 1, closed> $n
 * @param float<0, 1, open> $o
 * @param float<0.0, 1.0, closed-open> $h1
 */
function params($a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $h1): void
{
	dumpType($a);
	dumpType($b);
	dumpType($c);
	dumpType($d);
	dumpType($e);
	dumpType($f);
	dumpType($g);
	dumpType($h);
	dumpType($i);
	dumpType($j);
	dumpType($k);
	dumpType($l);
	dumpType($m);
	dumpType($n);
	dumpType($o);
	if ($h1 == 1.0) {
		dumpType($h1);
	}
	if ($h1 === 1.0) {
		dumpType($h1);
	}
	if ($h1 >= 1.0) {
		dumpType($h1);
	}
	$r = match (true) {
		$h1 > 0.5 => 'big',
		default => 'small',
	};
	dumpType($r);
}
/**
 * @param float<0.0, inf, open-closed> $positive
 * @param float<0.0, 1.0, open-open> $openUnit
 */
function subnormalLeak(mixed $m, float $f, $positive, $openUnit): void
{
	if ($m > $positive) {
		dumpType($m);
	} else {
		dumpType($m);
	}
	if ($m >= $positive) {
		dumpType($m);
	} else {
		dumpType($m);
	}
	if ($f < $openUnit) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($m < $openUnit) {
		dumpType($m);
	} else {
		dumpType($m);
	}
	if ($f >= $openUnit) {
		dumpType($f);
	}
	$x = $f > 0.0 ? $f : 0.0;
	dumpType($x);
	assert($f > 0.0);
	dumpType($f);
}
