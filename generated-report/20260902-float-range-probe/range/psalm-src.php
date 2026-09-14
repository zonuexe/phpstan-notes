<?php
/** @param float<0, 1> $x */
function e1($x): void { echo $x; }
/** @param float<0.0, 1.0, closed-open> $x */
function e2($x): void { echo $x; }
/** @return float<0.0, 1.0> */
function e3(): float { return 0.5; }
/** @param float<min, max> $x */
function e4($x): void { echo $x; }
/** @param int<0, max> $i */
function e5($i): void { echo $i; }
e1(2.0);
e2(2.0);
e4(1.0);
e5(-1);
