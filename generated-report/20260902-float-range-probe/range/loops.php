<?php
namespace ProbeLoops;
use function PHPStan\dumpType;

function l1(): void {
	$f = 0.0;
	while (rand(0, 1)) { $f += 0.1; if ($f > 1.0) { break; } }
	dumpType($f);
}
function l2(): void {
	for ($f = 0.0; $f < 1.0; $f += 0.1) { dumpType($f); }
	dumpType($f);
}
function l3(float $f): void {
	$i = 0;
	while ($f > 0.0) { $f -= 0.5; dumpType($f); $i++; }
	dumpType($f);
}
function l4(float $f): void {
	if ($f !== 0.1 && $f !== 0.2 && $f !== 0.3 && $f !== 0.4 && $f !== 0.5 && $f !== 0.6 && $f !== 0.7 && $f !== 0.8 && $f !== 0.9 && $f !== 1.0
		&& $f !== 1.1 && $f !== 1.2 && $f !== 1.3 && $f !== 1.4 && $f !== 1.5 && $f !== 1.6 && $f !== 1.7 && $f !== 1.8 && $f !== 1.9 && $f !== 2.0) { dumpType($f); }
}
function l4i(int $i): void {
	if ($i !== 1 && $i !== 2 && $i !== 3 && $i !== 4 && $i !== 5 && $i !== 6 && $i !== 7 && $i !== 8 && $i !== 9 && $i !== 10
		&& $i !== 11 && $i !== 12 && $i !== 13 && $i !== 14 && $i !== 15 && $i !== 16 && $i !== 17 && $i !== 18 && $i !== 19 && $i !== 20) { dumpType($i); }
}
function l5(float $f): void {
	$x = $f;
	for ($i = 0; $i < 10; $i++) { if ($x > 0.5) { $x = 0.0; } else { $x += 0.1; } dumpType($x); }
	dumpType($x);
}
/** @param float<0.0, 1.0> $u */
function l6($u): void {
	$x = $u;
	while (rand(0, 1)) { $x = $x / 2; }
	dumpType($x);
	$y = $u;
	while (rand(0, 1)) { if ($y > 0.5) { $y = $u; } }
	dumpType($y);
}
function l7(): void {
	$t = 0.0;
	foreach ([0.1, 0.2, 0.3] as $v) { $t += $v; }
	dumpType($t);
	$s = 0.0;
	foreach ([1.5, 2.5] as $v) { if ($v > 2.0) { $s = $v; } }
	dumpType($s);
}
function l8(float $f): void {
	$x = 1.0;
	while (rand(0, 1)) { if ($x > 0.0) { $x = -$x; } else { $x = -$x; } }
	dumpType($x);
}
function l9(float $f): void {
	$acc = $f;
	while (rand(0, 1)) { if ($acc > 100.0) { $acc = 0.0; } $acc = $acc + 1.0; }
	dumpType($acc);
}
