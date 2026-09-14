<?php
namespace ProbeMsg;
use function PHPStan\dumpType;
/** @param float<0.0, 1.0> $u */ function takesUnit($u): void {}
function takesFloat(float $f): void {}
/** @param int<0, 1> $i */ function takesInt01($i): void {}
/** @return float<0.0, 1.0> */
function clamp2(float $f): float {
	if ($f < 0.0) { return 0.0; }
	if ($f > 1.0) { return 1.0; }
	dumpType($f);
	return $f;
}
/** @return float<0.0, 1.0> */
function clamp3(float $f): float {
	if (is_nan($f)) { return 0.0; }
	if ($f < 0.0) { return 0.0; }
	if ($f > 1.0) { return 1.0; }
	dumpType($f);
	return $f;
}
/** @return float<0.0, 1.0> */
function clamp4(float $f): float {
	if ($f >= 0.0 && $f <= 1.0) { return $f; }
	return 0.0;
}
function calls(): void {
	takesUnit(NAN); takesUnit(2.0); takesUnit(2); takesUnit("0.5"); takesUnit(true); takesUnit(null);
	takesFloat("0.5"); takesFloat("abc"); takesFloat(true); takesFloat(null); takesFloat(NAN);
	takesInt01(2); takesInt01(1.0); takesInt01("1"); takesInt01(true);
}
