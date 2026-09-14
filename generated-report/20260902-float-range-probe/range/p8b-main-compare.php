<?php declare(strict_types=1);
namespace ProbeMain;

function takesInt(int $i): void {}
/** @param int<0, 1> $i */
function takesBit($i): void {}
/** @param float<0.0, 1.0> $u */
function takesUnit($u): void {}

function f(float $f, array $arr, int $i): void
{
	takesInt(1.0);
	takesInt(1.5);
	takesBit(2);
	takesBit($i);
	takesUnit(2);
	takesUnit(1.0000000000000002);
	$arr[$f] = 1;
	if ($f === NAN) { \PHPStan\dumpType($f); } else { \PHPStan\dumpType($f); }
	if ($f == NAN) { \PHPStan\dumpType($f); } else { \PHPStan\dumpType($f); }
	\PHPStan\dumpType($f * 0);
	\PHPStan\dumpType((float) $i);
}
