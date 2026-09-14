<?php
namespace ProbeMotivation;
use function PHPStan\dumpType;

/**
 * @param float<0.0, 1.0> $x
 * @param int<0, 10> $i
 * @param list<float<0.0, 1.0>> $list
 * @param float<0.0, 1.0, open-closed> $pos
 */
function m($x, $i, $list, $pos): void {
	dumpType($x * 2); dumpType($i * 2);
	dumpType($x + 1); dumpType($i + 1);
	dumpType($x + $i);
	dumpType(-$x); dumpType(-$i);
	dumpType(abs($x)); dumpType(abs($i));
	dumpType(ceil($x)); dumpType(ceil($i));
	dumpType(floor($x)); dumpType(floor($i));
	dumpType(round($x)); dumpType(round($x, 2)); dumpType(round($i));
	dumpType(min($x, 0.5)); dumpType(max($x, 0.5)); dumpType(min($i, 5)); dumpType(max($i, 5));
	dumpType(min($x, $i)); dumpType(max($list));
	dumpType((int) $x); dumpType((int) $i);
	dumpType(array_sum($list)); dumpType(array_product($list));
	dumpType($x / 2); dumpType($i / 2); dumpType(1 / $pos); dumpType(1 / $x);
	dumpType($x ** 2); dumpType($i ** 2); dumpType($x ** 0.5);
	dumpType(sqrt($x)); dumpType(sin($x)); dumpType(exp($x)); dumpType(log($pos));
	dumpType($x * $x); dumpType($x - $x); dumpType(1.0 - $x);
	dumpType($x % 1); dumpType(fmod($x, 1.0)); dumpType(intdiv($i, 2));
	dumpType((string) $x); dumpType((bool) $x); dumpType((float) $x); dumpType((float) $i); dumpType((bool) $pos);
	dumpType($x <=> 0.5);
	dumpType($x == 0.5); dumpType($x === 0.5); dumpType($x > 2.0); dumpType($x < 2.0); dumpType($x >= 0.0); dumpType($x === 2.0);
	$y = $x; $y++; dumpType($y);
	$z = $x; $z += 0.5; dumpType($z);
	$w = $i; $w += 5; dumpType($w);
	dumpType(mt_rand() / mt_getrandmax());
	dumpType(lcg_value());
	dumpType((new \Random\Randomizer())->getFloat(0.0, 1.0));
	dumpType((new \Random\Randomizer())->getFloat(0.0, 1.0, \Random\IntervalBoundary::ClosedOpen));
	dumpType((new \Random\Randomizer())->nextFloat());
	dumpType(microtime(true));
	dumpType(filter_var($x, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]]));
	dumpType(filter_var("1", FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]));
	dumpType(pi()); dumpType(M_PI); dumpType(PHP_FLOAT_MAX); dumpType(PHP_FLOAT_MIN); dumpType(PHP_FLOAT_EPSILON); dumpType(INF); dumpType(-INF); dumpType(NAN);
	dumpType(range(0.0, 1.0, 0.25));
	dumpType(array_map(fn ($v) => $v * 2, $list));
	dumpType(hrtime(true));
}

/** @return float<0.0, 1.0> */
function unit(): float { return 0.5; }

/** @return float<0.0, 0.5> */
function half(): float { return unit() / 2; }

/** @return float<0.0, 1.0> */
function clamp(float $f): float { return max(0.0, min(1.0, $f)); }

/** @return float<0.0, 1.0> */
function clamp2(float $f): float {
	if ($f < 0.0) { return 0.0; }
	if ($f > 1.0) { return 1.0; }
	return $f;
}

/** @return float<0.0, 1.0> */
function fromInt(): float { return random_int(0, 100) / 100; }

/** @return int<0, 10> */
function intHalf(): int { return intdiv(random_int(0, 20), 2); }

function ceilIssue(): void {
	/** @var int<1, 10> $i */
	$i = random_int(1, 10);
	dumpType(ceil($i / 2));
	dumpType(ceil($i));
	dumpType((int) ceil($i / 2));
}
