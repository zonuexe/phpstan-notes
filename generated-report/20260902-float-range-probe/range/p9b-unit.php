<?php declare(strict_types=1);
require '/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/review-float-range-type/vendor/autoload.php';
use PHPStan\Type\FloatRangeType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntegerRangeType;
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
echo $d(TypeCombinator::union(FloatRangeType::fromInterval(0.0, 1.0), IntegerRangeType::fromInterval(0, 1))), "\n";
echo "int<0,1> isSuperTypeOf float<0,1>: ", IntegerRangeType::fromInterval(0, 1)->isSuperTypeOf(FloatRangeType::fromInterval(0.0, 1.0))->result->describe(), "\n";
echo "float<0,1> accepts int<0,1> strict: ", FloatRangeType::fromInterval(0.0, 1.0)->accepts(IntegerRangeType::fromInterval(0, 1), true)->result->describe(), "\n";
echo "float<0,1> accepts int strict: ", FloatRangeType::fromInterval(0.0, 1.0)->accepts(new IntegerType(), true)->result->describe(), "\n";
echo "float accepts float<0,1>: ", (new FloatType())->accepts(FloatRangeType::fromInterval(0.0, 1.0), true)->result->describe(), "\n";
echo "float<-inf,inf> accepts float: ", FloatRangeType::createNonNan()->accepts(new FloatType(), true)->result->describe(), "\n";
echo "float<-inf,inf> isSuperTypeOf float: ", FloatRangeType::createNonNan()->isSuperTypeOf(new FloatType())->result->describe(), "\n";
echo "float isSuperTypeOf float<-inf,inf>: ", (new FloatType())->isSuperTypeOf(FloatRangeType::createNonNan())->result->describe(), "\n";
echo "typeOnly describe: ", FloatRangeType::fromInterval(0.0, 1.0, false, true)->describe(VerbosityLevel::typeOnly()), " / int: ", IntegerRangeType::fromInterval(0, 1)->describe(VerbosityLevel::typeOnly()), " / const float: ", (new ConstantFloatType(1.5))->describe(VerbosityLevel::typeOnly()), "\n";
echo "value describe: ", FloatRangeType::fromInterval(0.0, 1.0, false, true)->describe(VerbosityLevel::value()), "\n";

echo "--- round trips through toPhpDocNode\n";
$printer = new Printer();
$config = new ParserConfig([]);
$lexer = new Lexer($config);
$ce = new ConstExprParser($config);
$tp = new TypeParser($config, $ce);
$container = \PHPStan\Testing\PHPStanTestCase::getContainer();
$resolver = $container->getByType(\PHPStan\PhpDoc\TypeNodeResolver::class);
$ns = new \PHPStan\Analyser\NameScope(null, []);
foreach ([
	FloatRangeType::fromInterval(0.0, 1.0),
	FloatRangeType::fromInterval(-INF, 0.0, true, false),
	FloatRangeType::fromInterval(0.0, INF, false, true),
	FloatRangeType::createFinite(),
	FloatRangeType::fromInterval(-PHP_FLOAT_MAX, PHP_FLOAT_MAX),
	FloatRangeType::fromInterval(5e-324, 1.0),
	FloatRangeType::fromInterval(-1e-320, 1e-320),
	FloatRangeType::fromInterval(0.1, 0.30000000000000004),
	TypeCombinator::remove(new FloatType(), new ConstantFloatType(0.0)),
] as $t) {
	$node = $t->toPhpDocNode();
	$printed = $printer->print($node);
	$back = $resolver->resolve($node, $ns);
	echo str_pad($d($t), 55), " -> ", str_pad($printed, 50), " -> ", $d($back), " equals=", var_export($t->equals($back), true), "\n";
	// also re-lex the printed string
	try {
		$it = new TokenIterator($lexer->tokenize($printed));
		$re = $tp->parse($it);
		$it->consumeTokenType(Lexer::TOKEN_END);
		$back2 = $resolver->resolve($re, $ns);
		echo "    relexed: ", $d($back2), "\n";
	} catch (\Throwable $e) {
		echo "    relex FAIL: ", $e->getMessage(), "\n";
	}
}

echo "--- phpdoc-parser lexing\n";
foreach (['float<0.0, 1.0, closed-open>', 'float<[0.0, 1.0)>', 'float<-inf, 1.0>', 'float<min, max>', 'float<(0.0, inf]>', 'float<0.0, inf, open-closed>', 'float<-INF, 1.0>', 'float<-1.0, 1.0>', 'float<5.0E-324, 1.0>', 'float<-1.7976931348623157E+308, 0.0>', 'float<1.0E+308, 2.0E+308>'] as $s) {
	try {
		$it = new TokenIterator($lexer->tokenize($s));
		$node = $tp->parse($it);
		$it->consumeTokenType(Lexer::TOKEN_END);
		echo str_pad($s, 40), " => ", $printer->print($node), "\n";
	} catch (\Throwable $e) {
		echo str_pad($s, 40), " => FAIL ", $e->getMessage(), "\n";
	}
}
echo "phpdoc-parser version: ", \Composer\InstalledVersions::getPrettyVersion('phpstan/phpdoc-parser'), "\n";

echo "--- getGreaterType of open-below range (subnormal leak)\n";
$pv = new \PHPStan\Php\PhpVersion(80500);
$pos = FloatRangeType::fromInterval(0.0, INF, false, true);
echo "x > (0, inf]: ", $d($pos->getGreaterType($pv)), "\n";
echo "x >= (0, inf]: ", $d($pos->getGreaterOrEqualType($pv)), "\n";
$half = FloatRangeType::fromInterval(0.0, 1.0, true, false);
echo "x < [0, 1): ", $d($half->getSmallerType($pv)), "\n";
echo "x <= [0, 1): ", $d($half->getSmallerOrEqualType($pv)), "\n";
echo "toInteger (0,1): ", $d(FloatRangeType::fromInterval(0.0, 1.0, false, false)->toInteger()), " ; (-1, 1): ", $d(FloatRangeType::fromInterval(-1.0, 1.0, false, false)->toInteger()), " ; [-0.5, 0.5]: ", $d(FloatRangeType::fromInterval(-0.5, 0.5)->toInteger()), " ; [1e19, 1e20]: ", $d(FloatRangeType::fromInterval(1e19, 1e20)->toInteger()), " ; [-inf, 0]: ", $d(FloatRangeType::fromInterval(-INF, 0.0)->toInteger()), "\n";
echo "toArrayKey [0,1]: ", $d(FloatRangeType::fromInterval(0.0, 1.0)->toArrayKey()), "\n";
echo "toCoercedArgumentType non-strict [0,1]: ", $d(FloatRangeType::fromInterval(0.0, 1.0)->toCoercedArgumentType(false)), "\n";
echo "FloatType toCoercedArgumentType non-strict: ", $d((new FloatType())->toCoercedArgumentType(false)), "\n";
echo "looseCompare 0.5 vs [0,1]: ", $d((new ConstantFloatType(0.5))->looseCompare(FloatRangeType::fromInterval(0.0, 1.0), $pv)), " ; 2.0 vs [0,1]: ", $d((new ConstantFloatType(2.0))->looseCompare(FloatRangeType::fromInterval(0.0, 1.0), $pv)), " ; [0,1] vs 2.0: ", $d(FloatRangeType::fromInterval(0.0, 1.0)->looseCompare(new ConstantFloatType(2.0), $pv)), "\n";
echo "isSmallerThan [0,1] < 2.0: ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new ConstantFloatType(2.0), $pv)->describe(), " ; 2.0 > [0,1]: ", (new ConstantFloatType(2.0))->isGreaterThan(FloatRangeType::fromInterval(0.0, 1.0), $pv)->describe(), " ; [0,1) < 1.0: ", $half->isSmallerThan(new ConstantFloatType(1.0), $pv)->describe(), " ; [0,1] < 1.0: ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new ConstantFloatType(1.0), $pv)->describe(), "\n";
echo "[0,1] < NAN: ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new ConstantFloatType(NAN), $pv)->describe(), " ; [0,1] <= NAN: ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThanOrEqual(new ConstantFloatType(NAN), $pv)->describe(), " ; NAN < [0,1]: ", (new ConstantFloatType(NAN))->isSmallerThan(FloatRangeType::fromInterval(0.0, 1.0), $pv)->describe(), " ; [0,1] > NAN: ", FloatRangeType::fromInterval(0.0, 1.0)->isGreaterThan(new ConstantFloatType(NAN), $pv)->describe(), "\n";
echo "[0,1] < null: ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new \PHPStan\Type\NullType(), $pv)->describe(), " ; (0,1] > null: ", FloatRangeType::fromInterval(0.0, 1.0, false)->isGreaterThan(new \PHPStan\Type\NullType(), $pv)->describe(), " ; [0,1] >= null: ", FloatRangeType::fromInterval(0.0, 1.0)->isGreaterThanOrEqual(new \PHPStan\Type\NullType(), $pv)->describe(), "\n";
echo "(0,1] > true: ", FloatRangeType::fromInterval(0.0, 1.0, false)->isGreaterThan(new \PHPStan\Type\Constant\ConstantBooleanType(true), $pv)->describe(), " ; (0,1] >= true: ", FloatRangeType::fromInterval(0.0, 1.0, false)->isGreaterThanOrEqual(new \PHPStan\Type\Constant\ConstantBooleanType(true), $pv)->describe(), " ; [0,1] >= true: ", FloatRangeType::fromInterval(0.0, 1.0)->isGreaterThanOrEqual(new \PHPStan\Type\Constant\ConstantBooleanType(true), $pv)->describe(), "\n";
echo "[0,1] < '0.5': ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new \PHPStan\Type\Constant\ConstantStringType('0.5'), $pv)->describe(), " ; [0,1] < 'abc': ", FloatRangeType::fromInterval(0.0, 1.0)->isSmallerThan(new \PHPStan\Type\Constant\ConstantStringType('abc'), $pv)->describe(), " ; [2,3] < 'abc': ", FloatRangeType::fromInterval(2.0, 3.0)->isSmallerThan(new \PHPStan\Type\Constant\ConstantStringType('abc'), $pv)->describe(), "\n";
