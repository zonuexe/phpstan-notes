<?php
namespace ProbeNegZero;
use function PHPStan\dumpType;

/** @param float<0.0, 1.0> $u */ function takesUnit($u): void {}
/** @param float<0.0, 1.0, open-closed> $u */ function takesPositive($u): void {}
/** @param float<-1.0, 0.0, closed-open> $u */ function takesNegative($u): void {}

function z(float $f): void {
	$m = -0.0; dumpType($m); dumpType($m === 0.0); dumpType((string) $m); dumpType(-0.0 <=> 0.0); dumpType(0.0 === -0.0);
	dumpType(-0.0 == 0.0); dumpType(-0.0 < 0.0); dumpType(1 / -0.0);
	if ($f === -0.0) { dumpType($f); dumpType((string) $f); }
	if ($f !== 0.0) {
		dumpType($f);
		if ($f === -0.0) { dumpType($f); }
		dumpType(1 / $f);
	}
	if ($f == 0) { dumpType($f); }
	if ($f < 0.0) { dumpType($f); if ($f === -0.0) { dumpType($f); } }
	takesUnit(-0.0);
	takesPositive(-0.0);
	takesNegative(-0.0);
	takesUnit($m);
	takesPositive(0.0);
	takesPositive(5.0E-324);
	takesPositive(PHP_FLOAT_MIN);
	takesUnit(1.0000000000000002);
	takesUnit(0.9999999999999999);
	takesUnit(INF);
	takesUnit(NAN);
	takesUnit(1);
	takesUnit(2);
	takesUnit(true);
	takesUnit("0.5");
	takesUnit("5");
	takesUnit(null);
}
function nextUp(): void {
	/** @var float<0.0, 1.0, open-open> $oo */
	$oo = 0.5;
	dumpType($oo);
	if ($oo === 5.0E-324) { dumpType($oo); }
	if ($oo === 0.9999999999999999) { dumpType($oo); }
	if ($oo === 0.0) { dumpType($oo); }
	if ($oo === 1.0) { dumpType($oo); }
	/** @var float<5.0E-324, 0.9999999999999999> $canon */
	$canon = 0.5;
	dumpType($canon);
	/** @var float<1.7976931348623157E+308, inf, closed-open> $finiteTop */
	$finiteTop = 0.5;
	dumpType($finiteTop);
	/** @var float<min, max, open-open> $finite */
	$finite = 0.5;
	if ($finite === PHP_FLOAT_MAX) { dumpType($finite); }
	if ($finite === INF) { dumpType($finite); }
	if ($finite > PHP_FLOAT_MAX) { dumpType($finite); }
	if ($finite >= PHP_FLOAT_MAX) { dumpType($finite); }
}
