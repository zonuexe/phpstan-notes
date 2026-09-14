<?php declare(strict_types=1);
namespace ProbeMessages;

function takesInt(int $i): void {}
/** @param array<int> $a */
function takesIntArray(array $a): void {}
function takesString(string $s): void {}
/** @param float<0.0, 1.0> $u */
function takesUnit($u): void {}
/** @param float<0.0, 1.0, closed-open> $u */
function takesHalfOpen($u): void {}
/** @param float<min, max> $u */
function takesNonNan($u): void {}
/** @param float<0.0, inf, open-closed> $u */
function takesPositive($u): void {}

/** @param float<0.0, 1.0> $u @param array<mixed> $m */
function messages(float $f, $u, array $m, mixed $x): void
{
	if ($f > 0.0) {
		takesInt($f);
	} else {
		takesInt($f);
	}
	if ($f !== 0.0) {
		takesInt($f);
	}
	takesIntArray(array_filter($m));
	takesInt($u);
	takesUnit($f);
	takesUnit(2.0);
	takesHalfOpen($u);
	takesUnit(-$u);
	takesNonNan($f);
	takesNonNan($u);
	takesPositive($u);
	takesPositive(0.0);
	if ($u > 0.0) { takesPositive($u); }
	if ($u !== 0.0) { takesPositive($u); }
	if ($x > 0.5) { takesInt($x); }
	takesString($u);
}

/** @return float<0.0, 1.0> */
function tooWide(float $f): float
{
	if ($f > 2.0) { return $f; }
	return 0.5;
}

/** @return float<0.0, 1.0> */
function retNarrowed(float $f): float
{
	if ($f >= 0.0 && $f <= 1.0) { return $f; }
	return 0.0;
}

/** @return float */
function retNonNan(float $f): float
{
	if (is_nan($f)) { throw new \RuntimeException(); }
	return $f;
}
