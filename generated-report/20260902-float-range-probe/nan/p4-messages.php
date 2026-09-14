<?php declare(strict_types = 1);

namespace P4;

function takesInt(int $i): void {}
function takesString(string $s): void {}
/** @param 0.0|1.0 $z */
function takesZeroOne(float $z): void {}
/** @param positive-int $p */
function takesPositive(int $p): void {}
/** @return float */
function retFloat(float $f) { if (is_nan($f)) { return 0.0; } return $f; }
/** @return int */
function retInt(float $f) { if (is_nan($f)) { return 0; } return $f; }
/** @return float */
function retFinite(float $f) { if (is_finite($f)) { return $f; } return 0.0; }

/** @template T of float */
class Box { /** @param T $v */ public function __construct(public $v) {} /** @return T */ public function get() { return $this->v; } }

function messages(float $f, float $g, int|float $n): void
{
	if (!is_nan($f)) {
		takesInt($f);
		takesString($f);
		takesZeroOne($f);
		takesPositive($f);
		$b = new Box($f);
		\PHPStan\dumpType($b);
		\PHPStan\dumpType($b->get());
		echo $f;
		$arr = [$f => 1];
		$s = "x" . $f;
	}
	if (is_finite($g)) {
		takesInt($g);
		takesZeroOne($g);
	}
	if (is_infinite($g)) {
		takesInt($g);
	}
	if (is_nan($g)) {
		takesInt($g);
		takesString($g);
		echo $g;
	}
	if (is_finite($n)) {
		takesInt($n);
	}
	// impossible checks
	if (is_nan($f) && is_finite($f)) {
		echo 'impossible';
	}
	if (is_nan($f) && $f === NAN) {
		echo 'x';
	}
	if (!is_nan($f) && is_nan($f)) {
		echo 'x';
	}
	if (is_nan($f) && is_infinite($f)) {
		echo 'x';
	}
	if (is_finite($f) && is_infinite($f)) {
		echo 'x';
	}
	if (!is_nan($f)) {
		if (is_float($f)) { echo 'always'; }
		if (is_nan($f)) { echo 'never'; }
	}
	if ($f === INF) {
		if (is_infinite($f)) { echo 'always'; }
		if (is_finite($f)) { echo 'never'; }
	}
}
