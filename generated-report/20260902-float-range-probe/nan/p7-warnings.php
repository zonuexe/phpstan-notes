<?php declare(strict_types = 1);

namespace P7;

use function PHPStan\dumpType;

const CAST_BIG = (int) 1e19;
const CAST_NAN = (int) (0 * INF);
const MOD_NAN = 7 % (0 * INF);
const MOD_BIG = 7 % 1e19;
const KEY_NAN = [(0 * INF) => 1];
const NOT_NAN = ~(0 * INF);

function f(mixed $m, float $f, int $i): void
{
	// 2^63 boundary through comparison narrowing (commit 2 premise)
	if ($m >= 9223372036854775808.0) { dumpType($m); } else { dumpType($m); }
	if ($m < 9223372036854775808.0) { dumpType($m); }
	if ($m > 9223372036854775807.0) { dumpType($m); }
	if ($i <= 1e19) { dumpType($i); }
	if ($i > -9223372036854775808.0) { dumpType($i); }
	// NAN as comparison operand (getSmallerType etc. run (bool) NAN and (int) ceil(NAN))
	if ($m < NAN) { dumpType($m); } else { dumpType($m); }
	if ($m > NAN) { dumpType($m); }
	if ($m >= NAN) { dumpType($m); }
	if ($m <= NAN) { dumpType($m); }
	if ($f < NAN) { dumpType($f); } else { dumpType($f); }
	if ($i > NAN) { dumpType($i); }
	if ($m < INF) { dumpType($m); }
	if ($m > -INF) { dumpType($m); }
	// modulo / bitwise with NAN, INF, big float
	dumpType(7 % NAN);
	dumpType(7 % INF);
	dumpType(7 % 1e19);
	dumpType(7 % 2.5);
	dumpType(NAN % 2);
	dumpType(NAN & 1);
	dumpType(NAN | 1);
	dumpType(NAN ^ 1);
	dumpType(NAN << 1);
	dumpType(1 << NAN);
	dumpType(~NAN);
	dumpType(~INF);
	dumpType(~1e19);
	dumpType(intdiv(1, (int) NAN));
	// arrays keyed by NAN / INF / big
	dumpType([NAN => 1, 0 => 2]);
	dumpType([INF => 1, 0 => 2]);
	dumpType([1e19 => 1]);
	dumpType(array_flip([NAN]));
	dumpType(array_fill_keys([NAN], 1));
	dumpType(array_combine([NAN], [1]));
	dumpType(array_key_exists(NAN, [0 => 1]));
	dumpType(isset([0 => 1][NAN]));
	// filter_var
	dumpType(filter_var(NAN, FILTER_VALIDATE_INT));
	dumpType(filter_var(INF, FILTER_VALIDATE_INT));
	dumpType(filter_var(1e19, FILTER_VALIDATE_INT));
	dumpType(filter_var(1, FILTER_VALIDATE_INT, ['options' => ['min_range' => NAN]]));
	dumpType(filter_var(1, FILTER_VALIDATE_INT, ['options' => ['max_range' => 1e19]]));
	// exponent
	dumpType(NAN ** 2);
	dumpType(2 ** NAN);
	dumpType(INF ** 0);
	dumpType((-8.0) ** 0.5);
	dumpType(1e200 ** 2);
	// functions with NAN constant args
	dumpType(max(NAN, 1));
	dumpType(max(1, NAN));
	dumpType(max([1, NAN]));
	dumpType(min(1, NAN));
	dumpType(round(NAN));
	dumpType(abs(NAN));
	dumpType(sprintf('%d', NAN));
	dumpType(sprintf('%.2f', NAN));
	dumpType(number_format(NAN));
	dumpType(array_sum([NAN, 1]));
	dumpType(array_product([NAN]));
	dumpType(range(0, 1e19));
	dumpType(range(0.0, 1.0, INF));
	dumpType(str_repeat('x', (int) NAN));
	dumpType(array_search(NAN, [NAN], true));
	dumpType(array_unique([NAN, NAN]));
	dumpType(in_array(NAN, [NAN], true));
	dumpType(json_encode(NAN));
	dumpType(NAN <=> 1);
	dumpType(1 <=> NAN);
	dumpType(NAN <=> NAN);
	dumpType(PHP_FLOAT_MAX + PHP_FLOAT_MAX);
	dumpType(1e19 == '1e19');
	dumpType((string) 1e19);
	dumpType((int) '1e19');
	dumpType(CAST_BIG);
	dumpType(CAST_NAN);
	dumpType(MOD_NAN);
	dumpType(MOD_BIG);
	dumpType(KEY_NAN);
	dumpType(NOT_NAN);
	// bool coercion via truthiness contexts
	dumpType(NAN && true);
	dumpType(NAN || false);
	dumpType(NAN ? 1 : 2);
	dumpType((array) NAN);
	dumpType("$f" . NAN);
	dumpType(NAN . '');
	dumpType(match (true) { NAN => 1, default => 2 });
	// switch with NAN case on float
	switch ($f) { case NAN: dumpType($f); break; }
	// settype
	$x = NAN; settype($x, 'int'); dumpType($x);
	$y = NAN; settype($y, 'string'); dumpType($y);
	$z = NAN; settype($z, 'bool'); dumpType($z);
}
