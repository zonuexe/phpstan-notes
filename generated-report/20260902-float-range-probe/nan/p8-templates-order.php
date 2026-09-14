<?php declare(strict_types = 1);

namespace P8;

use function PHPStan\dumpType;

/**
 * @template T of float
 * @param T $t
 * @return T
 */
function retT(float $t, float $u): float
{
	if (!is_nan($u)) {
		return $u; // float<-inf, inf> is NOT T: must be reported (base reports plain float here)
	}
	return $t;
}

/**
 * @template T of float
 * @param T $t
 * @return T
 */
function retTFinite(float $t, float $u): float
{
	if (is_finite($u)) {
		return $u;
	}
	return $t;
}

/**
 * @template T of float
 * @param T $t
 * @return T
 */
function retTPlain(float $t, float $u): float
{
	return $u;
}

/**
 * @template T of int
 * @param T $t
 * @return T
 */
function retTInt(int $t, int $u): int
{
	if ($u > 0) {
		return $u; // int<1, max> is not T: reported
	}
	return $t;
}

/**
 * @template T of float
 * @param T $a
 * @param T $b
 * @return T
 */
function same(float $a, float $b): float { return $a; }

function callSame(float $x, float $y): void
{
	if (!is_nan($x)) {
		dumpType(same($x, $y));
		dumpType(same($x, 1.5));
	}
	dumpType(same($x, $y));
}

function orderDependent(float $f): void
{
	if ($f !== 0.0 && !is_nan($f)) {
		dumpType($f);
	}
	if (!is_nan($f) && $f !== 0.0) {
		dumpType($f);
	}
	if ($f !== 0.0 && is_finite($f)) {
		dumpType($f);
	}
	if (is_finite($f) && $f !== 0.0) {
		dumpType($f);
	}
	if ($f !== 1.0) {
		if (!is_nan($f)) {
			dumpType($f);
		}
	}
	if (!is_nan($f)) {
		if ($f !== 1.0) {
			dumpType($f);
		}
	}
}

function unaryMinus(float $f): void
{
	if (!is_nan($f) && $f !== INF) {
		dumpType($f);
		dumpType(-$f);
		dumpType(+$f);
		dumpType($f * -1);
		dumpType(abs($f));
		dumpType($f ** 1);
		dumpType(0 - $f);
	}
	if (is_finite($f) && $f !== 0.5) {
		dumpType($f);
		dumpType(-$f);
		dumpType(abs($f));
		dumpType($f ** 1);
	}
	if (is_finite($f) && $f !== -0.5) {
		dumpType($f);
		dumpType(-$f);
	}
}

/** @template T of float */
class Box
{
	/** @param T $v */
	public function __construct(public $v) {}
}

class Holder
{
	/** @var Box<float> */
	public Box $box;
	/** @var list<float> */
	public array $list = [];
	/** @var array<string, float> */
	public array $map = [];
	/** @var \Closure(): float */
	public \Closure $cl;

	public function set(float $f): void
	{
		if (!is_nan($f)) {
			$this->box = new Box($f);
			$this->list[] = $f;
			$this->map['a'] = $f;
			$this->cl = fn (): float => $f;
			$this->cl = fn () => $f;
			dumpType($this->cl);
		}
	}
}

/** @param Box<float> $b */
function takesBoxFloat(Box $b): void {}
/** @param array<float> $a */
function takesArrayFloat(array $a): void {}
/** @param callable(): float $c */
function takesCallableFloat(callable $c): void {}
/** @param iterable<float> $i */
function takesIterableFloat(iterable $i): void {}

function invariance(float $f): void
{
	if (!is_nan($f)) {
		takesBoxFloat(new Box($f));
		takesArrayFloat([$f]);
		takesCallableFloat(fn () => $f);
		takesIterableFloat([$f]);
		$g = new \SplObjectStorage();
		/** @var \ArrayObject<int, float> $ao */
		$ao = new \ArrayObject([$f]);
		$ao2 = new \ArrayObject([$f]);
		dumpType($ao2);
		takesArrayObjectFloat($ao2);
	}
}
/** @param \ArrayObject<int, float> $ao */
function takesArrayObjectFloat(\ArrayObject $ao): void {}
