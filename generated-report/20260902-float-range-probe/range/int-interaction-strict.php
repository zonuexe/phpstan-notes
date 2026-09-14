<?php declare(strict_types=1);
namespace ProbeIntStrict;

function takesInt(int $i): void {}
/** @param float<0.0, 1.0> $u */ function takesUnit($u): void {}
/** @param float<0.0, 1.0> $u @param int<0, 1> $i01 @param int<0, 2> $i02 */
function ii($u, $i01, $i02, int $i, float $f): void {
	takesInt($u);
	takesUnit($i01); takesUnit($i02); takesUnit($i); takesUnit(1); takesUnit(2); takesUnit(0.5); takesUnit("0.5"); takesUnit($f);
}
