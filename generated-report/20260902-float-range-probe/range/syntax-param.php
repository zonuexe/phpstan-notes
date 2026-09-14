<?php
namespace ProbeSyntax;
use function PHPStan\dumpType;

/** @param float<min, max> $x */ function s01($x): void { dumpType($x); }
/** @param float<-inf, 1.0> $x */ function s02($x): void { dumpType($x); }
/** @param float<1.0, inf> $x */ function s03($x): void { dumpType($x); }
/** @param float<, 1.0> $x */ function s04($x): void { dumpType($x); }
/** @param float<1, 2> $x */ function s05($x): void { dumpType($x); }
/** @param float<1e3, 1E-3> $x */ function s06($x): void { dumpType($x); }
/** @param float<1E-3, 1e3> $x */ function s06b($x): void { dumpType($x); }
/** @param float<-1.5, 2> $x */ function s07($x): void { dumpType($x); }
/** @param float<0x10, 100> $x */ function s08($x): void { dumpType($x); }
/** @param float<0.1, 0.1> $x */ function s09($x): void { dumpType($x); }
/** @param float<1.0, 0.0> $x */ function s10($x): void { dumpType($x); }
/** @param float<NAN, 1> $x */ function s11($x): void { dumpType($x); }
/** @param float<0, 1, closed> $x */ function s12($x): void { dumpType($x); }
/** @param float<0, 1, ClosedOpen> $x */ function s13($x): void { dumpType($x); }
/** @param float<0.0, INF> $x */ function s14($x): void { dumpType($x); }
/** @param float<inf, inf> $x */ function s15($x): void { dumpType($x); }
/** @param float<0.0, max> $x */ function s16($x): void { dumpType($x); }
/** @param float<max, min> $x */ function s17($x): void { dumpType($x); }
/** @param float<0.0> $x */ function s18($x): void { dumpType($x); }
/** @param float<0.0, 1.0, open-open, closed-closed> $x */ function s19($x): void { dumpType($x); }
/** @param float<0.0, 5.0E-324, open-open> $x */ function s20($x): void { dumpType($x); }
/** @param float<-0.0, 0.0> $x */ function s21($x): void { dumpType($x); }
/** @param float<0.0, 1.0, 'closed-open'> $x */ function s22($x): void { dumpType($x); }
/** @param float<0.0, 1.0, \Random\IntervalBoundary::ClosedOpen> $x */ function s23($x): void { dumpType($x); }
/** @param float<1e400, inf> $x */ function s24($x): void { dumpType($x); }
/** @param float<PHP_FLOAT_MAX, inf> $x */ function s25($x): void { dumpType($x); }
/** @param float<0.0, 1.0>|NAN $x */ function s27($x): void { dumpType($x); }
/** @param float< 0.0 , 1.0 > $x */ function s28($x): void { dumpType($x); }
/** @param float<0.0,1.0> $x */ function s29($x): void { dumpType($x); }
/** @param float<-1_000.5, 1_000.5> $x */ function s30($x): void { dumpType($x); }
/** @param float<.5, 1.> $x */ function s31($x): void { dumpType($x); }
/** @param float<min, max, closed-closed> $x */ function s32($x): void { dumpType($x); }
/** @param float<min, inf> $x */ function s33($x): void { dumpType($x); }
/** @param float<0, 1, open-open> $x */ function s34($x): void { dumpType($x); }
/** @param float<0.0, 1.0, OPEN-OPEN> $x */ function s35($x): void { dumpType($x); }
/** @param float<9007199254740993, 9007199254740995> $x */ function s36($x): void { dumpType($x); }
/** @param float<PHP_INT_MAX, inf> $x */ function s37($x): void { dumpType($x); }
/** @param int<0x10, 20> $x */ function s38($x): void { dumpType($x); }
/** @param int<5, 1> $x */ function s39($x): void { dumpType($x); }
/** @param float<0.0, 1.0, closed-open> $x */ function s40($x): void { dumpType($x); }
/** @param float<-1.0, -0.0, closed-open> $x */ function s41($x): void { dumpType($x); }
/** @param float<0.0, 1.0>[] $x */ function s42($x): void { dumpType($x); }
/** @param list<float<0.0, 1.0, open-open>> $x */ function s43($x): void { dumpType($x); }
/** @param float<0.0, 1.0, open-closed, > $x */ function s44($x): void { dumpType($x); }
/** @param float<1.0, 1.0, closed-open> $x */ function s45($x): void { dumpType($x); }
/** @param float<min, max, open-closed> $x */ function s46($x): void { dumpType($x); }
/** @param float<0.0, 1e-400> $x */ function s47($x): void { dumpType($x); }
/** @param float<-1e400, 0.0> $x */ function s48($x): void { dumpType($x); }
/** @param float<0.0, 1.0, closed-open>|float<1.0, 2.0, open-closed> $x */ function s49($x): void { dumpType($x); }
/** @param non-empty-float $x */ function s50($x): void { dumpType($x); }
/** @param positive-float $x */ function s51($x): void { dumpType($x); }

class WithConst {
	const LOW = 0.0;
	const HIGH = 1.0;
	/** @param float<self::LOW, self::HIGH> $x */ function s26($x): void { dumpType($x); }
	/** @param float<0.0, 1.0> $x */ function withDefault($x = 2.0): void { dumpType($x); }
	/** @param float<0.0, 1.0> $x */ function withIntDefault($x = 1): void { dumpType($x); }
	/** @param float<0.0, 1.0> $x */ function withNegZeroDefault($x = -0.0): void { dumpType($x); }
}
