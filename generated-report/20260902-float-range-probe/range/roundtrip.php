<?php
namespace ProbeRoundTrip;

/** @param float<0.0, 1.0, closed-open> $x */
function r1($x): int { return $x; }

/** @return float<0.0, 1.0> */
function r2(float $f) { return $f; }

function r3(float $f): void { if ($f > 0.0) { r4($f); } else { r4($f); } }
function r4(int $i): void {}

/** @param float<min, max, open-open> $x */
function r5($x): string { return $x; }

function r6(float $f): void { if ($f !== 0.0) { r4($f); } }

/** @param float<0.0, 1.0> $u */
function r7($u): void { r8($u); }
/** @param float<0.0, 1.0, open-closed> $p */
function r8($p): void {}

function r9(): float { return NAN; }
/** @return float<min, max> */
function r10(float $f): float { return $f; }
