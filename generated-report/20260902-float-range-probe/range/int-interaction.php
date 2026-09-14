<?php
namespace ProbeInt;
use function PHPStan\dumpType;

function takesInt(int $i): void {}
/** @param float<0.0, 1.0> $u */ function takesUnit($u): void {}
/** @param int<0, 1> $i */ function takesInt01($i): void {}
/** @param float<0.0, 1.0>|int<0, 1> $x */ function takesEither($x): void {}

/**
 * @param float<0.0, 1.0> $u
 * @param float<1.0, 1.0> $one
 * @param int<0, 1> $i01
 * @param int<0, 2> $i02
 * @param int|float<0.0, 1.0> $x
 * @param float<2.0, 3.0> $two
 * @param array<string, mixed> $arr
 */
function ii($u, $one, $i01, $i02, int $i, float $f, $x, $two, array $arr): void {
	takesInt($u); takesInt($one); takesInt($two); takesInt($f);
	takesUnit($i01); takesUnit($i02); takesUnit($i); takesUnit(1); takesUnit(2); takesUnit(0.5); takesUnit("0.5"); takesUnit("5"); takesUnit(true); takesUnit($f); takesUnit($x);
	takesInt01($u); takesInt01($one);
	takesEither($u); takesEither($i01); takesEither($f);
	$arr[$u] = 1; dumpType($arr);
	$k = [$u => 1]; dumpType($k);
	$k2 = [$two => 1]; dumpType($k2);
	$k3 = [$one => 1]; dumpType($k3);
	dumpType($one);
	if (is_int($u)) { dumpType($u); } else { dumpType($u); }
	if (is_float($i)) { dumpType($i); }
	if (is_float($x)) { dumpType($x); } else { dumpType($x); }
	if (is_numeric($u)) { dumpType($u); }
	if ($x > 0.5) { dumpType($x); } else { dumpType($x); }
	if ($x === 0.5) { dumpType($x); }
	if ($x === 1) { dumpType($x); }
	dumpType(in_array($u, [0.0, 0.5], true));
	dumpType($u + $i01); dumpType($u . ''); dumpType([$u, $i01]);
	dumpType(array_key_exists($u, $arr));
	dumpType($u == $i01); dumpType($one == 1); dumpType($one === 1); dumpType($two == $i01); dumpType($two == $i);
	dumpType(intdiv($u, 1)); dumpType($u % 2); dumpType($u | 1); dumpType(~$u); dumpType($u << 1);
	dumpType(str_repeat('a', $u)); dumpType(chr($u)); dumpType(range($u, 5));
	dumpType(array_fill($u, 3, 'x'));
	dumpType(sprintf('%d', $u)); dumpType(number_format($u)); dumpType(round($u, 1));
	dumpType(json_encode($u)); dumpType(strval($u)); dumpType(intval($u)); dumpType(floatval($u)); dumpType(boolval($u)); dumpType(settype($u, 'int'));
}
