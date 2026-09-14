<?php declare(strict_types=1);
namespace ProbeArith;
use function PHPStan\dumpType;

/**
 * @param float<0.0, 1.0> $x
 * @param int<0, 10> $i
 * @param float<0.0, 1.0, open-closed> $pos
 * @param list<float<0.0, 1.0>> $xs
 */
function ops($x, $i, $pos, array $xs): void
{
	dumpType($x * 2);
	dumpType($x + 1);
	dumpType($x - 1);
	dumpType($x / 2);
	dumpType(1 / $pos);
	dumpType(1 / $x);
	dumpType($x ** 2);
	dumpType(-$x);
	dumpType(abs($x));
	dumpType(ceil($x));
	dumpType(floor($x));
	dumpType(round($x));
	dumpType(round($x, 2));
	dumpType(min($x, 0.5));
	dumpType(max($x, 0.5));
	dumpType(min($x, $x));
	dumpType((int) $x);
	dumpType((string) $x);
	dumpType((bool) $x);
	dumpType(array_sum($xs));
	dumpType(sqrt($x));
	dumpType(fdiv($x, 2));
	dumpType(intdiv((int) $x, 1));
	dumpType($x % 1);
	dumpType($x <=> 0.5);
	dumpType($x . '');
	dumpType(number_format($x));
	dumpType(range(0.0, $x));
	dumpType($x == 0.5);
	dumpType($x * $x);
	dumpType($x + $x);
	dumpType($x * 0);
	dumpType($x * -1);
	dumpType(1 - $x);
	dumpType(2.0 * $pos);

	// same on int<0, 10>
	dumpType($i * 2);
	dumpType($i + 1);
	dumpType(-$i);
	dumpType(abs($i));
	dumpType(ceil($i));
	dumpType(ceil($i / 4));
	dumpType(round($i));
	dumpType(min($i, 5));
	dumpType((float) $i);
	dumpType($i / 4);
	dumpType(array_sum([$i, $i]));
	dumpType(intdiv($i, 2));
}

/** @param array<string> $list */
function issue6963(array $list): void
{
	$a = count($list);
	dumpType($a);
	$b = $a / 4;
	dumpType($b);
	$c = ceil($b);
	dumpType($c);
	$columns = array_chunk($list, (int) $c);
}

function guardThenUse(float $f): void
{
	if ($f > 0.0) {
		dumpType($f);
		dumpType(1 / $f);
		dumpType(log($f));
		dumpType(sqrt($f));
		dumpType($f * 2);
		dumpType(intdiv(1, (int) $f));
		$arr = [];
		$arr[$f] = 1;
		dumpType($arr);
	}
	if ($f >= 0.0 && $f <= 1.0) {
		dumpType($f);
		dumpType($f * 100);
		dumpType((int) ($f * 100));
		dumpType(round($f * 100));
	}
}
