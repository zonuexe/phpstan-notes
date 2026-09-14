<?php declare(strict_types=1);
namespace ProbeEco;

/**
 * @param float<0, 1> $a
 * @param float<0.0, 1.0, closed-open> $b
 * @param float<min, max> $c
 */
function f($a, $b, $c): void {}

/** @return float<0.0, 1.0> */
function g(): float { return 0.5; }

f(0.5, 0.5, 0.5);
f(g(), 1.0, 2.0);
