<?php
namespace ProbeAttrib2;
use function PHPStan\dumpType;
function a(float $f): void {
	if (!is_infinite($f)) { dumpType($f); dumpType($f * 2); dumpType($f + 1); dumpType(2 / $f); dumpType(-$f); }
	if (is_infinite($f)) { dumpType($f); dumpType($f * 2); dumpType($f + 1); dumpType(-$f); dumpType($f * 0); }
}
