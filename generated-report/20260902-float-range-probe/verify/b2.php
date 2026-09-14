<?php
function neg(float $f): void {
	if ($f !== INF) {
		\PHPStan\dumpType($f);
		\PHPStan\dumpType(-$f);
	}
	if ($f > 1.0) {
		\PHPStan\dumpType($f);
		\PHPStan\dumpType(-$f);
	}
}
