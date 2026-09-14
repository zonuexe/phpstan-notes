<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Accessory\AccessoryUppercaseStringType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Traits\NonArrayTypeTrait;
use PHPStan\Type\Traits\NonCallableTypeTrait;
use PHPStan\Type\Traits\NonGenericTypeTrait;
use PHPStan\Type\Traits\NonIterableTypeTrait;
use PHPStan\Type\Traits\NonObjectTypeTrait;
use PHPStan\Type\Traits\NonOffsetAccessibleTypeTrait;
use PHPStan\Type\Traits\UndecidedComparisonTypeTrait;
use function array_map;
use function get_class;
use function is_nan;
use function pack;
use function sprintf;
use function unpack;
use const INF;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * A convex set of non-NaN floats.
 *
 * This is deliberately not a copy of IntegerRangeType: the bounds are real float values
 * (-INF and INF are ordinary members of PHP's float ordering, so no null is needed) and each
 * bound records whether it is inclusive, because floats have no usable successor value -
 * `float ~ 0.0` or the truthy side of `$f > 0.0` can only be written with an open bound.
 *
 * NAN compares as neither smaller nor greater than anything, so it is never a member of a
 * range. `float<-inf, inf>` is therefore "every float except NAN" and is intentionally not
 * normalized to FloatType; FloatType is `float<-inf, inf>|NAN`.
 *
 * Like ConstantArrayType next to ArrayType, this is not a subclass of FloatType: the float
 * family is queried through Type::isFloat(), and FloatType delegates to this class through
 * CompoundType. instanceof FloatRangeType is therefore a representation check only.
 *
 * Doubles are a finite set, so an open bound has a closed equivalent at the adjacent double:
 * `(0.0, 1.0]` is the same set as `[5.0E-324, 1.0]`. The bounds are kept as written for
 * display, while emptiness, containment, equality and merging are decided on the canonical
 * closed bounds (see canonicalMin()/canonicalMax()).
 */
final class FloatRangeType implements CompoundType
{

	use NonArrayTypeTrait;
	use NonCallableTypeTrait;
	use NonIterableTypeTrait;
	use NonObjectTypeTrait;
	use NonGenericTypeTrait;
	use NonOffsetAccessibleTypeTrait;
	use UndecidedComparisonTypeTrait;

	private const SMALLEST_POSITIVE = 5.0E-324;
	private const WORD_MAX = 0xFFFF;

	private function __construct(
		private float $min,
		private float $max,
		private bool $minInclusive,
		private bool $maxInclusive,
	)
	{
	}

	public static function fromInterval(float $min, float $max, bool $minInclusive = true, bool $maxInclusive = true): Type
	{
		if (is_nan($min) || is_nan($max)) {
			throw new ShouldNotHappenException('NAN cannot be a bound of a float range.');
		}

		// -0.0 and 0.0 are the same point in PHP's ordering (-0.0 === 0.0)
		if ($min === 0.0) {
			$min = 0.0;
		}
		if ($max === 0.0) {
			$max = 0.0;
		}

		// nextUp()/nextDown() saturate at the infinities, so an open bound *at* the far end
		// (`[-inf, -inf)`, `(inf, inf]`) has to be recognized as empty explicitly.
		if ((!$minInclusive && $min === INF) || (!$maxInclusive && $max === -INF)) {
			return new NeverType();
		}
		$canonicalMin = $minInclusive ? $min : self::nextUp($min);
		$canonicalMax = $maxInclusive ? $max : self::nextDown($max);
		if ($canonicalMin > $canonicalMax) {
			return new NeverType();
		}
		if ($canonicalMin === $canonicalMax) {
			return new ConstantFloatType($canonicalMin === 0.0 ? 0.0 : $canonicalMin);
		}

		return new self($min, $max, $minInclusive, $maxInclusive);
	}

	/** The smallest float greater than the given one; INF stays INF. */
	public static int $nextUpCalls = 0;
	public static function nextUp(float $value): float
	{
		self::$nextUpCalls++;
		if (is_nan($value) || $value === INF) {
			return $value;
		}
		if ($value === 0.0) {
			return self::SMALLEST_POSITIVE;
		}

		// IEEE 754 doubles are sign-magnitude: stepping the magnitude by one moves to the adjacent
		// value. Done on four 16-bit words, which fit a native int on every PHP build.
		$words = unpack('n4', pack('E', $value));
		if ($words === false) {
			throw new ShouldNotHappenException();
		}
		for ($i = 4; $i >= 1; $i--) {
			if ($value > 0.0) {
				if ($words[$i] !== self::WORD_MAX) {
					$words[$i]++;
					break;
				}
				$words[$i] = 0;
			} else {
				if ($words[$i] !== 0) {
					$words[$i]--;
					break;
				}
				$words[$i] = self::WORD_MAX;
			}
		}

		$result = unpack('E', pack('n4', $words[1], $words[2], $words[3], $words[4]));
		if ($result === false) {
			throw new ShouldNotHappenException();
		}

		return $result[1];
	}

	/** The largest float smaller than the given one; -INF stays -INF. */
	public static function nextDown(float $value): float
	{
		return -self::nextUp(-$value);
	}

	private function canonicalMin(): float
	{
		return $this->minInclusive ? $this->min : self::nextUp($this->min);
	}

	private function canonicalMax(): float
	{
		return $this->maxInclusive ? $this->max : self::nextDown($this->max);
	}

	/** Every float except NAN, i.e. `float<-inf, inf>`. */
	public static function createNonNan(): Type
	{
		return self::fromInterval(-INF, INF);
	}

	/** Every finite float, i.e. `float<(-inf, inf)>` - the truthy side of is_finite(). */
	public static function createFinite(): Type
	{
		return self::fromInterval(-INF, INF, minInclusive: false, maxInclusive: false);
	}

	/** @param int|float $value */
	public static function createAllGreaterThan($value): Type
	{
		return self::fromInterval((float) $value, INF, minInclusive: false);
	}

	/** @param int|float $value */
	public static function createAllGreaterThanOrEqualTo($value): Type
	{
		return self::fromInterval((float) $value, INF);
	}

	/** @param int|float $value */
	public static function createAllSmallerThan($value): Type
	{
		return self::fromInterval(-INF, (float) $value, maxInclusive: false);
	}

	/** @param int|float $value */
	public static function createAllSmallerThanOrEqualTo($value): Type
	{
		return self::fromInterval(-INF, (float) $value);
	}

	public function getMin(): float
	{
		return $this->min;
	}

	public function getMax(): float
	{
		return $this->max;
	}

	public function isMinInclusive(): bool
	{
		return $this->minInclusive;
	}

	public function isMaxInclusive(): bool
	{
		return $this->maxInclusive;
	}

	public function describe(VerbosityLevel $level): string
	{
		$min = self::describeBound($this->min);
		$max = self::describeBound($this->max);
		if ($this->minInclusive && $this->maxInclusive) {
			return sprintf('float<%s, %s>', $min, $max);
		}

		return sprintf(
			'float<%s%s, %s%s>',
			$this->minInclusive ? '[' : '(',
			$min,
			$max,
			$this->maxInclusive ? ']' : ')',
		);
	}

	private static function describeBound(float $bound): string
	{
		if ($bound === -INF) {
			return '-inf';
		}
		if ($bound === INF) {
			return 'inf';
		}

		return (new ConstantFloatType($bound))->describe(VerbosityLevel::precise());
	}

	private function contains(float $value): bool
	{
		if (is_nan($value)) {
			return false;
		}

		return $value >= $this->canonicalMin() && $value <= $this->canonicalMax();
	}

	/**
	 * Bounds of a range or of a non-NAN constant float, or null for anything else.
	 *
	 * @return array{float, float, bool, bool}|null
	 */
	private static function boundsOf(Type $type): ?array
	{
		if ($type instanceof self) {
			return [$type->min, $type->max, $type->minInclusive, $type->maxInclusive];
		}
		if ($type instanceof ConstantFloatType && !is_nan($type->getValue())) {
			return [$type->getValue(), $type->getValue(), true, true];
		}

		return null;
	}

	private function intersectBounds(float $otherMin, float $otherMax, bool $otherMinInclusive, bool $otherMaxInclusive): Type
	{
		if ($this->min > $otherMin) {
			$min = $this->min;
			$minInclusive = $this->minInclusive;
		} elseif ($this->min < $otherMin) {
			$min = $otherMin;
			$minInclusive = $otherMinInclusive;
		} else {
			$min = $this->min;
			$minInclusive = $this->minInclusive && $otherMinInclusive;
		}

		if ($this->max < $otherMax) {
			$max = $this->max;
			$maxInclusive = $this->maxInclusive;
		} elseif ($this->max > $otherMax) {
			$max = $otherMax;
			$maxInclusive = $otherMaxInclusive;
		} else {
			$max = $this->max;
			$maxInclusive = $this->maxInclusive && $otherMaxInclusive;
		}

		return self::fromInterval($min, $max, $minInclusive, $maxInclusive);
	}

	/**
	 * Whether the union with the other bounds is still convex: the two overlap, or they touch
	 * at a point that at least one of them includes. `[0, 1)` and `(1, 2]` are not mergeable -
	 * 1.0 would be missing from `[0, 2]`.
	 */
	private function isMergeableWith(float $otherMin, float $otherMax, bool $otherMinInclusive, bool $otherMaxInclusive): bool
	{
		$otherCanonicalMin = $otherMinInclusive ? $otherMin : self::nextUp($otherMin);
		$otherCanonicalMax = $otherMaxInclusive ? $otherMax : self::nextDown($otherMax);

		return self::nextUp($this->canonicalMax()) >= $otherCanonicalMin
			&& self::nextUp($otherCanonicalMax) >= $this->canonicalMin();
	}

	public function accepts(Type $type, bool $strictTypes): AcceptsResult
	{
		if ($type->isInteger()->yes()) {
			return $this->isSuperTypeOf(self::integerToFloat($type))->toAcceptsResult();
		}

		if ($type instanceof CompoundType) {
			return $type->isAcceptedBy($this, $strictTypes);
		}

		if ($type->isFloat()->yes()) {
			return $this->isSuperTypeOf($type)->toAcceptsResult();
		}

		return AcceptsResult::createNo();
	}

	/** The floats an integer type is coerced to; `int` becomes `float<-9.2e18, 9.2e18>`. */
	private static function integerToFloat(Type $type): Type
	{
		if ($type instanceof ConstantIntegerType) {
			return new ConstantFloatType((float) $type->getValue());
		}
		if ($type instanceof IntegerRangeType) {
			return self::fromInterval((float) ($type->getMin() ?? PHP_INT_MIN), (float) ($type->getMax() ?? PHP_INT_MAX));
		}
		if ($type instanceof UnionType) {
			return TypeCombinator::union(...array_map(static fn (Type $inner): Type => self::integerToFloat($inner), $type->getTypes()));
		}

		return self::fromInterval((float) PHP_INT_MIN, (float) PHP_INT_MAX);
	}

	public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
	{
		if ($type instanceof ConstantFloatType) {
			return $this->contains($type->getValue()) ? IsSuperTypeOfResult::createYes() : IsSuperTypeOfResult::createNo();
		}

		if ($type instanceof self) {
			if ($this->canonicalMin() <= $type->canonicalMin() && $this->canonicalMax() >= $type->canonicalMax()) {
				return IsSuperTypeOfResult::createYes();
			}

			if ($this->canonicalMax() < $type->canonicalMin() || $type->canonicalMax() < $this->canonicalMin()) {
				return IsSuperTypeOfResult::createNo();
			}

			return IsSuperTypeOfResult::createMaybe();
		}

		if ($type instanceof CompoundType) {
			return $type->isSubTypeOf($this);
		}

		if ($type->isFloat()->yes()) {
			// a plain float may be NAN or lie outside the range
			return IsSuperTypeOfResult::createMaybe();
		}

		return IsSuperTypeOfResult::createNo();
	}

	public function isSubTypeOf(Type $otherType): IsSuperTypeOfResult
	{
		if ($otherType instanceof self) {
			return $otherType->isSuperTypeOf($this);
		}

		if ($otherType instanceof ConstantFloatType) {
			// a range is never a single point, but it may contain the constant
			return $this->contains($otherType->getValue()) ? IsSuperTypeOfResult::createMaybe() : IsSuperTypeOfResult::createNo();
		}

		if ($otherType instanceof UnionType) {
			return IsSuperTypeOfResult::createNo()->or(...array_map(fn (Type $innerType) => $this->isSubTypeOf($innerType), $otherType->getTypes()));
		}

		if ($otherType instanceof IntersectionType) {
			return $otherType->isSuperTypeOf($this);
		}

		if ($otherType->isFloat()->yes()) {
			// plain float; FloatType::isSuperTypeOf() delegates to this method for compound types
			return IsSuperTypeOfResult::createYes();
		}

		return IsSuperTypeOfResult::createNo();
	}

	public function isAcceptedBy(Type $acceptingType, bool $strictTypes): AcceptsResult
	{
		return $this->isSubTypeOf($acceptingType)->toAcceptsResult();
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self
			&& $this->canonicalMin() === $type->canonicalMin()
			&& $this->canonicalMax() === $type->canonicalMax();
	}

	public function generalize(GeneralizePrecision $precision): Type
	{
		return new FloatType();
	}

	/**
	 * Comparison results are decided at the ends of the range: `x < y` holds for every member
	 * when it holds at the upper end, and for no member when it fails at the lower end. An open
	 * bound is never reached, so the test at that end becomes the non-strict variant.
	 *
	 * 0.0 is checked separately because it compares differently to bool/null than the ends do.
	 */
	private function decideAtEnds(TrinaryLogic $atMin, TrinaryLogic $atMax, ?TrinaryLogic $atZero): TrinaryLogic
	{
		if ($atZero !== null) {
			return TrinaryLogic::extremeIdentity($atZero, $atMin, $atMax);
		}

		return TrinaryLogic::extremeIdentity($atMin, $atMax);
	}

	private function zero(): ?ConstantFloatType
	{
		return $this->contains(0.0) ? new ConstantFloatType(0.0) : null;
	}

	public function isSmallerThan(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		$min = new ConstantFloatType($this->min);
		$max = new ConstantFloatType($this->max);

		return $this->decideAtEnds(
			$min->isSmallerThan($otherType, $phpVersion),
			$this->maxInclusive ? $max->isSmallerThan($otherType, $phpVersion) : $max->isSmallerThanOrEqual($otherType, $phpVersion),
			$this->zero()?->isSmallerThan($otherType, $phpVersion),
		);
	}

	public function isSmallerThanOrEqual(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		$min = new ConstantFloatType($this->min);
		$max = new ConstantFloatType($this->max);

		return $this->decideAtEnds(
			$this->minInclusive ? $min->isSmallerThanOrEqual($otherType, $phpVersion) : $min->isSmallerThan($otherType, $phpVersion),
			$max->isSmallerThanOrEqual($otherType, $phpVersion),
			$this->zero()?->isSmallerThanOrEqual($otherType, $phpVersion),
		);
	}

	public function isGreaterThan(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		$min = new ConstantFloatType($this->min);
		$max = new ConstantFloatType($this->max);

		return $this->decideAtEnds(
			$this->minInclusive ? $otherType->isSmallerThan($min, $phpVersion) : $otherType->isSmallerThanOrEqual($min, $phpVersion),
			$otherType->isSmallerThan($max, $phpVersion),
			$this->zero() !== null ? $otherType->isSmallerThan($this->zero(), $phpVersion) : null,
		);
	}

	public function isGreaterThanOrEqual(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		$min = new ConstantFloatType($this->min);
		$max = new ConstantFloatType($this->max);

		return $this->decideAtEnds(
			$otherType->isSmallerThanOrEqual($min, $phpVersion),
			$this->maxInclusive ? $otherType->isSmallerThanOrEqual($max, $phpVersion) : $otherType->isSmallerThan($max, $phpVersion),
			$this->zero() !== null ? $otherType->isSmallerThanOrEqual($this->zero(), $phpVersion) : null,
		);
	}

	/**
	 * Everything `x` for which `x < $this` can hold: `x` is smaller than the largest member,
	 * i.e. the canonical upper bound - for `[a, b)` that is nextDown(b), not b.
	 */
	public function getSmallerType(PhpVersion $phpVersion): Type
	{
		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(
			new ConstantBooleanType(true),
			new ConstantFloatType(NAN),
			IntegerRangeType::createAllGreaterThanOrEqualTo($this->canonicalMax()),
			self::createAllGreaterThanOrEqualTo($this->canonicalMax()),
		));
	}

	public function getSmallerOrEqualType(PhpVersion $phpVersion): Type
	{
		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(
			new ConstantFloatType(NAN),
			IntegerRangeType::createAllGreaterThan($this->canonicalMax()),
			self::createAllGreaterThan($this->canonicalMax()),
		));
	}

	public function getGreaterType(PhpVersion $phpVersion): Type
	{
		$subtractedTypes = [
			new NullType(),
			new ConstantBooleanType(false),
			new ConstantFloatType(NAN),
			IntegerRangeType::createAllSmallerThanOrEqualTo($this->canonicalMin()),
			self::createAllSmallerThanOrEqualTo($this->canonicalMin()),
		];
		if (!$this->contains(0.0)) {
			$subtractedTypes[] = new ConstantBooleanType(true);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	public function getGreaterOrEqualType(PhpVersion $phpVersion): Type
	{
		$subtractedTypes = [
			new ConstantFloatType(NAN),
			IntegerRangeType::createAllSmallerThan($this->canonicalMin()),
			self::createAllSmallerThan($this->canonicalMin()),
		];
		if (!$this->contains(0.0)) {
			$subtractedTypes[] = new NullType();
			$subtractedTypes[] = new ConstantBooleanType(false);
		}

		return TypeCombinator::remove(new MixedType(), TypeCombinator::union(...$subtractedTypes));
	}

	/** `-[a, b]` is `[-b, -a]`; negation is exact, so open bounds stay open. */
	public function negate(): Type
	{
		return self::fromInterval(-$this->max, -$this->min, $this->maxInclusive, $this->minInclusive);
	}

	public function toBoolean(): BooleanType
	{
		if ($this->contains(0.0)) {
			return new BooleanType();
		}

		return new ConstantBooleanType(true);
	}

	public function toAbsoluteNumber(): Type
	{
		if ($this->min >= 0.0) {
			return $this;
		}

		if ($this->max <= 0.0) {
			return self::fromInterval(-$this->max, -$this->min, $this->maxInclusive, $this->minInclusive);
		}

		$negatedMin = -$this->min;
		if ($negatedMin > $this->max) {
			return self::fromInterval(0.0, $negatedMin, maxInclusive: $this->minInclusive);
		}
		if ($negatedMin < $this->max) {
			return self::fromInterval(0.0, $this->max, maxInclusive: $this->maxInclusive);
		}

		return self::fromInterval(0.0, $this->max, maxInclusive: $this->minInclusive || $this->maxInclusive);
	}

	public function toInteger(): Type
	{
		// (int) truncates toward zero, which is monotonic, so the truncated canonical ends bound
		// the result exactly - as long as both fit the analysing host's int range
		// (PHP_INT_MAX + 1.0 is the first float above PHP_INT_MAX on any int width).
		$min = $this->canonicalMin();
		$max = $this->canonicalMax();
		if ($min >= (float) PHP_INT_MIN && $max < PHP_INT_MAX + 1.0) {
			return IntegerRangeType::fromInterval((int) $min, (int) $max);
		}

		return new IntegerType();
	}

	/** Union with a float range, a float constant or plain float, or null if it is not convex. */
	public function tryUnion(Type $otherType): ?Type
	{
		if ($otherType instanceof ConstantFloatType && is_nan($otherType->getValue())) {
			if ($this->min === -INF && $this->max === INF && $this->minInclusive && $this->maxInclusive) {
				return new FloatType();
			}

			return null;
		}

		$bounds = self::boundsOf($otherType);
		if ($bounds !== null) {
			[$otherMin, $otherMax, $otherMinInclusive, $otherMaxInclusive] = $bounds;
			if (!$this->isMergeableWith($otherMin, $otherMax, $otherMinInclusive, $otherMaxInclusive)) {
				return null;
			}

			if ($this->min < $otherMin) {
				$min = $this->min;
				$minInclusive = $this->minInclusive;
			} elseif ($this->min > $otherMin) {
				$min = $otherMin;
				$minInclusive = $otherMinInclusive;
			} else {
				$min = $this->min;
				$minInclusive = $this->minInclusive || $otherMinInclusive;
			}

			if ($this->max > $otherMax) {
				$max = $this->max;
				$maxInclusive = $this->maxInclusive;
			} elseif ($this->max < $otherMax) {
				$max = $otherMax;
				$maxInclusive = $otherMaxInclusive;
			} else {
				$max = $this->max;
				$maxInclusive = $this->maxInclusive || $otherMaxInclusive;
			}

			return self::fromInterval($min, $max, $minInclusive, $maxInclusive);
		}

		if (get_class($otherType) === FloatType::class) {
			return $otherType;
		}

		return null;
	}

	public function tryIntersect(Type $otherType): ?Type
	{
		if ($otherType instanceof ConstantFloatType && is_nan($otherType->getValue())) {
			return new NeverType();
		}

		$bounds = self::boundsOf($otherType);
		if ($bounds !== null) {
			return $this->intersectBounds(...$bounds);
		}

		if (get_class($otherType) === FloatType::class) {
			return $this;
		}

		return null;
	}

	/**
	 * Removing a point or a range leaves up to two pieces whose new bounds are open - this is
	 * where float ranges need open bounds at all.
	 */
	public function tryRemove(Type $typeToRemove): ?Type
	{
		if (get_class($typeToRemove) === FloatType::class) {
			return new NeverType();
		}

		if ($typeToRemove instanceof ConstantFloatType && is_nan($typeToRemove->getValue())) {
			return $this;
		}

		$bounds = self::boundsOf($typeToRemove);
		if ($bounds === null) {
			return null;
		}

		[$removeMin, $removeMax, $removeMinInclusive, $removeMaxInclusive] = $bounds;
		if ($this->intersectBounds(...$bounds) instanceof NeverType) {
			return $this;
		}

		return TypeCombinator::union(
			self::fromInterval($this->min, $removeMin, $this->minInclusive, !$removeMinInclusive),
			self::fromInterval($removeMax, $this->max, !$removeMaxInclusive, $this->maxInclusive),
		);
	}

	public function getFiniteTypes(): array
	{
		return [];
	}

	/**
	 * Written in the parseable form: `float<min, max>` for a closed range and
	 * `float<min, max, closed-open>` (the Random\IntervalBoundary vocabulary) when a bound is
	 * open. phpdoc-parser cannot lex `-inf`, so the unbounded ends are spelled `min`/`max`.
	 */
	public function toPhpDocNode(): TypeNode
	{
		$genericTypes = [
			$this->min === -INF ? new IdentifierTypeNode('min') : (new ConstantFloatType($this->min))->toPhpDocNode(),
			$this->max === INF ? new IdentifierTypeNode('max') : (new ConstantFloatType($this->max))->toPhpDocNode(),
		];
		if (!$this->minInclusive || !$this->maxInclusive) {
			$genericTypes[] = new IdentifierTypeNode(sprintf(
				'%s-%s',
				$this->minInclusive ? 'closed' : 'open',
				$this->maxInclusive ? 'closed' : 'open',
			));
		}

		return new GenericTypeNode(new IdentifierTypeNode('float'), $genericTypes);
	}

	public function getReferencedClasses(): array
	{
		return [];
	}

	public function getObjectClassNames(): array
	{
		return [];
	}

	public function getObjectClassReflections(): array
	{
		return [];
	}

	public function getConstantStrings(): array
	{
		return [];
	}

	public function toNumber(): Type
	{
		return $this;
	}

	public function toBitwiseNotType(): Type
	{
		return new IntegerType();
	}

	public function toFloat(): Type
	{
		return $this;
	}

	public function toString(): Type
	{
		return new IntersectionType([
			new StringType(),
			new AccessoryUppercaseStringType(),
			new AccessoryNumericStringType(),
		]);
	}

	public function toArray(): Type
	{
		return new ConstantArrayType(
			[new ConstantIntegerType(0)],
			[$this],
			[1],
			isList: TrinaryLogic::createYes(),
		);
	}

	public function toArrayKey(): Type
	{
		return new IntegerType();
	}

	public function toCoercedArgumentType(bool $strictTypes): Type
	{
		if (!$strictTypes) {
			return TypeCombinator::union($this->toInteger(), $this, $this->toString(), $this->toBoolean());
		}

		return $this;
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isNull(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantValue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantScalarValue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getConstantScalarTypes(): array
	{
		return [];
	}

	public function getConstantScalarValues(): array
	{
		return [];
	}

	public function isTrue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isFalse(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isBoolean(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isFloat(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isInteger(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNumericString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isDecimalIntegerString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNonEmptyString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNonFalsyString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isLiteralString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isLowercaseString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isClassString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isUppercaseString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function isVoid(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isScalar(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		return new BooleanType();
	}

	public function traverse(callable $cb): Type
	{
		return $this;
	}

	public function traverseSimultaneously(Type $right, callable $cb): Type
	{
		return $this;
	}

	public function exponentiate(Type $exponent): Type
	{
		return ExponentiateHelper::exponentiate($this, $exponent);
	}

	public function hasTemplateOrLateResolvableType(): bool
	{
		return false;
	}

}
