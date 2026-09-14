<?php
namespace ProbeNegUnion;
use function PHPStan\dumpType;
/** @param float|int<5, 6> $x @param int<5, 6>|null $y */
function n($x, $y): void { dumpType(-$x); dumpType($x + 1); dumpType($x * 2); dumpType(-$y); dumpType($y + 1); if ($x !== 5) { dumpType(-$x); } }
