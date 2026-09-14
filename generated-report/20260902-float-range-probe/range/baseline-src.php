<?php
namespace ProbeBaseline;
function takesInt(int $i): void {}
function a(float $f): void { if ($f > 0) { takesInt($f); } }
function b(float $f): void { if ($f !== 0.0) { takesInt($f); } }
function c(float $f): void { if ($f) { takesInt($f); } }
/** @param array<string, bool|float|int|string> $map */
function d(array $map): void { takesInt(array_filter($map)); }
/** @return array<string> */
function e(mixed $m): array { if ($m > 1) { return $m; } return []; }
