<?php
namespace ProbeConstUnion;
use function PHPStan\dumpType;
/**
 * @param 0.5|int<1, 2> $x
 */
function n($x, float $f): void {
	dumpType($x + 1);
	dumpType($x * 2);
	dumpType($x - 1);
	dumpType(-$x);
	if ($f !== 0.5) { dumpType($f + 1); }
	if ($f === 0.5 || $f === 1.5) { dumpType($f + 1); dumpType($f * 2); }
	/** @var 0.5|1.5 $g */
	$g = $f;
	dumpType($g + 1);
	/** @var 0.5|int $h */
	$h = $f;
	dumpType($h + 1);
}
