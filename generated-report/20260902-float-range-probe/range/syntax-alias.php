<?php
namespace ProbeAlias;
use function PHPStan\dumpType;

/**
 * @phpstan-type Unit float<0.0, 1.0>
 * @phpstan-type HalfOpen float<0.0, 1.0, closed-open>
 * @phpstan-type NonNan float<min, max>
 * @phpstan-type Reversed float<1.0, 0.0>
 * @phpstan-type Empty float<0.0, 5.0E-324, open-open>
 * @phpstan-type UnitList list<Unit>
 */
class Aliases {
	/**
	 * @param Unit $u
	 * @param HalfOpen $h
	 * @param NonNan $n
	 * @param Reversed $r
	 * @param Empty $e
	 * @param UnitList $l
	 */
	function f($u, $h, $n, $r, $e, $l): void { dumpType($u); dumpType($h); dumpType($n); dumpType($r); dumpType($e); dumpType($l); }
}

/**
 * @phpstan-type NegInf float<-inf, 1.0>
 */
class BadAlias {
	/** @param NegInf $x */
	function f($x): void { dumpType($x); }
}

/**
 * @phpstan-import-type Unit from Aliases
 */
class Importer {
	/** @param Unit $u */
	function g($u): void { dumpType($u); }
}
