<?php declare(strict_types=1);
namespace ProbeSyntax;
use function PHPStan\dumpType;

/**
 * @param float<min, max> $a
 * @param float<-inf, 1.0> $b
 * @param float<1.0, inf> $c
 * @param float<0.0, max> $c2
 * @param float<min, inf> $c3
 * @param float<inf, inf> $c4
 * @param float<, 1.0> $d
 * @param float<1, 2> $e
 * @param float<1e3, 1E-3> $f
 * @param float<-1.5, 2> $g
 * @param float<0x10, 1> $h
 * @param float<0.1, 0.1> $i
 * @param float<1.0, 0.0> $j
 * @param float<NAN, 1> $k
 * @param float<\NAN, 1> $k2
 * @param float<0, 1, closed> $l
 * @param float<0, 1, ClosedOpen> $l2
 * @param float<0, 1, \Random\IntervalBoundary::ClosedOpen> $l3
 * @param float<0.0, 1.0, 'closed-open'> $l4
 * @param float<0.0> $m
 * @param float<0.0, 1.0, closed-open, x> $n
 * @param float<\PHP_FLOAT_MAX, \INF> $o
 * @param float<-\INF, 0.0> $o2
 * @param float<\PHP_FLOAT_EPSILON, 1.0> $o3
 * @param float<-0.0, 0.0> $p
 * @param float<-0.0, 1.0> $p2
 * @param float<0.0, 1.0, open-open> $q
 * @param float<0.0, 5e-324, open-open> $q2
 * @param float<1_000.5, 2_000.5> $r
 * @param float<.5, 1.> $s
 * @param float<0.0, 1.0>|float<1.0, 2.0, open-closed> $t
 * @param float<0.0, 1.0, closed-open>|float<1.0, 2.0, open-closed> $t2
 * @param float<0.0, 1.0>|int<0, 1> $u
 * @param float<0.0, 1.0>|NAN $v
 * @param float<min, max>|NAN $w
 * @param positive-float $x
 * @param float<0.0, 1.0, closed-open>|1.0 $y
 */
function params($a, $b, $c, $c2, $c3, $c4, $d, $e, $f, $g, $h, $i, $j, $k, $k2, $l, $l2, $l3, $l4, $m, $n, $o, $o2, $o3, $p, $p2, $q, $q2, $r, $s, $t, $t2, $u, $v, $w, $x, $y): void
{
	dumpType($a); dumpType($b); dumpType($c); dumpType($c2); dumpType($c3); dumpType($c4); dumpType($d); dumpType($e); dumpType($f); dumpType($g); dumpType($h); dumpType($i); dumpType($j); dumpType($k); dumpType($k2);
	dumpType($l); dumpType($l2); dumpType($l3); dumpType($l4); dumpType($m); dumpType($n); dumpType($o); dumpType($o2); dumpType($o3); dumpType($p); dumpType($p2); dumpType($q); dumpType($q2); dumpType($r); dumpType($s);
	dumpType($t); dumpType($t2); dumpType($u); dumpType($v); dumpType($w); dumpType($x); dumpType($y);
}

/**
 * @phpstan-type Unit float<0.0, 1.0>
 * @phpstan-type HalfOpen float<0.0, 1.0, closed-open>
 * @phpstan-type Bad float<1.0, 0.0>
 * @phpstan-type NonNan float<min, max>
 */
class Aliases
{
	/**
	 * @param Unit $u
	 * @param HalfOpen $h
	 * @param Bad $b
	 * @param NonNan $n
	 */
	public function m($u, $h, $b, $n): void
	{
		dumpType($u); dumpType($h); dumpType($b); dumpType($n);
	}

	/** @return float<0.0, 1.0> */
	public function ret(float $f): float
	{
		return $f;
	}

	/** @var float<0.0, 1.0> */
	public float $prop = 0.5;

	/** @var float<0.0, 1.0> */
	public float $propBad = 2.0;
}
