<?php declare(strict_types=1);
require '/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/review-float-range-type/vendor/autoload.php';
use PHPStan\Type\FloatRangeType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;

$p = VerbosityLevel::precise();
$d = fn($t) => $t->describe($p);

echo "--- canonical equivalence\n";
$open = FloatRangeType::fromInterval(0.0, 1.0, false, false);
$closed = FloatRangeType::fromInterval(5e-324, 0.9999999999999999);
echo $d($open), " equals ", $d($closed), " => ", var_export($open->equals($closed), true), "\n";
echo "isSuperTypeOf both ways: ", $open->isSuperTypeOf($closed)->result->describe(), " / ", $closed->isSuperTypeOf($open)->result->describe(), "\n";
echo "union(open, closed) => ", $d(TypeCombinator::union($open, $closed)), "\n";
echo "union(closed, open) => ", $d(TypeCombinator::union($closed, $open)), "\n";
echo "intersect(open, closed) => ", $d(TypeCombinator::intersect($open, $closed)), "\n";
echo "toPhpDocNode(open) => ", (string) $open->toPhpDocNode(), "\n";
echo "toPhpDocNode(closed) => ", (string) $closed->toPhpDocNode(), "\n";

echo "--- nextUp edge cases\n";
foreach ([0.0, -0.0, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, 5e-324, -5e-324, PHP_FLOAT_MIN, 1.0, -1.0, INF, -INF] as $v) {
	printf("nextUp(%s) = %s ; nextDown(%s) = %s\n", var_export($v, true), var_export(FloatRangeType::nextUp($v), true), var_export($v, true), var_export(FloatRangeType::nextDown($v), true));
}

echo "--- -0.0\n";
echo "ConstantFloatType(-0.0) describe: ", $d(new ConstantFloatType(-0.0)), " equals 0.0: ", var_export((new ConstantFloatType(-0.0))->equals(new ConstantFloatType(0.0)), true), "\n";
echo "fromInterval(-0.0, 1.0): ", $d(FloatRangeType::fromInterval(-0.0, 1.0)), "\n";
echo "fromInterval(-1.0, -0.0, true, false): ", $d(FloatRangeType::fromInterval(-1.0, -0.0, true, false)), "\n";
echo "[0,1] isSuperTypeOf -0.0: ", FloatRangeType::fromInterval(0.0, 1.0)->isSuperTypeOf(new ConstantFloatType(-0.0))->result->describe(), "\n";
echo "(0,1] isSuperTypeOf -0.0: ", FloatRangeType::fromInterval(0.0, 1.0, false)->isSuperTypeOf(new ConstantFloatType(-0.0))->result->describe(), "\n";
echo "remove(float, -0.0): ", $d(TypeCombinator::remove(new FloatType(), new ConstantFloatType(-0.0))), "\n";
echo "remove([-1,1], 0.0): ", $d(TypeCombinator::remove(FloatRangeType::fromInterval(-1.0, 1.0), new ConstantFloatType(0.0))), "\n";
echo "toString of [-1, 0]: ", $d(FloatRangeType::fromInterval(-1.0, 0.0)->toString()), "\n";
echo "(-0.0)->toString: ", $d((new ConstantFloatType(-0.0))->toString()), "\n";

echo "--- infinities / subnormal\n";
echo "fromInterval(PHP_FLOAT_MAX, INF, false, true): ", $d(FloatRangeType::fromInterval(PHP_FLOAT_MAX, INF, false, true)), "\n";
echo "fromInterval(PHP_FLOAT_MAX, INF, false, false): ", $d(FloatRangeType::fromInterval(PHP_FLOAT_MAX, INF, false, false)), "\n";
echo "fromInterval(-INF, -INF): ", $d(FloatRangeType::fromInterval(-INF, -INF)), "\n";
echo "fromInterval(-INF, INF, false, false) equals [-MAX, MAX]: ", var_export(FloatRangeType::createFinite()->equals(FloatRangeType::fromInterval(-PHP_FLOAT_MAX, PHP_FLOAT_MAX)), true), "\n";
echo "fromInterval(0.0, 5e-324, false, false): ", $d(FloatRangeType::fromInterval(0.0, 5e-324, false, false)), "\n";
echo "fromInterval(0.0, 1e-323, false, false): ", $d(FloatRangeType::fromInterval(0.0, 1e-323, false, false)), "\n";

echo "--- unions of many disjoint ranges\n";
$t = new FloatType();
for ($i = 1; $i <= 12; $i++) { $t = TypeCombinator::remove($t, new ConstantFloatType($i / 10)); }
echo $d($t), "\n";
echo "count members: ", count($t->getTypes()), "\n";
echo "generalize: ", $d($t->generalize(\PHPStan\Type\GeneralizePrecision::lessSpecific())), "\n";

echo "--- union ordering / merging\n";
echo $d(TypeCombinator::union(FloatRangeType::fromInterval(0.0, 1.0, true, false), FloatRangeType::fromInterval(1.0, 2.0, false, true))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::fromInterval(0.0, 1.0, true, false), new ConstantFloatType(1.0), FloatRangeType::fromInterval(1.0, 2.0, false, true))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::fromInterval(0.0, 1.0), new ConstantFloatType(NAN))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::createNonNan(), new ConstantFloatType(NAN))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::createFinite(), new ConstantFloatType(INF), new ConstantFloatType(-INF), new ConstantFloatType(NAN))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::createAllSmallerThan(0.0), FloatRangeType::createAllGreaterThan(0.0), new ConstantFloatType(0.0), new ConstantFloatType(NAN))), "\n";
echo $d(TypeCombinator::union(FloatRangeType::fromInterval(0.0, 1.0), new \PHPStan\Type\IntegerRangeType(0, 1))), "\n";
echo "int<0,1> isSuperTypeOf float<0,1>: ", (new \PHPStan\Type\IntegerRangeType(0, 1))->isSuperTypeOf(FloatRangeType::fromInterval(0.0, 1.0))->result->describe(), "\n";
echo "float<0,1> accepts int<0,1> strict: ", FloatRangeType::fromInterval(0.0, 1.0)->accepts(\PHPStan\Type\IntegerRangeType::fromInterval(0, 1), true)->result->describe(), "\n";
echo "float<0,1> accepts int strict: ", FloatRangeType::fromInterval(0.0, 1.0)->accepts(new \PHPStan\Type\IntegerType(), true)->result->describe(), "\n";
echo "float accepts float<0,1>: ", (new FloatType())->accepts(FloatRangeType::fromInterval(0.0, 1.0), true)->result->describe(), "\n";
echo "float<-inf,inf> accepts float: ", FloatRangeType::createNonNan()->accepts(new FloatType(), true)->result->describe(), "\n";
echo "float<-inf,inf> isSuperTypeOf float: ", FloatRangeType::createNonNan()->isSuperTypeOf(new FloatType())->result->describe(), "\n";
echo "float isSuperTypeOf float<-inf,inf>: ", (new FloatType())->isSuperTypeOf(FloatRangeType::createNonNan())->result->describe(), "\n";
echo "typeOnly describe: ", FloatRangeType::fromInterval(0.0, 1.0, false, true)->describe(VerbosityLevel::typeOnly()), " / int: ", (new \PHPStan\Type\IntegerRangeType(0, 1))->describe(VerbosityLevel::typeOnly()), "\n";

echo "--- phpdoc-parser lexing\n";
$config = new ParserConfig([]);
$lexer = new Lexer($config);
$ce = new ConstExprParser($config);
$tp = new TypeParser($config, $ce);
$printer = new Printer();
foreach (['float<0.0, 1.0, closed-open>', 'float<[0.0, 1.0)>', 'float<-inf, 1.0>', 'float<min, max>', 'float<(0.0, inf]>', 'float<0.0, inf, open-closed>', 'float<-INF, 1.0>', 'float<-1.0, 1.0>'] as $s) {
	try {
		$it = new TokenIterator($lexer->tokenize($s));
		$node = $tp->parse($it);
		$it->consumeTokenType(Lexer::TOKEN_END);
		echo str_pad($s, 32), " => ", get_class($node), " ", $printer->print($node), "\n";
	} catch (\Throwable $e) {
		echo str_pad($s, 32), " => FAIL ", $e->getMessage(), "\n";
	}
}
echo "phpdoc-parser version: ", \Composer\InstalledVersions::getPrettyVersion('phpstan/phpdoc-parser'), "\n";
