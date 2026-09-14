<?php declare(strict_types = 1);

$root = $argv[1];
require $root . '/vendor/autoload.php';

use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\NullType;
use PHPStan\Type\TypeCombinator;

$ints = [];
for ($i = 0; $i < 200; $i++) { $ints[] = new ConstantIntegerType($i * 3); }
$strs = [];
for ($i = 0; $i < 200; $i++) { $strs[] = new ConstantStringType('s' . $i); }
$mixedBag = [];
for ($i = 0; $i < 50; $i++) { $mixedBag[] = new ConstantStringType('k' . $i); $mixedBag[] = IntegerRangeType::fromInterval($i * 10, $i * 10 + 5); $mixedBag[] = new ConstantFloatType($i / 7); }
$floats = [];
for ($i = 0; $i < 200; $i++) { $floats[] = new ConstantFloatType($i / 3); }

function bench(string $name, callable $fn, int $n): void {
	$fn();
	$t = hrtime(true);
	for ($i = 0; $i < $n; $i++) { $fn(); }
	$dt = (hrtime(true) - $t) / 1e6 / $n;
	printf("%-32s %8.3f ms/op\n", $name, $dt);
}

bench('union 200 const ints', fn () => TypeCombinator::union(...$ints), 100);
bench('union 200 const strings', fn () => TypeCombinator::union(...$strs), 100);
bench('union 150 mixed bag', fn () => TypeCombinator::union(...$mixedBag), 100);
bench('union 200 const floats', fn () => TypeCombinator::union(...$floats), 100);
bench('union 200 floats + float', fn () => TypeCombinator::union(new FloatType(), ...$floats), 100);
bench('remove(float|null, null)', fn () => TypeCombinator::remove(TypeCombinator::union(new FloatType(), new NullType()), new NullType()), 2000);
bench('remove(int|string, int)', fn () => TypeCombinator::remove(TypeCombinator::union(new StringType(), IntegerRangeType::fromInterval(0, 100)), IntegerRangeType::fromInterval(0, 10)), 2000);
bench('intersect 200 ints/ranges', fn () => TypeCombinator::intersect(TypeCombinator::union(...$ints), IntegerRangeType::fromInterval(0, 300)), 200);
