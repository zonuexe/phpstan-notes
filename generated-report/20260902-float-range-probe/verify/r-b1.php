<?php
function div(float $f): void {
	if ($f !== 0.0) {
		\PHPStan\dumpType($f);
		\PHPStan\dumpType(1 / $f);
		if (1 / $f === 0.0) { echo "reachable at runtime when \$f is INF"; }
	}
}
function mm(float $f): void {
	$c = max(0.0, min(1.0, $f));
	\PHPStan\dumpType($c);
	if (is_nan($c)) { echo "reachable at runtime when \$f is NAN"; }
}
