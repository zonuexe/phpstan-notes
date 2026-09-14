<?php
namespace ProbeAttrib;
use function PHPStan\dumpType;
function a(float $f): void {
	if (is_finite($f)) { dumpType($f); dumpType($f * 2); dumpType($f + $f); dumpType($f + 1); dumpType($f + 1.0); dumpType(-$f); dumpType(2 / $f); }
	if (!is_nan($f)) { dumpType($f); dumpType($f * 2); dumpType($f + $f); dumpType($f + 1); dumpType(1 / $f); dumpType(max(0.0, $f)); dumpType(max($f, 0.0)); }
	dumpType(max(0.0, $f)); dumpType(max($f, 0.0)); dumpType(min(1.0, $f)); dumpType(max(0.0, min(1.0, $f)));
	if ($f > 0.0) { dumpType($f); dumpType($f * 2); dumpType($f + 1); dumpType(2 / $f); }
	if ($f !== 0.0) { dumpType($f); dumpType($f * 2); dumpType($f + 1); dumpType(2 / $f); }
}
