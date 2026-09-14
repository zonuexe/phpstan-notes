<?php
// (moved below autoload)
require '/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/review-float-range-type/vendor/autoload.php';
require __DIR__ . '/FloatRangeTypeInstrumented.php';
use PHPStan\Type\FloatType; use PHPStan\Type\FloatRangeType; use PHPStan\Type\TypeCombinator; use PHPStan\Type\Constant\ConstantFloatType; use PHPStan\Type\Constant\ConstantIntegerType; use PHPStan\Type\IntegerType;
foreach ([50, 100, 200, 300] as $n) {
	FloatRangeType::$nextUpCalls = 0;
	$t = new FloatType(); $s = microtime(true);
	for ($k = 1; $k <= $n; $k++) { $t = TypeCombinator::remove($t, new ConstantFloatType($k / 1000)); }
	$removeTime = microtime(true) - $s; $removeCalls = FloatRangeType::$nextUpCalls;
	// what the narrowing does per guard on top of remove(): intersect the current type with `mixed~c` and re-union
	$s = microtime(true); FloatRangeType::$nextUpCalls = 0;
	$u = TypeCombinator::union($t, new ConstantFloatType(NAN)); $u2 = TypeCombinator::intersect($t, new FloatType()); $eq = $t->equals($u2);
	$isSup = $t->isSuperTypeOf(new ConstantFloatType(0.5));
	$extra = microtime(true) - $s; $extraCalls = FloatRangeType::$nextUpCalls;
	$ti = new IntegerType(); $s = microtime(true);
	for ($k = 1; $k <= $n; $k++) { $ti = TypeCombinator::remove($ti, new ConstantIntegerType(2 * $k)); }
	$intTime = microtime(true) - $s;
	printf("n=%3d  float remove loop: %6.2fs  nextUp calls=%9d   | one union(NAN)+intersect(float)+equals+isSuperTypeOf: %6.3fs nextUp=%8d | int gap remove loop: %5.2fs  members=%d\n", $n, $removeTime, $removeCalls, $extra, $extraCalls, $intTime, count($t->getTypes()));
}
// cost of a single nextUp
$s = microtime(true); for ($i = 0; $i < 100000; $i++) { FloatRangeType::nextUp(0.5); } printf("nextUp: %.2f us/call\n", (microtime(true) - $s) * 10);
