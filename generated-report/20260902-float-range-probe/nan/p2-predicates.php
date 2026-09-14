<?php declare(strict_types = 1);

namespace P2;

use function PHPStan\dumpType;

/**
 * @param float|null $fn
 * @param numeric-string $ns
 * @param int|float|null $ifn
 * @param positive-int $pi
 * @param float|bool $fb
 * @param 1.0|2.0|NAN $lit
 * @param int|float $if
 */
function predicates(?float $fn, string $ns, $ifn, int $pi, $fb, float $lit, $if, mixed $m, string $s, bool $b): void
{
	if (is_finite($fn)) { dumpType($fn); } else { dumpType($fn); }
	if (is_infinite($fn)) { dumpType($fn); } else { dumpType($fn); }
	if (is_nan($fn)) { dumpType($fn); } else { dumpType($fn); }

	if (is_finite($ns)) { dumpType($ns); } else { dumpType($ns); }
	if (is_nan($ns)) { dumpType($ns); } else { dumpType($ns); }

	if (is_finite($ifn)) { dumpType($ifn); } else { dumpType($ifn); }
	if (is_finite($pi)) { dumpType($pi); } else { dumpType($pi); }
	if (is_infinite($pi)) { dumpType($pi); } else { dumpType($pi); }
	if (is_finite($fb)) { dumpType($fb); } else { dumpType($fb); }

	if (is_nan($lit)) { dumpType($lit); } else { dumpType($lit); }
	if (is_finite($lit)) { dumpType($lit); } else { dumpType($lit); }
	if (is_infinite($lit)) { dumpType($lit); } else { dumpType($lit); }

	if (is_infinite($if)) { dumpType($if); } else { dumpType($if); }
	if (is_finite($m)) { dumpType($m); } else { dumpType($m); }
	if (is_infinite($m)) { dumpType($m); } else { dumpType($m); }
	if (is_nan($s)) { dumpType($s); } else { dumpType($s); }
	if (is_nan($b)) { dumpType($b); } else { dumpType($b); }

	// negations and combos
	if (!is_finite($if) && !is_nan($if)) { dumpType($if); }
	if (is_nan($if) || is_infinite($if)) { dumpType($if); } else { dumpType($if); }
	// assert
	assert(!is_nan($fn));
	dumpType($fn);
	// ternary/short-circuit
	$r = is_nan($if) ? 0.0 : $if;
	dumpType($r);
	// while loop with narrowing
	$acc = 0.0;
	while (!is_nan($acc)) {
		dumpType($acc);
		$acc += 0.1;
		dumpType($acc);
	}
	dumpType($acc);
	// foreach accumulation
	foreach ([1.0, 2.0] as $v) {
		if (is_nan($v)) { dumpType($v); }
	}
}
