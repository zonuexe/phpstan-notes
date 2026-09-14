<?php
// PHP の float/NaN/INF 意味論プローブ。`php php-float-semantics-probe.php` で実行（PHP 8.5 推奨）。
// 各式の結果と、発生した Warning/Deprecated を 1 行にまとめて出力する。
ini_set('precision', '-1');
$tests = [
	'NAN == NAN' => fn() => NAN == NAN,
	'NAN === NAN' => fn() => NAN === NAN,
	'NAN < 1.0' => fn() => NAN < 1.0,
	'NAN <=> 1.0' => fn() => NAN <=> 1.0,
	'NAN == true' => fn() => NAN == true,
	'min(NAN, 1.0)' => fn() => min(NAN, 1.0),
	'min(1.0, NAN)' => fn() => min(1.0, NAN),
	'INF > PHP_FLOAT_MAX' => fn() => INF > PHP_FLOAT_MAX,
	'PHP_FLOAT_MIN' => fn() => PHP_FLOAT_MIN,
	'PHP_FLOAT_MIN / 2 > 0' => fn() => PHP_FLOAT_MIN / 2 > 0,
	'-0.0 === 0.0' => fn() => -0.0 === 0.0,
	'-0.0 <=> 0.0' => fn() => -0.0 <=> 0.0,
	'(string) -0.0' => fn() => (string) -0.0,
	'PHP_FLOAT_MAX + PHP_FLOAT_MAX' => fn() => PHP_FLOAT_MAX + PHP_FLOAT_MAX,
	'INF - INF' => fn() => INF - INF,
	'0.0 * INF' => fn() => 0.0 * INF,
	'atan(INF)' => fn() => atan(INF),
	'sin(INF)' => fn() => sin(INF),
	'log(0.0)' => fn() => log(0.0),
	'9007199254740993 == 9007199254740992.0' => fn() => 9007199254740993 == 9007199254740992.0,
	'(float) PHP_INT_MAX' => fn() => (float) PHP_INT_MAX,
	'(string) NAN' => fn() => (string) NAN,
	'NAN . ""' => fn() => NAN . '',
	'(string) INF' => fn() => (string) INF,
	'(bool) NAN' => fn() => (bool) NAN,
	'NAN && true' => fn() => NAN && true,
	'(array) NAN' => fn() => (array) NAN,
	'(int) NAN' => fn() => (int) NAN,
	'(int) INF' => fn() => (int) INF,
	'(int) 1e19' => fn() => (int) 1e19,
	'$a[NAN] = 1' => function () { $a = []; $a[NAN] = 1; return $a; },
	'1.0 / 0.0' => function () { try { return 1.0 / 0.0; } catch (\Throwable $e) { return get_class($e); } },
	'fdiv(1, 0)' => fn() => fdiv(1, 0),
	'json_encode(NAN)' => fn() => json_encode(NAN),
	'Randomizer::getFloat(1.0, 1.0, OpenOpen)' => function () { try { return (new Random\Randomizer())->getFloat(1.0, 1.0, Random\IntervalBoundary::OpenOpen); } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } },
	'Randomizer::getFloat(0.0, INF)' => function () { try { return (new Random\Randomizer())->getFloat(0.0, INF); } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } },
	'filter_var("1.5", FLOAT, 0..1)' => fn() => filter_var('1.5', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]]),
	'filter_var("1.0", FLOAT, 0..1)' => fn() => filter_var('1.0', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 1.0]]),
	'filter_var("NAN", FLOAT)' => fn() => filter_var('NAN', FILTER_VALIDATE_FLOAT),
];
foreach ($tests as $name => $fn) {
	$diag = [];
	set_error_handler(static function (int $no, string $str) use (&$diag): bool { $diag[] = "[$no] $str"; return true; });
	try { $r = $fn(); } catch (\Throwable $e) { $r = get_class($e) . ': ' . $e->getMessage(); }
	restore_error_handler();
	printf("%-44s => %s%s\n", $name, str_replace("\n", ' ', var_export($r, true)), $diag !== [] ? '    !! ' . implode(' | ', $diag) : '');
}
