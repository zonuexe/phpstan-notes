<?php declare(strict_types = 1);

namespace P1;

use function PHPStan\dumpType;

function identity(float $f, mixed $m, int $i): void
{
	// === / !== against NAN
	if ($f === NAN) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	if ($f == NAN) {
		dumpType($f);
	}
	// self-comparison (canonical NaN test)
	if ($f !== $f) {
		dumpType($f);
	} else {
		dumpType($f);
	}
	// after is_nan, compare NAN === NAN
	if (is_nan($f)) {
		dumpType($f === NAN);
		dumpType($f == NAN);
		dumpType($f === $f);
		dumpType($f <=> $f);
		dumpType($f < 1.0);
		dumpType($f > 1.0);
		dumpType($f >= $f);
		dumpType(in_array($f, [NAN], true));
		dumpType(in_array(NAN, [$f], true));
		dumpType(match (true) { $f === NAN => 'eq', default => 'ne' });
		dumpType(max($f, 1));
		dumpType(max(1, $f));
		dumpType(min($f, 1));
		dumpType(abs($f));
		dumpType(-$f);
		dumpType($f * 0);
		dumpType($f + 1);
		dumpType((int) $f);
		dumpType((string) $f);
		dumpType((bool) $f);
		dumpType((array) $f);
		dumpType([$f => 1]);
		dumpType(round($f));
		dumpType(intdiv(1, 1) + $f);
		dumpType($f ?: 'falsy');
		dumpType(!$f);
		dumpType($f == 'NAN');
		dumpType(json_encode($f));
		dumpType($f == 0.0);
		dumpType($f === 0.0);
	}
	// NAN constant literal
	dumpType(NAN);
	dumpType(-NAN);
	dumpType(NAN === NAN);
	dumpType(NAN == NAN);
	dumpType(NAN <=> NAN);
	dumpType(NAN < 1);
	dumpType([NAN] === [NAN]);
	dumpType(in_array(NAN, [NAN], true));
	dumpType(in_array(NAN, [NAN]));
	dumpType(array_search(NAN, [NAN], true));
	dumpType(0.0 * INF);
	dumpType(INF - INF);
	dumpType(sqrt(-1.0));
	dumpType(fdiv(0.0, 0.0));
	dumpType(NAN ?: 'x');
	switch ($f) {
		case NAN:
			dumpType($f);
			break;
		case 1.0:
			dumpType($f);
			break;
	}
	dumpType(match ($f) { NAN => 'nan', default => 'other' });
	// is_nan mixed → then is_float
	if (!is_nan($m)) {
		dumpType($m);
		if (is_float($m)) {
			dumpType($m);
		}
		if (is_int($m)) {
			dumpType($m);
		}
	}
	// int via is_nan
	if (is_nan($i)) {
		dumpType($i);
	}
}
