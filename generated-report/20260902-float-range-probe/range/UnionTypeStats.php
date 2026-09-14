<?php declare(strict_types = 1);

namespace PHPStan\Type;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Error;
use Exception;
use PHPStan\DependencyInjection\ReportUnsafeArrayStringKeyCastingToggle;
use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\MissingPropertyFromReflectionException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\Type\UnionTypeUnresolvedMethodPrototypeReflection;
use PHPStan\Reflection\Type\UnionTypeUnresolvedPropertyPrototypeReflection;
use PHPStan\Reflection\Type\UnresolvedMethodPrototypeReflection;
use PHPStan\Reflection\Type\UnresolvedPropertyPrototypeReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Enum\EnumCaseObjectType;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\TemplateIterableType;
use PHPStan\Type\Generic\TemplateMixedType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Generic\TemplateUnionType;
use PHPStan\Type\Traits\NonGeneralizableTypeTrait;
use Throwable;
use function array_diff_assoc;
use function array_fill_keys;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function str_contains;

/** @api */
class UnionType implements CompoundType
{
	public static array $stats = [];

	use NonGeneralizableTypeTrait;

	public const EQUAL_UNION_CLASSES = [
		DateTimeInterface::class => [DateTimeImmutable::class, DateTime::class],
		Throwable::class => [Error::class, Exception::class], // phpcs:ignore SlevomatCodingStandard.Exceptions.ReferenceThrowableOnly.ReferencedGeneralException
	];

	/**
	 * Sorting must not reorder $types: describe() sorts for readability, but $types is the
	 * type's value — getTypes() exposes it and callers merge array shapes in that order. It
	 * used to be sorted in place, so describing a union permanently changed what getTypes()
	 * returned, making an immutable value object's observable state depend on what had been
	 * called on it before.
	 *
	 * @var list<Type>|null
	 */
	private ?array $sortedTypesCache = null;

	/** @var array<int, string> */
	private array $cachedDescriptions = [];

	/**
	 * Identity-keyed view of $types, built on first use. False once it is known that the
	 * members cannot be keyed at all, so a union of objects pays for the attempt only once.
	 */
	private FiniteTypeSet|false|null $finiteTypeSet = null;

	/** @var list<Type>|null */
	private ?array $finiteTypes = null;

	private ?TrinaryLogic $isNull = null;

	private ?TrinaryLogic $isCallable = null;

	/**
	 * @api
	 * @param list<Type> $types
	 */
	public function __construct(private array $types, private bool $normalized = false)
	{
		self::$stats["__construct"] = (self::$stats["__construct"] ?? 0) + 1;
		$throwException = static function () use ($types): void {
			throw new ShouldNotHappenException(sprintf(
				'Cannot create %s with: %s',
				self::class,
				implode(', ', array_map(static fn (Type $type): string => $type->describe(VerbosityLevel::value()), $types)),
			));
		};
		if (count($types) < 2) {
			$throwException();
		}
		foreach ($types as $type) {
			if (!($type instanceof UnionType)) {
				continue;
			}
			if ($type instanceof TemplateType) {
				continue;
			}

			$throwException();
		}
	}

	/**
	 * @return list<Type>
	 */
	public function getTypes(): array
	{
		self::$stats["getTypes"] = (self::$stats["getTypes"] ?? 0) + 1;
		return $this->types;
	}

	/**
	 * @param callable(Type $type): bool $filterCb
	 */
	public function filterTypes(callable $filterCb): Type
	{
		self::$stats["filterTypes"] = (self::$stats["filterTypes"] ?? 0) + 1;
		$newTypes = [];
		$changed = false;
		foreach ($this->getTypes() as $innerType) {
			if (!$filterCb($innerType)) {
				$changed = true;
				continue;
			}

			$newTypes[] = $innerType;
		}

		if (!$changed) {
			return $this;
		}

		return TypeCombinator::union(...$newTypes);
	}

	public function isNormalized(): bool
	{
		self::$stats["isNormalized"] = (self::$stats["isNormalized"] ?? 0) + 1;
		return $this->normalized;
	}

	public function getFiniteTypeSet(): ?FiniteTypeSet
	{
		self::$stats["getFiniteTypeSet"] = (self::$stats["getFiniteTypeSet"] ?? 0) + 1;
		$finiteTypeSet = $this->finiteTypeSet ??= FiniteTypeSet::create($this->types) ?? false;
		if ($finiteTypeSet === false) {
			return null;
		}

		return $finiteTypeSet;
	}

	/**
	 * @return list<Type>
	 */
	protected function getSortedTypes(): array
	{
		self::$stats["getSortedTypes"] = (self::$stats["getSortedTypes"] ?? 0) + 1;
		return $this->sortedTypesCache ??= UnionTypeHelper::sortTypes($this->types);
	}

	public function getReferencedClasses(): array
	{
		self::$stats["getReferencedClasses"] = (self::$stats["getReferencedClasses"] ?? 0) + 1;
		$classes = [];
		foreach ($this->types as $type) {
			foreach ($type->getReferencedClasses() as $className) {
				$classes[] = $className;
			}
		}

		return $classes;
	}

	public function getObjectClassNames(): array
	{
		self::$stats["getObjectClassNames"] = (self::$stats["getObjectClassNames"] ?? 0) + 1;
		return array_values(array_unique($this->pickFromTypes(
			static fn (Type $type) => $type->getObjectClassNames(),
			static fn (Type $type) => $type->isObject()->yes(),
		)));
	}

	public function getObjectClassReflections(): array
	{
		self::$stats["getObjectClassReflections"] = (self::$stats["getObjectClassReflections"] ?? 0) + 1;
		return $this->pickFromTypes(
			static fn (Type $type) => $type->getObjectClassReflections(),
			static fn (Type $type) => $type->isObject()->yes(),
		);
	}

	public function getArrays(): array
	{
		self::$stats["getArrays"] = (self::$stats["getArrays"] ?? 0) + 1;
		return $this->pickFromTypes(
			static fn (Type $type) => $type->getArrays(),
			static fn (Type $type) => $type->isArray()->yes(),
		);
	}

	public function getConstantArrays(): array
	{
		self::$stats["getConstantArrays"] = (self::$stats["getConstantArrays"] ?? 0) + 1;
		return $this->pickFromTypes(
			static fn (Type $type) => $type->getConstantArrays(),
			static fn (Type $type) => $type->isArray()->yes(),
		);
	}

	public function getConstantStrings(): array
	{
		self::$stats["getConstantStrings"] = (self::$stats["getConstantStrings"] ?? 0) + 1;
		return $this->pickFromTypes(
			static fn (Type $type) => $type->getConstantStrings(),
			static fn (Type $type) => $type->isString()->yes(),
		);
	}

	public function accepts(Type $type, bool $strictTypes): AcceptsResult
	{
		self::$stats["accepts"] = (self::$stats["accepts"] ?? 0) + 1;
		$finiteTypeSet = $this->getFiniteTypeSet();
		if ($finiteTypeSet !== null) {
			$key = FiniteTypeSet::key($type);
			if ($key !== null && $finiteTypeSet->has($key)) {
				return AcceptsResult::createYes();
			}

			if ($finiteTypeSet->isComplete()) {
				if ($key !== null) {
					// A value the union does not hold can still be accepted through scalar
					// coercion, so unlike isSuperTypeOf() the answer is not simply no - but it
					// is the same for every member of a kind, so one member of each is enough.
					// None of the branches below apply to a value: it is not iterable, not
					// compound, and an enum case already is the union of its own cases.
					$result = AcceptsResult::createNo();
					foreach ($finiteTypeSet->getRepresentativesOfOtherKinds($type) as $representative) {
						$result = $result->or($representative->accepts($type, $strictTypes));
					}

					return $result;
				}

				if ($type instanceof self && !$type instanceof TemplateType) {
					$otherFiniteTypeSet = $type->getFiniteTypeSet();
					if ($otherFiniteTypeSet !== null && $otherFiniteTypeSet->isComplete()) {
						// A member standing for a single value never accepts more than one of
						// the other union's values, and the other union has at least two of
						// them - so the member-by-member or() below cannot come out yes, and
						// its result is discarded in favour of the compound answer anyway.
						return $type->isAcceptedBy($this, $strictTypes);
					}
				}
			}
		}

		if ($type instanceof IterableType) {
			return $this->accepts($type->toArrayOrTraversable(), $strictTypes);
		}

		foreach (self::EQUAL_UNION_CLASSES as $baseClass => $classes) {
			if (!$type->equals(new ObjectType($baseClass))) {
				continue;
			}

			$union = TypeCombinator::union(
				...array_map(static fn (string $objectClass): Type => new ObjectType($objectClass), $classes),
			);
			if ($this->accepts($union, $strictTypes)->yes()) {
				return AcceptsResult::createYes();
			}
			break;
		}

		$innerAccepts = [];
		$result = AcceptsResult::createNo();
		foreach ($this->getSortedTypes() as $i => $innerType) {
			$innerResult = $innerType->accepts($type, $strictTypes);
			$innerAccepts[$i] = $innerResult;
			$result = $result->or($innerResult->decorateReasons(static fn (string $reason) => sprintf('Type #%d from the union: %s', $i + 1, $reason)));
		}
		if ($result->yes()) {
			return $result;
		}

		$commonReasons = null;
		foreach ($innerAccepts as $innerResult) {
			if ($commonReasons === null) {
				$commonReasons = $innerResult->reasons;
				continue;
			}
			$commonReasons = array_values(array_intersect($commonReasons, $innerResult->reasons));
		}
		if ($commonReasons !== null && count($commonReasons) > 0) {
			$decorated = [];
			foreach (array_keys($innerAccepts) as $i) {
				foreach ($commonReasons as $reason) {
					$decorated[] = sprintf('Type #%d from the union: %s', $i + 1, $reason);
				}
			}
			$result = new AcceptsResult($result->result, $decorated);
		}

		if ($type instanceof CompoundType && !$type instanceof CallableType && !$type instanceof TemplateType && !$type instanceof IntersectionType) {
			return $type->isAcceptedBy($this, $strictTypes);
		}

		if ($type instanceof TemplateUnionType) {
			return $result->or($type->isAcceptedBy($this, $strictTypes));
		}

		if ($type->isEnum()->yes() && !$this->isEnum()->no()) {
			$enumCasesUnion = TypeCombinator::union(...$type->getEnumCases());
			if (!$type->equals($enumCasesUnion)) {
				return $this->accepts($enumCasesUnion, $strictTypes);
			}
		}

		return $result;
	}

	public function isSuperTypeOf(Type $otherType): IsSuperTypeOfResult
	{
		self::$stats["isSuperTypeOf"] = (self::$stats["isSuperTypeOf"] ?? 0) + 1;
		if (
			($otherType instanceof self && !$otherType instanceof TemplateUnionType)
			|| ($otherType instanceof IterableType && !$otherType instanceof TemplateIterableType)
			|| $otherType instanceof NeverType
			|| $otherType instanceof IntegerRangeType
			|| $otherType instanceof FloatRangeType
		) {
			return $otherType->isSubTypeOf($this);
		}

		$types = $this->types;
		$finiteTypeSet = $this->getFiniteTypeSet();
		if ($finiteTypeSet !== null) {
			$key = FiniteTypeSet::key($otherType);
			if ($key !== null) {
				if ($finiteTypeSet->has($key)) {
					return IsSuperTypeOfResult::createYes();
				}

				// Every keyed member stands for a different value than $otherType, so all of
				// them answer no - only the members that could not be keyed are left to ask.
				if ($finiteTypeSet->isComplete()) {
					return IsSuperTypeOfResult::createNo();
				}

				$types = $finiteTypeSet->getOthers();
			}
		}

		$results = [];
		foreach ($types as $innerType) {
			$result = $innerType->isSuperTypeOf($otherType);
			if ($result->yes()) {
				return $result;
			}
			$results[] = $result;
		}
		$result = IsSuperTypeOfResult::createNo()->or(...$results);

		if (
			$otherType instanceof TemplateUnionType
			|| ($otherType instanceof LateResolvableType && $otherType instanceof CompoundType && !$otherType instanceof TemplateType)
		) {
			return $result->or($otherType->isSubTypeOf($this));
		}

		return $result;
	}

	public function isSubTypeOf(Type $otherType): IsSuperTypeOfResult
	{
		self::$stats["isSubTypeOf"] = (self::$stats["isSubTypeOf"] ?? 0) + 1;
		$containment = $this->finiteTypeSetContainedIn($otherType, false);
		if ($containment !== null) {
			if ($containment->maybe()) {
				return IsSuperTypeOfResult::createMaybe();
			}

			return IsSuperTypeOfResult::createFromBoolean($containment->yes());
		}

		return IsSuperTypeOfResult::extremeIdentity(...array_map(static fn (Type $innerType) => $otherType->isSuperTypeOf($innerType), $this->types));
	}

	public function isAcceptedBy(Type $acceptingType, bool $strictTypes): AcceptsResult
	{
		self::$stats["isAcceptedBy"] = (self::$stats["isAcceptedBy"] ?? 0) + 1;
		// Unlike isSubTypeOf() only the positive answer holds: a member $acceptingType does
		// not hold can still be accepted through scalar coercion.
		$containment = $this->finiteTypeSetContainedIn($acceptingType, true);
		if ($containment !== null && $containment->yes()) {
			return AcceptsResult::createYes();
		}

		return AcceptsResult::extremeIdentity(...array_map(static fn (Type $innerType) => $acceptingType->accepts($innerType, $strictTypes), $this->types));
	}

	/**
	 * Whether $otherType holds every member of this one, or null when the question cannot
	 * be settled from the identity maps alone.
	 *
	 * $otherType is compared as a set of values: a union through its own map, a single
	 * finite value as the one-member set holding it.
	 *
	 * $yesOnly skips the completeness requirement on the other union: a member missing from
	 * its map only rules out the yes answer, which is all the caller is after.
	 */
	private function finiteTypeSetContainedIn(Type $otherType, bool $yesOnly): ?TrinaryLogic
	{
		self::$stats["finiteTypeSetContainedIn"] = (self::$stats["finiteTypeSetContainedIn"] ?? 0) + 1;
		$finiteTypeSet = $this->getFiniteTypeSet();
		if ($finiteTypeSet === null || !$finiteTypeSet->isComplete()) {
			return null;
		}

		if (!$otherType instanceof self || $otherType instanceof TemplateType) {
			// A single value covers a member iff they are the same value, and no member
			// stands for a value it is not keyed by - so one lookup answers for every member
			// at once. This is the shape TypeCombinator::remove() takes to ask whether
			// removing a value empties a union of values, and the only way a non-union other
			// type reaches this helper at all.
			$key = FiniteTypeSet::key($otherType);
			if ($key === null) {
				return null;
			}

			$containment = $finiteTypeSet->containedInKey($key);

			// No constant value accepts another one - not even a numeric string an int, in
			// either direction - so today accepts() agrees with isSuperTypeOf() here and this
			// guard never changes an answer. It is kept because that is a fact about the
			// current value types rather than something the identity map guarantees: a value
			// the map does not hold being accepted anyway is exactly what accepts() is
			// allowed to do, and all isAcceptedBy() wants from the map is the yes.
			if (!$containment->yes() && $yesOnly) {
				return null;
			}

			return $containment;
		}

		$otherFiniteTypeSet = $otherType->getFiniteTypeSet();
		if ($otherFiniteTypeSet === null) {
			return null;
		}

		$containment = $finiteTypeSet->containedIn($otherFiniteTypeSet);
		if (!$containment->yes() && ($yesOnly || !$otherFiniteTypeSet->isComplete())) {
			return null;
		}

		return $containment;
	}

	public function equals(Type $type): bool
	{
		self::$stats["equals"] = (self::$stats["equals"] ?? 0) + 1;
		if (!$type instanceof static) {
			return false;
		}

		if (count($this->types) !== count($type->types)) {
			return false;
		}

		$finiteTypeSet = $this->getFiniteTypeSet();
		if ($finiteTypeSet !== null && $finiteTypeSet->isComplete()) {
			$otherFiniteTypeSet = $type->getFiniteTypeSet();
			if ($otherFiniteTypeSet !== null && $otherFiniteTypeSet->isComplete()) {
				return $finiteTypeSet->containedIn($otherFiniteTypeSet)->yes();
			}
		}

		$otherTypes = $type->types;
		foreach ($this->types as $innerType) {
			$match = false;
			foreach ($otherTypes as $i => $otherType) {
				if (!$innerType->equals($otherType)) {
					continue;
				}

				$match = true;
				unset($otherTypes[$i]);
				break;
			}

			if (!$match) {
				return false;
			}
		}

		return count($otherTypes) === 0;
	}

	public function describe(VerbosityLevel $level): string
	{
		self::$stats["describe"] = (self::$stats["describe"] ?? 0) + 1;
		if (isset($this->cachedDescriptions[$level->getLevelValue()])) {
			return $this->cachedDescriptions[$level->getLevelValue()];
		}
		$joinTypes = static function (array $types) use ($level): string {
			$typeNames = [];
			foreach ($types as $i => $type) {
				if ($type instanceof ClosureType || $type instanceof CallableType || $type instanceof TemplateUnionType) {
					$typeNames[] = sprintf('(%s)', $type->describe($level));
				} elseif ($type instanceof TemplateType) {
					$isLast = $i >= count($types) - 1;
					$bound = $type->getBound();
					if (
						!$isLast
						&& ($level->isTypeOnly() || $level->isValue())
						&& !($bound instanceof MixedType && $bound->getSubtractedType() === null && !$bound instanceof TemplateMixedType)
					) {
						$typeNames[] = sprintf('(%s)', $type->describe($level));
					} else {
						$typeNames[] = $type->describe($level);
					}
				} elseif ($type instanceof IntersectionType) {
					$intersectionDescription = $type->describe($level);
					if (str_contains($intersectionDescription, '&')) {
						$typeNames[] = sprintf('(%s)', $intersectionDescription);
					} else {
						$typeNames[] = $intersectionDescription;
					}
				} else {
					$typeNames[] = $type->describe($level);
				}
			}

			if ($level->isPrecise() || $level->isCache()) {
				$duplicates = array_diff_assoc($typeNames, array_unique($typeNames));
				if (count($duplicates) > 0) {
					$indexByDuplicate = array_fill_keys($duplicates, 0);
					foreach ($typeNames as $key => $typeName) {
						if (!isset($indexByDuplicate[$typeName])) {
							continue;
						}

						$typeNames[$key] = $typeName . '#' . ++$indexByDuplicate[$typeName];
					}
				}
			} else {
				$typeNames = array_unique($typeNames);
			}

			if (count($typeNames) > 1024) {
				return implode('|', array_slice($typeNames, 0, 1024)) . "|\u{2026}";
			}

			return implode('|', $typeNames);
		};

		return $this->cachedDescriptions[$level->getLevelValue()] = $level->handle(
			function () use ($joinTypes): string {
				$types = TypeCombinator::union(...array_map(static function (Type $type): Type {
					if (
						$type->isConstantValue()->yes()
						&& $type->isTrue()->or($type->isFalse())->no()
					) {
						return $type->generalize(GeneralizePrecision::lessSpecific());
					}

					return $type;
				}, $this->getSortedTypes()));

				if ($types instanceof UnionType) {
					return $joinTypes($types->getSortedTypes());
				}

				return $joinTypes([$types]);
			},
			fn (): string => $joinTypes($this->getSortedTypes()),
		);
	}

	/**
	 * @param callable(Type $type): TrinaryLogic $canCallback
	 * @param callable(Type $type): TrinaryLogic $hasCallback
	 */
	private function hasInternal(
		callable $canCallback,
		callable $hasCallback,
	): TrinaryLogic
	{
		self::$stats["hasInternal"] = (self::$stats["hasInternal"] ?? 0) + 1;
		return TrinaryLogic::lazyExtremeIdentity($this->types, static function (Type $type) use ($canCallback, $hasCallback): TrinaryLogic {
			if ($canCallback($type)->no()) {
				return TrinaryLogic::createNo();
			}

			return $hasCallback($type);
		});
	}

	/**
	 * @template TObject of object
	 * @param callable(Type $type): TrinaryLogic $hasCallback
	 * @param callable(Type $type): TObject $getCallback
	 * @return TObject
	 */
	private function getInternal(
		callable $hasCallback,
		callable $getCallback,
	): object
	{
		self::$stats["getInternal"] = (self::$stats["getInternal"] ?? 0) + 1;
		/** @var TrinaryLogic|null $result */
		$result = null;

		/** @var TObject|null $object */
		$object = null;
		foreach ($this->types as $type) {
			$has = $hasCallback($type);
			if (!$has->yes()) {
				continue;
			}
			if ($result !== null && $result->compareTo($has) !== $has) {
				continue;
			}

			$get = $getCallback($type);
			$result = $has;
			$object = $get;
		}

		if ($object === null) {
			throw new ShouldNotHappenException();
		}

		return $object;
	}

	public function getTemplateType(string $ancestorClassName, string $templateTypeName): Type
	{
		self::$stats["getTemplateType"] = (self::$stats["getTemplateType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getTemplateType($ancestorClassName, $templateTypeName));
	}

	public function isObject(): TrinaryLogic
	{
		self::$stats["isObject"] = (self::$stats["isObject"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isObject());
	}

	public function getClassStringType(): Type
	{
		self::$stats["getClassStringType"] = (self::$stats["getClassStringType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getClassStringType());
	}

	public function isEnum(): TrinaryLogic
	{
		self::$stats["isEnum"] = (self::$stats["isEnum"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isEnum());
	}

	public function canAccessProperties(): TrinaryLogic
	{
		self::$stats["canAccessProperties"] = (self::$stats["canAccessProperties"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->canAccessProperties());
	}

	public function hasProperty(string $propertyName): TrinaryLogic
	{
		self::$stats["hasProperty"] = (self::$stats["hasProperty"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->hasProperty($propertyName));
	}

	public function getProperty(string $propertyName, ClassMemberAccessAnswerer $scope): ExtendedPropertyReflection
	{
		self::$stats["getProperty"] = (self::$stats["getProperty"] ?? 0) + 1;
		return $this->getUnresolvedPropertyPrototype($propertyName, $scope)->getTransformedProperty();
	}

	public function getUnresolvedPropertyPrototype(string $propertyName, ClassMemberAccessAnswerer $scope): UnresolvedPropertyPrototypeReflection
	{
		self::$stats["getUnresolvedPropertyPrototype"] = (self::$stats["getUnresolvedPropertyPrototype"] ?? 0) + 1;
		$propertyPrototypes = [];
		foreach ($this->types as $type) {
			if (!$type->hasProperty($propertyName)->yes()) {
				continue;
			}

			$propertyPrototypes[] = $type->getUnresolvedPropertyPrototype($propertyName, $scope)->withFechedOnType($this);
		}

		$propertiesCount = count($propertyPrototypes);
		if ($propertiesCount === 0) {
			throw new MissingPropertyFromReflectionException($this->describe(VerbosityLevel::typeOnly()), $propertyName);
		}

		if ($propertiesCount === 1) {
			return $propertyPrototypes[0];
		}

		return new UnionTypeUnresolvedPropertyPrototypeReflection($propertyPrototypes);
	}

	public function hasInstanceProperty(string $propertyName): TrinaryLogic
	{
		self::$stats["hasInstanceProperty"] = (self::$stats["hasInstanceProperty"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->hasInstanceProperty($propertyName));
	}

	public function getInstanceProperty(string $propertyName, ClassMemberAccessAnswerer $scope): ExtendedPropertyReflection
	{
		self::$stats["getInstanceProperty"] = (self::$stats["getInstanceProperty"] ?? 0) + 1;
		return $this->getUnresolvedInstancePropertyPrototype($propertyName, $scope)->getTransformedProperty();
	}

	public function getUnresolvedInstancePropertyPrototype(string $propertyName, ClassMemberAccessAnswerer $scope): UnresolvedPropertyPrototypeReflection
	{
		self::$stats["getUnresolvedInstancePropertyPrototype"] = (self::$stats["getUnresolvedInstancePropertyPrototype"] ?? 0) + 1;
		$propertyPrototypes = [];
		foreach ($this->types as $type) {
			if (!$type->hasInstanceProperty($propertyName)->yes()) {
				continue;
			}

			$propertyPrototypes[] = $type->getUnresolvedInstancePropertyPrototype($propertyName, $scope)->withFechedOnType($this);
		}

		$propertiesCount = count($propertyPrototypes);
		if ($propertiesCount === 0) {
			throw new MissingPropertyFromReflectionException($this->describe(VerbosityLevel::typeOnly()), $propertyName);
		}

		if ($propertiesCount === 1) {
			return $propertyPrototypes[0];
		}

		return new UnionTypeUnresolvedPropertyPrototypeReflection($propertyPrototypes);
	}

	public function hasStaticProperty(string $propertyName): TrinaryLogic
	{
		self::$stats["hasStaticProperty"] = (self::$stats["hasStaticProperty"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->hasStaticProperty($propertyName));
	}

	public function getStaticProperty(string $propertyName, ClassMemberAccessAnswerer $scope): ExtendedPropertyReflection
	{
		self::$stats["getStaticProperty"] = (self::$stats["getStaticProperty"] ?? 0) + 1;
		return $this->getUnresolvedStaticPropertyPrototype($propertyName, $scope)->getTransformedProperty();
	}

	public function getUnresolvedStaticPropertyPrototype(string $propertyName, ClassMemberAccessAnswerer $scope): UnresolvedPropertyPrototypeReflection
	{
		self::$stats["getUnresolvedStaticPropertyPrototype"] = (self::$stats["getUnresolvedStaticPropertyPrototype"] ?? 0) + 1;
		$propertyPrototypes = [];
		foreach ($this->types as $type) {
			if (!$type->hasStaticProperty($propertyName)->yes()) {
				continue;
			}

			$propertyPrototypes[] = $type->getUnresolvedStaticPropertyPrototype($propertyName, $scope)->withFechedOnType($this);
		}

		$propertiesCount = count($propertyPrototypes);
		if ($propertiesCount === 0) {
			throw new MissingPropertyFromReflectionException($this->describe(VerbosityLevel::typeOnly()), $propertyName);
		}

		if ($propertiesCount === 1) {
			return $propertyPrototypes[0];
		}

		return new UnionTypeUnresolvedPropertyPrototypeReflection($propertyPrototypes);
	}

	public function canCallMethods(): TrinaryLogic
	{
		self::$stats["canCallMethods"] = (self::$stats["canCallMethods"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->canCallMethods());
	}

	public function hasMethod(string $methodName): TrinaryLogic
	{
		self::$stats["hasMethod"] = (self::$stats["hasMethod"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->hasMethod($methodName));
	}

	public function getMethod(string $methodName, ClassMemberAccessAnswerer $scope): ExtendedMethodReflection
	{
		self::$stats["getMethod"] = (self::$stats["getMethod"] ?? 0) + 1;
		return $this->getUnresolvedMethodPrototype($methodName, $scope)->getTransformedMethod();
	}

	public function getUnresolvedMethodPrototype(string $methodName, ClassMemberAccessAnswerer $scope): UnresolvedMethodPrototypeReflection
	{
		self::$stats["getUnresolvedMethodPrototype"] = (self::$stats["getUnresolvedMethodPrototype"] ?? 0) + 1;
		$methodPrototypes = [];
		foreach ($this->types as $type) {
			if (!$type->hasMethod($methodName)->yes()) {
				continue;
			}

			$prototype = $type->getUnresolvedMethodPrototype($methodName, $scope);
			if ($this instanceof TemplateType) {
				$prototype = $prototype->withCalledOnType($this);
			}
			$methodPrototypes[] = $prototype;
		}

		$methodsCount = count($methodPrototypes);
		if ($methodsCount === 0) {
			throw new MissingMethodFromReflectionException($this->describe(VerbosityLevel::typeOnly()), $methodName);
		}

		if ($methodsCount === 1) {
			return $methodPrototypes[0];
		}

		return new UnionTypeUnresolvedMethodPrototypeReflection($methodName, $methodPrototypes);
	}

	public function canAccessConstants(): TrinaryLogic
	{
		self::$stats["canAccessConstants"] = (self::$stats["canAccessConstants"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->canAccessConstants());
	}

	public function hasConstant(string $constantName): TrinaryLogic
	{
		self::$stats["hasConstant"] = (self::$stats["hasConstant"] ?? 0) + 1;
		return $this->hasInternal(
			static fn (Type $type): TrinaryLogic => $type->canAccessConstants(),
			static fn (Type $type): TrinaryLogic => $type->hasConstant($constantName),
		);
	}

	public function getConstant(string $constantName): ClassConstantReflection
	{
		self::$stats["getConstant"] = (self::$stats["getConstant"] ?? 0) + 1;
		return $this->getInternal(
			static fn (Type $type): TrinaryLogic => $type->hasConstant($constantName),
			static fn (Type $type): ClassConstantReflection => $type->getConstant($constantName),
		);
	}

	public function isIterable(): TrinaryLogic
	{
		self::$stats["isIterable"] = (self::$stats["isIterable"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isIterable());
	}

	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		self::$stats["isIterableAtLeastOnce"] = (self::$stats["isIterableAtLeastOnce"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isIterableAtLeastOnce());
	}

	public function getArraySize(): Type
	{
		self::$stats["getArraySize"] = (self::$stats["getArraySize"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getArraySize());
	}

	public function getIterableKeyType(): Type
	{
		self::$stats["getIterableKeyType"] = (self::$stats["getIterableKeyType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableKeyType());
	}

	public function getFirstIterableKeyType(): Type
	{
		self::$stats["getFirstIterableKeyType"] = (self::$stats["getFirstIterableKeyType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableKeyType());
	}

	public function getLastIterableKeyType(): Type
	{
		self::$stats["getLastIterableKeyType"] = (self::$stats["getLastIterableKeyType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableKeyType());
	}

	public function getIterableValueType(): Type
	{
		self::$stats["getIterableValueType"] = (self::$stats["getIterableValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableValueType());
	}

	public function getFirstIterableValueType(): Type
	{
		self::$stats["getFirstIterableValueType"] = (self::$stats["getFirstIterableValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableValueType());
	}

	public function getLastIterableValueType(): Type
	{
		self::$stats["getLastIterableValueType"] = (self::$stats["getLastIterableValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getIterableValueType());
	}

	public function isArray(): TrinaryLogic
	{
		self::$stats["isArray"] = (self::$stats["isArray"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isArray());
	}

	public function isConstantArray(): TrinaryLogic
	{
		self::$stats["isConstantArray"] = (self::$stats["isConstantArray"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isConstantArray());
	}

	public function isOversizedArray(): TrinaryLogic
	{
		self::$stats["isOversizedArray"] = (self::$stats["isOversizedArray"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isOversizedArray());
	}

	public function isList(): TrinaryLogic
	{
		self::$stats["isList"] = (self::$stats["isList"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isList());
	}

	public function isString(): TrinaryLogic
	{
		self::$stats["isString"] = (self::$stats["isString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isString());
	}

	public function isNumericString(): TrinaryLogic
	{
		self::$stats["isNumericString"] = (self::$stats["isNumericString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isNumericString());
	}

	public function isDecimalIntegerString(): TrinaryLogic
	{
		self::$stats["isDecimalIntegerString"] = (self::$stats["isDecimalIntegerString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isDecimalIntegerString());
	}

	public function isNonEmptyString(): TrinaryLogic
	{
		self::$stats["isNonEmptyString"] = (self::$stats["isNonEmptyString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isNonEmptyString());
	}

	public function isNonFalsyString(): TrinaryLogic
	{
		self::$stats["isNonFalsyString"] = (self::$stats["isNonFalsyString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isNonFalsyString());
	}

	public function isLiteralString(): TrinaryLogic
	{
		self::$stats["isLiteralString"] = (self::$stats["isLiteralString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isLiteralString());
	}

	public function isLowercaseString(): TrinaryLogic
	{
		self::$stats["isLowercaseString"] = (self::$stats["isLowercaseString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isLowercaseString());
	}

	public function isUppercaseString(): TrinaryLogic
	{
		self::$stats["isUppercaseString"] = (self::$stats["isUppercaseString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isUppercaseString());
	}

	public function isClassString(): TrinaryLogic
	{
		self::$stats["isClassString"] = (self::$stats["isClassString"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isClassString());
	}

	public function getClassStringObjectType(): Type
	{
		self::$stats["getClassStringObjectType"] = (self::$stats["getClassStringObjectType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getClassStringObjectType());
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		self::$stats["getObjectTypeOrClassStringObjectType"] = (self::$stats["getObjectTypeOrClassStringObjectType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getObjectTypeOrClassStringObjectType());
	}

	public function isVoid(): TrinaryLogic
	{
		self::$stats["isVoid"] = (self::$stats["isVoid"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isVoid());
	}

	public function isScalar(): TrinaryLogic
	{
		self::$stats["isScalar"] = (self::$stats["isScalar"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isScalar());
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		self::$stats["looseCompare"] = (self::$stats["looseCompare"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(
			static fn (Type $innerType): TrinaryLogic => $innerType->looseCompare($type, $phpVersion)->toTrinaryLogic(),
		)->toBooleanType();
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		self::$stats["isOffsetAccessible"] = (self::$stats["isOffsetAccessible"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isOffsetAccessible());
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		self::$stats["isOffsetAccessLegal"] = (self::$stats["isOffsetAccessLegal"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isOffsetAccessLegal());
	}

	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		self::$stats["hasOffsetValueType"] = (self::$stats["hasOffsetValueType"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->hasOffsetValueType($offsetType));
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		self::$stats["getOffsetValueType"] = (self::$stats["getOffsetValueType"] ?? 0) + 1;
		$types = [];
		foreach ($this->types as $innerType) {
			$valueType = $innerType->getOffsetValueType($offsetType);
			if ($valueType instanceof ErrorType) {
				continue;
			}

			$types[] = $valueType;
		}

		if (count($types) === 0) {
			return new ErrorType();
		}

		return TypeCombinator::union(...$types);
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType, bool $unionValues = true): Type
	{
		self::$stats["setOffsetValueType"] = (self::$stats["setOffsetValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->setOffsetValueType($offsetType, $valueType, $unionValues));
	}

	public function setExistingOffsetValueType(Type $offsetType, Type $valueType): Type
	{
		self::$stats["setExistingOffsetValueType"] = (self::$stats["setExistingOffsetValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->setExistingOffsetValueType($offsetType, $valueType));
	}

	public function unsetOffset(Type $offsetType): Type
	{
		self::$stats["unsetOffset"] = (self::$stats["unsetOffset"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->unsetOffset($offsetType));
	}

	public function getKeysArrayFiltered(Type $filterValueType, TrinaryLogic $strict): Type
	{
		self::$stats["getKeysArrayFiltered"] = (self::$stats["getKeysArrayFiltered"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getKeysArrayFiltered($filterValueType, $strict));
	}

	public function getKeysArray(): Type
	{
		self::$stats["getKeysArray"] = (self::$stats["getKeysArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getKeysArray());
	}

	public function getValuesArray(): Type
	{
		self::$stats["getValuesArray"] = (self::$stats["getValuesArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getValuesArray());
	}

	public function chunkArray(Type $lengthType, TrinaryLogic $preserveKeys): Type
	{
		self::$stats["chunkArray"] = (self::$stats["chunkArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->chunkArray($lengthType, $preserveKeys));
	}

	public function fillKeysArray(Type $valueType): Type
	{
		self::$stats["fillKeysArray"] = (self::$stats["fillKeysArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->fillKeysArray($valueType));
	}

	public function flipArray(): Type
	{
		self::$stats["flipArray"] = (self::$stats["flipArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->flipArray());
	}

	public function intersectKeyArray(Type $otherArraysType): Type
	{
		self::$stats["intersectKeyArray"] = (self::$stats["intersectKeyArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->intersectKeyArray($otherArraysType));
	}

	public function popArray(): Type
	{
		self::$stats["popArray"] = (self::$stats["popArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->popArray());
	}

	public function reverseArray(TrinaryLogic $preserveKeys): Type
	{
		self::$stats["reverseArray"] = (self::$stats["reverseArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->reverseArray($preserveKeys));
	}

	public function searchArray(Type $needleType, ?TrinaryLogic $strict = null): Type
	{
		self::$stats["searchArray"] = (self::$stats["searchArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->searchArray($needleType, $strict));
	}

	public function shiftArray(): Type
	{
		self::$stats["shiftArray"] = (self::$stats["shiftArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->shiftArray());
	}

	public function shuffleArray(): Type
	{
		self::$stats["shuffleArray"] = (self::$stats["shuffleArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->shuffleArray());
	}

	public function sliceArray(Type $offsetType, Type $lengthType, TrinaryLogic $preserveKeys): Type
	{
		self::$stats["sliceArray"] = (self::$stats["sliceArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->sliceArray($offsetType, $lengthType, $preserveKeys));
	}

	public function spliceArray(Type $offsetType, Type $lengthType, Type $replacementType): Type
	{
		self::$stats["spliceArray"] = (self::$stats["spliceArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->spliceArray($offsetType, $lengthType, $replacementType));
	}

	public function truncateListToSize(Type $sizeType): Type
	{
		self::$stats["truncateListToSize"] = (self::$stats["truncateListToSize"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->truncateListToSize($sizeType));
	}

	public function makeListMaybe(): Type
	{
		self::$stats["makeListMaybe"] = (self::$stats["makeListMaybe"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->makeListMaybe());
	}

	public function mapValueType(callable $cb): Type
	{
		self::$stats["mapValueType"] = (self::$stats["mapValueType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->mapValueType($cb));
	}

	public function mapKeyType(callable $cb): Type
	{
		self::$stats["mapKeyType"] = (self::$stats["mapKeyType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->mapKeyType($cb));
	}

	public function makeAllArrayKeysOptional(): Type
	{
		self::$stats["makeAllArrayKeysOptional"] = (self::$stats["makeAllArrayKeysOptional"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->makeAllArrayKeysOptional());
	}

	public function changeKeyCaseArray(?int $case): Type
	{
		self::$stats["changeKeyCaseArray"] = (self::$stats["changeKeyCaseArray"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->changeKeyCaseArray($case));
	}

	public function filterArrayRemovingFalsey(): Type
	{
		self::$stats["filterArrayRemovingFalsey"] = (self::$stats["filterArrayRemovingFalsey"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->filterArrayRemovingFalsey());
	}

	public function getEnumCases(): array
	{
		self::$stats["getEnumCases"] = (self::$stats["getEnumCases"] ?? 0) + 1;
		return $this->pickFromTypes(
			static fn (Type $type) => $type->getEnumCases(),
			static fn (Type $type) => $type->isObject()->yes(),
		);
	}

	public function getEnumCaseObject(): ?EnumCaseObjectType
	{
		self::$stats["getEnumCaseObject"] = (self::$stats["getEnumCaseObject"] ?? 0) + 1;
		$cases = $this->getEnumCases();

		if (count($cases) === 1) {
			return $cases[0];
		}

		return null;
	}

	public function isCallable(): TrinaryLogic
	{
		self::$stats["isCallable"] = (self::$stats["isCallable"] ?? 0) + 1;
		return $this->isCallable ??= $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isCallable());
	}

	public function getCallableParametersAcceptors(ClassMemberAccessAnswerer $scope): array
	{
		self::$stats["getCallableParametersAcceptors"] = (self::$stats["getCallableParametersAcceptors"] ?? 0) + 1;
		$acceptors = [];

		foreach ($this->types as $type) {
			if ($type->isCallable()->no()) {
				continue;
			}

			$acceptors = array_merge($acceptors, $type->getCallableParametersAcceptors($scope));
		}

		if (count($acceptors) === 0) {
			throw new ShouldNotHappenException();
		}

		return $acceptors;
	}

	public function isCloneable(): TrinaryLogic
	{
		self::$stats["isCloneable"] = (self::$stats["isCloneable"] ?? 0) + 1;
		return $this->unionResults(static fn (Type $type): TrinaryLogic => $type->isCloneable());
	}

	public function isSmallerThan(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		self::$stats["isSmallerThan"] = (self::$stats["isSmallerThan"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isSmallerThan($otherType, $phpVersion));
	}

	public function isSmallerThanOrEqual(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		self::$stats["isSmallerThanOrEqual"] = (self::$stats["isSmallerThanOrEqual"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isSmallerThanOrEqual($otherType, $phpVersion));
	}

	public function isNull(): TrinaryLogic
	{
		self::$stats["isNull"] = (self::$stats["isNull"] ?? 0) + 1;
		return $this->isNull ??= $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isNull());
	}

	public function isConstantValue(): TrinaryLogic
	{
		self::$stats["isConstantValue"] = (self::$stats["isConstantValue"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isConstantValue());
	}

	public function isConstantScalarValue(): TrinaryLogic
	{
		self::$stats["isConstantScalarValue"] = (self::$stats["isConstantScalarValue"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isConstantScalarValue());
	}

	public function getConstantScalarTypes(): array
	{
		self::$stats["getConstantScalarTypes"] = (self::$stats["getConstantScalarTypes"] ?? 0) + 1;
		return $this->notBenevolentPickFromTypes(static fn (Type $type) => $type->getConstantScalarTypes());
	}

	public function getConstantScalarValues(): array
	{
		self::$stats["getConstantScalarValues"] = (self::$stats["getConstantScalarValues"] ?? 0) + 1;
		return $this->notBenevolentPickFromTypes(static fn (Type $type) => $type->getConstantScalarValues());
	}

	public function isTrue(): TrinaryLogic
	{
		self::$stats["isTrue"] = (self::$stats["isTrue"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isTrue());
	}

	public function isFalse(): TrinaryLogic
	{
		self::$stats["isFalse"] = (self::$stats["isFalse"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isFalse());
	}

	public function isBoolean(): TrinaryLogic
	{
		self::$stats["isBoolean"] = (self::$stats["isBoolean"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isBoolean());
	}

	public function isFloat(): TrinaryLogic
	{
		self::$stats["isFloat"] = (self::$stats["isFloat"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isFloat());
	}

	public function isInteger(): TrinaryLogic
	{
		self::$stats["isInteger"] = (self::$stats["isInteger"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $type->isInteger());
	}

	public function getSmallerType(PhpVersion $phpVersion): Type
	{
		self::$stats["getSmallerType"] = (self::$stats["getSmallerType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getSmallerType($phpVersion));
	}

	public function getSmallerOrEqualType(PhpVersion $phpVersion): Type
	{
		self::$stats["getSmallerOrEqualType"] = (self::$stats["getSmallerOrEqualType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getSmallerOrEqualType($phpVersion));
	}

	public function getGreaterType(PhpVersion $phpVersion): Type
	{
		self::$stats["getGreaterType"] = (self::$stats["getGreaterType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getGreaterType($phpVersion));
	}

	public function getGreaterOrEqualType(PhpVersion $phpVersion): Type
	{
		self::$stats["getGreaterOrEqualType"] = (self::$stats["getGreaterOrEqualType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->getGreaterOrEqualType($phpVersion));
	}

	public function isGreaterThan(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		self::$stats["isGreaterThan"] = (self::$stats["isGreaterThan"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $otherType->isSmallerThan($type, $phpVersion));
	}

	public function isGreaterThanOrEqual(Type $otherType, PhpVersion $phpVersion): TrinaryLogic
	{
		self::$stats["isGreaterThanOrEqual"] = (self::$stats["isGreaterThanOrEqual"] ?? 0) + 1;
		return $this->notBenevolentUnionResults(static fn (Type $type): TrinaryLogic => $otherType->isSmallerThanOrEqual($type, $phpVersion));
	}

	public function toBoolean(): BooleanType
	{
		self::$stats["toBoolean"] = (self::$stats["toBoolean"] ?? 0) + 1;
		/** @var BooleanType $type */
		$type = $this->unionTypes(static fn (Type $type): BooleanType => $type->toBoolean());

		return $type;
	}

	public function toNumber(): Type
	{
		self::$stats["toNumber"] = (self::$stats["toNumber"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toNumber());

		return $type;
	}

	public function toBitwiseNotType(): Type
	{
		self::$stats["toBitwiseNotType"] = (self::$stats["toBitwiseNotType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->toBitwiseNotType());
	}

	public function toGetClassResultType(): Type
	{
		self::$stats["toGetClassResultType"] = (self::$stats["toGetClassResultType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->toGetClassResultType());
	}

	public function toClassConstantType(ReflectionProvider $reflectionProvider): Type
	{
		self::$stats["toClassConstantType"] = (self::$stats["toClassConstantType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->toClassConstantType($reflectionProvider));
	}

	public function toObjectTypeForInstanceofCheck(): ClassNameToObjectTypeResult
	{
		self::$stats["toObjectTypeForInstanceofCheck"] = (self::$stats["toObjectTypeForInstanceofCheck"] ?? 0) + 1;
		$types = [];
		$uncertainty = false;
		foreach ($this->getTypes() as $innerType) {
			$result = $innerType->toObjectTypeForInstanceofCheck();
			$types[] = $result->type;
			if (!$result->uncertainty) {
				continue;
			}

			$uncertainty = true;
		}

		return new ClassNameToObjectTypeResult(TypeCombinator::union(...$types), $uncertainty);
	}

	public function toObjectTypeForIsACheck(Type $objectOrClassType, bool $allowString, bool $allowSameClass): ClassNameToObjectTypeResult
	{
		self::$stats["toObjectTypeForIsACheck"] = (self::$stats["toObjectTypeForIsACheck"] ?? 0) + 1;
		$types = [];
		$uncertainty = false;
		foreach ($this->getTypes() as $innerType) {
			$result = $innerType->toObjectTypeForIsACheck($objectOrClassType, $allowString, $allowSameClass);
			$types[] = $result->type;
			if (!$result->uncertainty) {
				continue;
			}

			$uncertainty = true;
		}

		return new ClassNameToObjectTypeResult(TypeCombinator::union(...$types), $uncertainty);
	}

	public function toAbsoluteNumber(): Type
	{
		self::$stats["toAbsoluteNumber"] = (self::$stats["toAbsoluteNumber"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toAbsoluteNumber());

		return $type;
	}

	public function toString(): Type
	{
		self::$stats["toString"] = (self::$stats["toString"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toString());

		return $type;
	}

	public function toInteger(): Type
	{
		self::$stats["toInteger"] = (self::$stats["toInteger"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toInteger());

		return $type;
	}

	public function toFloat(): Type
	{
		self::$stats["toFloat"] = (self::$stats["toFloat"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toFloat());

		return $type;
	}

	public function toArray(): Type
	{
		self::$stats["toArray"] = (self::$stats["toArray"] ?? 0) + 1;
		$type = $this->unionTypes(static fn (Type $type): Type => $type->toArray());

		return $type;
	}

	public function toArrayKey(): Type
	{
		self::$stats["toArrayKey"] = (self::$stats["toArrayKey"] ?? 0) + 1;
		$level = ReportUnsafeArrayStringKeyCastingToggle::getLevel();
		if ($level !== ReportUnsafeArrayStringKeyCastingToggle::PREVENT || $this->isInteger()->no()) {
			return $this->unionTypes(static fn (Type $type): Type => $type->toArrayKey());
		}

		return $this->unionTypes(static function (Type $type): Type {
			if ($type instanceof StringType) { // @phpstan-ignore phpstanApi.instanceofType
				return $type;
			}

			return $type->toArrayKey();
		});
	}

	public function toCoercedArgumentType(bool $strictTypes): Type
	{
		self::$stats["toCoercedArgumentType"] = (self::$stats["toCoercedArgumentType"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->toCoercedArgumentType($strictTypes));
	}

	public function inferTemplateTypes(Type $receivedType): TemplateTypeMap
	{
		self::$stats["inferTemplateTypes"] = (self::$stats["inferTemplateTypes"] ?? 0) + 1;
		if ($receivedType instanceof IterableType) {
			$receivedType = $receivedType->toArrayOrTraversable();
		}

		$types = TemplateTypeMap::createEmpty();
		if ($receivedType instanceof UnionType) {
			$myTypes = [];
			$remainingReceivedTypes = [];
			foreach ($receivedType->getTypes() as $receivedInnerType) {
				foreach ($this->types as $type) {
					if ($type->isSuperTypeOf($receivedInnerType)->yes()) {
						$types = $types->union($type->inferTemplateTypes($receivedInnerType));
						continue 2;
					}
					$myTypes[] = $type;
				}
				$remainingReceivedTypes[] = $receivedInnerType;
			}
			if (count($remainingReceivedTypes) === 0) {
				return $types;
			}
			$receivedType = TypeCombinator::union(...$remainingReceivedTypes);
		} else {
			$myTypes = $this->types;
		}

		foreach ($myTypes as $type) {
			if ($type instanceof TemplateType || ($type instanceof GenericClassStringType && $type->getGenericType() instanceof TemplateType)) {
				continue;
			}
			$types = $types->union($type->inferTemplateTypes($receivedType));
		}

		if (!$types->isEmpty()) {
			return $types;
		}

		foreach ($myTypes as $type) {
			$types = $types->union($type->inferTemplateTypes($receivedType));
		}

		return $types;
	}

	public function inferTemplateTypesOn(Type $templateType): TemplateTypeMap
	{
		self::$stats["inferTemplateTypesOn"] = (self::$stats["inferTemplateTypesOn"] ?? 0) + 1;
		$types = TemplateTypeMap::createEmpty();

		foreach ($this->types as $type) {
			$types = $types->union($templateType->inferTemplateTypes($type));
		}

		return $types;
	}

	public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
	{
		self::$stats["getReferencedTemplateTypes"] = (self::$stats["getReferencedTemplateTypes"] ?? 0) + 1;
		$references = [];

		foreach ($this->types as $type) {
			foreach ($type->getReferencedTemplateTypes($positionVariance) as $reference) {
				$references[] = $reference;
			}
		}

		return $references;
	}

	public function traverse(callable $cb): Type
	{
		self::$stats["traverse"] = (self::$stats["traverse"] ?? 0) + 1;
		$types = [];
		$changed = false;

		foreach ($this->types as $type) {
			$newType = $cb($type);
			if ($type !== $newType) {
				$changed = true;
			}
			$types[] = $newType;
		}

		if ($changed) {
			return TypeCombinator::union(...$types);
		}

		return $this;
	}

	public function traverseSimultaneously(Type $right, callable $cb): Type
	{
		self::$stats["traverseSimultaneously"] = (self::$stats["traverseSimultaneously"] ?? 0) + 1;
		$rightTypes = TypeUtils::flattenTypes($right);
		$newTypes = [];
		$changed = false;
		foreach ($this->types as $innerType) {
			$candidates = [];
			foreach ($rightTypes as $i => $rightType) {
				if (!$innerType->isSuperTypeOf($rightType)->yes()) {
					continue;
				}

				$candidates[] = $rightType;
				unset($rightTypes[$i]);
			}

			if (count($candidates) === 0) {
				$newTypes[] = $innerType;
				continue;
			}

			$newType = $cb($innerType, TypeCombinator::union(...$candidates));
			if ($innerType !== $newType) {
				$changed = true;
			}

			$newTypes[] = $newType;
		}

		if ($changed) {
			return TypeCombinator::union(...$newTypes);
		}

		return $this;
	}

	public function tryRemove(Type $typeToRemove): ?Type
	{
		self::$stats["tryRemove"] = (self::$stats["tryRemove"] ?? 0) + 1;
		$finiteTypeSet = $this->getFiniteTypeSet();
		if ($finiteTypeSet !== null && $finiteTypeSet->isComplete()) {
			$key = FiniteTypeSet::key($typeToRemove);
			if ($key !== null) {
				if (!$finiteTypeSet->has($key)) {
					return null;
				}

				$remainingTypes = [];
				foreach ($finiteTypeSet->getMembers() as $memberKey => $member) {
					if ($memberKey === $key) {
						continue;
					}

					$remainingTypes[] = $member;
				}

				if (count($remainingTypes) === 1) {
					return $remainingTypes[0];
				}

				return new UnionType($remainingTypes);
			}
		}

		$innerTypes = [];
		$changed = false;
		foreach ($this->types as $innerType) {
			$removed = TypeCombinator::remove($innerType, $typeToRemove);
			if (!$removed->equals($innerType)) {
				$changed = true;
			}
			if ($removed instanceof NeverType) {
				continue;
			}
			if ($removed instanceof self && !$removed instanceof TemplateType) {
				foreach ($removed->getTypes() as $removedInnerType) {
					$innerTypes[] = $removedInnerType;
				}
			} else {
				$innerTypes[] = $removed;
			}
		}

		if (!$changed) {
			return null;
		}

		if (count($innerTypes) === 0) {
			return new NeverType();
		}

		if (count($innerTypes) === 1) {
			return $innerTypes[0];
		}

		return new UnionType($innerTypes);
	}

	public function exponentiate(Type $exponent): Type
	{
		self::$stats["exponentiate"] = (self::$stats["exponentiate"] ?? 0) + 1;
		return $this->unionTypes(static fn (Type $type): Type => $type->exponentiate($exponent));
	}

	public function getFiniteTypes(): array
	{
		self::$stats["getFiniteTypes"] = (self::$stats["getFiniteTypes"] ?? 0) + 1;
		if ($this->finiteTypes !== null) {
			return $this->finiteTypes;
		}

		$types = $this->notBenevolentPickFromTypes(static fn (Type $type) => $type->getFiniteTypes());
		$uniquedTypes = [];
		foreach ($types as $type) {
			$uniquedTypes[$type->describe(VerbosityLevel::cache())] = $type;
		}

		if (count($uniquedTypes) > InitializerExprTypeResolver::CALCULATE_SCALARS_LIMIT) {
			return $this->finiteTypes = [];
		}

		return $this->finiteTypes = array_values($uniquedTypes);
	}

	/**
	 * @param callable(Type $type): TrinaryLogic $getResult
	 */
	protected function unionResults(callable $getResult): TrinaryLogic
	{
		self::$stats["unionResults"] = (self::$stats["unionResults"] ?? 0) + 1;
		return TrinaryLogic::lazyExtremeIdentity($this->types, $getResult);
	}

	/**
	 * @param callable(Type $type): TrinaryLogic $getResult
	 */
	private function notBenevolentUnionResults(callable $getResult): TrinaryLogic
	{
		self::$stats["notBenevolentUnionResults"] = (self::$stats["notBenevolentUnionResults"] ?? 0) + 1;
		return TrinaryLogic::lazyExtremeIdentity($this->types, $getResult);
	}

	/**
	 * @param callable(Type $type): Type $getType
	 */
	protected function unionTypes(callable $getType): Type
	{
		self::$stats["unionTypes"] = (self::$stats["unionTypes"] ?? 0) + 1;
		$newTypes = [];
		$changed = false;
		foreach ($this->types as $type) {
			$newType = $getType($type);
			if ($newType !== $type) {
				$changed = true;
			}
			$newTypes[] = $newType;
		}

		if (!$changed) {
			return $this;
		}

		return TypeCombinator::union(...$newTypes);
	}

	/**
	 * @template T
	 * @param callable(Type $type): list<T> $getValues
	 * @param callable(Type $type): bool $criteria
	 * @return list<T>
	 */
	protected function pickFromTypes(
		callable $getValues,
		callable $criteria,
	): array
	{
		self::$stats["pickFromTypes"] = (self::$stats["pickFromTypes"] ?? 0) + 1;
		$values = [];
		foreach ($this->types as $type) {
			$innerValues = $getValues($type);
			if ($innerValues === []) {
				return [];
			}

			foreach ($innerValues as $innerType) {
				$values[] = $innerType;
			}
		}

		return $values;
	}

	public function toPhpDocNode(): TypeNode
	{
		self::$stats["toPhpDocNode"] = (self::$stats["toPhpDocNode"] ?? 0) + 1;
		return new UnionTypeNode(array_map(static fn (Type $type) => $type->toPhpDocNode(), $this->getSortedTypes()));
	}

	/**
	 * @template T
	 * @param callable(Type $type): list<T> $getValues
	 * @return list<T>
	 */
	private function notBenevolentPickFromTypes(callable $getValues): array
	{
		self::$stats["notBenevolentPickFromTypes"] = (self::$stats["notBenevolentPickFromTypes"] ?? 0) + 1;
		$values = [];
		foreach ($this->types as $type) {
			$innerValues = $getValues($type);
			if ($innerValues === []) {
				return [];
			}

			foreach ($innerValues as $innerType) {
				$values[] = $innerType;
			}
		}

		return $values;
	}

	public function hasTemplateOrLateResolvableType(): bool
	{
		self::$stats["hasTemplateOrLateResolvableType"] = (self::$stats["hasTemplateOrLateResolvableType"] ?? 0) + 1;
		foreach ($this->types as $type) {
			if (!$type->hasTemplateOrLateResolvableType()) {
				continue;
			}

			return true;
		}

		return false;
	}

}
