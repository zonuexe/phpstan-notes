<?php declare(strict_types = 1);

namespace P6;

use function PHPStan\dumpType;

/** @template T of float */
class Box { /** @param T $v */ public function __construct(public $v) {} }
/** @template T of int */
class IBox { /** @param T $v */ public function __construct(public $v) {} }
/** @param Box<float> $b */
function takesBox(Box $b): void {}
/** @param IBox<int> $b */
function takesIBox(IBox $b): void {}

function pre(float $f, int $i): void
{
	dumpType(max(NAN, 1));
	dumpType(max(1, NAN));
	dumpType(min(NAN, 1));
	dumpType(NAN == 'NAN');
	dumpType(NAN == 'abc');
	dumpType(INF == 'INF');
	dumpType(NAN == true);
	dumpType(NAN == null);
	dumpType(NAN == []);
	$a = (int) (0 * INF);
	$b = (string) (0 * INF);
	$c = (bool) (0 * INF);
	$d = [(0 * INF) => 1];
	$e = ~(0 * INF);
	$g = (int) 1e19;
	$h = (int) INF;
	dumpType($a);
	dumpType($b);
	dumpType($c);
	dumpType($d);
	dumpType($e);
	dumpType($g);
	dumpType($h);
	if ($i > 0) {
		$ib = new IBox($i);
		dumpType($ib);
		takesIBox($ib);
	}
	$ib2 = new IBox(1);
	dumpType($ib2);
	if (!is_nan($f)) {
		$fb = new Box($f);
		dumpType($fb);
		takesBox($fb);
	}
	$fb2 = new Box(1.5);
	dumpType($fb2);
	takesBox($fb2);
	if ($f > 0.0) {
		dumpType($f);
	} else {
		dumpType($f);
	}
}
