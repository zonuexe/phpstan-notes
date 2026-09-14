# A Comprehensive Analysis of Range Syntax and Boundary Semantics in Programming Languages for Complex Data Modeling

## 1. Introduction: The Mathematical Nature of Intervals and the Complexity Introduced by Data-Type Boundaries

The ability to express a "range" or "interval" is a core concept in programming, used everywhere from array slicing, database query construction, and iteration (loops) to advanced scientific computing. Yet this apparently simple concept reveals its true complexity depending on whether the underlying data type is *discrete* or *continuous*.

As the question that prompted this survey points out, for discrete data such as integers, where adjacent values are clearly defined, the closed interval `[1, 5]` and the half-open interval `[1, 6)` denote mathematically identical sets of elements. Whichever form a language specification adopts, the programmer can absorb the syntactic difference by adding or subtracting `1` from the upper bound as needed. That PHP's `range(1, 5)` behaves as a closed interval while Python's `range(1, 5)` behaves as a half-open one is, in the world of discrete data, nothing more than an offset adjustment.

The moment the type is extended to continuous data such as floating-point numbers or date-times, however, this premise collapses completely. Between the intervals `[1.0, 5.0]` and `[1.0, 5.0)` lie infinitely many elements, and "the value just before 5.0" cannot be specified in a programming language by simply adding or subtracting a constant such as `+1` or `−1`[^1]. Consequently, languages, libraries, and static analysis tools must provide a way to distinguish, at the level of syntax or of types, the open interval `(a, b)`, the closed interval `[a, b]`, and the half-open intervals `[a, b)` and `(a, b]`.

This report starts from the historical background of half-open intervals in computer science and then analyses, comprehensively and in depth, how each technology — the major modern programming languages (Rust, Swift, Kotlin, Python, C#, Julia, C++, and others), static analysis tools (PHPStan and the TypeScript ecosystem), databases (PostgreSQL), and even the international standard for high-precision interval arithmetic (IEEE 1788) — resolves this "boundary problem between the continuous and the discrete".

## 2. Theoretical Foundation: Dijkstra's Half-Open Interval Principle and the Handling of Discrete Data

Behind the fact that many modern programming languages default to the half-open interval `[a, b)` for array indexing and iteration lies the strong influence of the 1982 manuscript "Why numbering should start at zero" (EWD831) by the computer scientist Edsger W. Dijkstra[^3]. Dijkstra compared four mathematical notations for denoting a subsequence of the natural numbers and argued which of them is least prone to bugs in program logic.

The four notations he examined are as follows: first, `a ≤ i < b` (half-open, closed on the left and open on the right); second, `a < i ≤ b` (half-open, open on the left and closed on the right); third, `a ≤ i ≤ b` (closed); and fourth, `a < i < b` (open)[^4]. He pointed out that with a closed interval, expressing an empty interval (a sequence with no elements) produces the unnatural state in which the upper bound falls below the lower bound (for example `0 ≤ i ≤ −1`)[^4]. He also noted that with an open lower bound, specifying a sequence that starts at the smallest natural number (zero, say) forces the use of an unnatural negative number as the lower bound (for example `−1 < i < 3`)[^4].

He therefore concluded that the half-open notation `a ≤ i < b`, including the lower bound and excluding the upper bound, is the best. This property of half-open intervals is extremely effective in avoiding the fencepost problem (off-by-one errors) and preserving logical consistency[^3]. The length of an interval is obtained by the simple subtraction `b − a`; splitting the interval `[a, c)` at an arbitrary point `b` yields `[a, b)` and `[b, c)` cleanly, with neither duplication nor omission of boundary values[^3]. Furthermore, an interval whose start and end coincide, `[a, a)`, can be intuitively treated as an empty interval of length zero[^3].

The standard C loop `for (int i = 0; i < N; i++)`, C#'s range operator, Python's slice syntax, and the like are all built on this combination of half-open interval philosophy and zero-origin indexing[^6]. These designs, however, presuppose integer arithmetic on *indices* (offsets); once continuous data comes into play, the need arises for new syntax that expresses closed and open intervals explicitly.

## 3. Interval Representation and Slicing for Discrete Data in Practice

Each language provides its own syntax or objects for manipulating discrete data (chiefly array and string indices). Observing these implementations closely shows how thoroughly the half-open interval has been optimized for operating on in-memory data structures.

### 3.1. Slicing in Python and Its Discontinuous Semantics

Python strongly supports half-open intervals through the `range(start, stop)` function, the `slice` object, and the `a[start:stop:step]` syntax[^8]. Python slicing is faithful to Dijkstra's principle: the given `stop` index is never included in the result (exclusive)[^8].

In its pursuit of convenience, however, Python's slice syntax carries a very complicated internal boundary logic. According to an analysis by Quansight Labs, the mathematical meaning of a Python slice changes discontinuously with the values of its arguments (positive, negative, or omitted)[^10]. Using a negative index, for instance, means an offset from the end (`-1` being the last element), and a negative step reverses the direction of iteration. The interval is still half-open, but the side on which the boundary is excluded switches from the right to the left[^10]. At the C API level (`PySlice_GetIndicesEx` and friends), out-of-range indices are automatically clipped to the length of the sequence so that an empty list is returned rather than an error[^10]. This is extremely robust for element lookup and extraction, but as a mathematical representation of intervals it lacks purity.

### 3.2. Dedicated Syntax in C# and Chapel

C# introduced the `..` range operator to write array and collection slices concisely[^13]. Like Python it produces a half-open interval, and combined with memory-efficient data structures such as `ReadOnlySlice` it is widely used to avoid allocating new objects when extracting substrings and the like[^14].

In the parallel computing language Chapel, `..` likewise acts as an operator that produces an interval, and in addition a clearer `..<` operator is provided to define a half-open interval[^13]. Chapel also has advanced features whereby, when an implicit conversion is allowed in an assignment between intervals, the stride (step) and alignment are carried over[^13].

### 3.3. Coexistence of Closed and Half-Open Intervals in Crystal

Crystal, a statically typed language strongly influenced by Ruby, adopts a syntax that visually distinguishes closed from half-open intervals by the number of dots in string and array slicing[^15].

| Syntax (Crystal) | Semantics | Example string slice (s = "Hello") |
| :---- | :---- | :---- |
| a..b | Closed (includes b) | s[0..1] → "He" |
| a...b | Half-open (excludes b) | s[0...1] → "H" |

Thus even in a context centred on discrete index manipulation, distinguishing by dot count has some value in making offset calculations intuitive to write. When this is applied to floating-point numbers, however, its semantic importance rises dramatically.

## 4. The Rise of Continuous Data and the Evolution of Dedicated Syntax

When the need grows to treat "continuous data (or data that behaves like it)" — floating-point numbers, dates, or lexicographic comparison of strings — as intervals, beyond integer loops and array indices, the discrete compromise of "subtract 1 from the upper bound" no longer works. Modern programming languages have redesigned their range-operator syntax to address this problem.

### 4.1. Kotlin: The Limits of `until` and the Introduction of the `..<` Operator

The evolution of Kotlin's range syntax embodies the paradigm shift from discrete to continuous data. Early Kotlin used `a..b` (the `rangeTo` function) for closed intervals and the infix function `a until b` for half-open ones[^2].

`until`, however, had a fatal flaw. The function was defined only for integer types (`Int`, `Long`, and so on), and its internal implementation simply converted to the closed interval `a .. (b - 1)`[^2]. This is precisely the discrete approach of "for integers, subtracting 1 from the upper bound suffices". The technique cannot be applied to continuous data such as strings (say, the words between "cat" and "dog") or floating-point numbers, because subtracting 1 from "dog" is not defined[^2].

To solve this, Kotlin 1.9 and later introduced the new half-open range operator `..<` (the `rangeUntil` function)[^2]. This makes it possible to construct a true half-open range (`OpenRange`) for any `Comparable` type, without any internal subtraction. As a result, expressions such as `0.0 ..< 5.0` became possible for floating-point numbers, and containment tests on continuous values could be performed accurately[^2].

### 4.2. Swift: Intuitive Dot Syntax and Generality

Swift has from the outset provided two visually distinct operators for expressing intervals and built them into the core design of the language[^18].

| Operator (Swift) | Name | Mathematical meaning | Applies to |
| :---- | :---- | :---- | :---- |
| a...b | Closed Range Operator | `[a, b]` | any `Comparable` type |
| a..<b | Half-Open Range Operator | `[a, b)` | any `Comparable` type |

Swift's strength is that these ranges target any type conforming to the `Comparable` protocol from the start. Creating the floating-point range `let underFive = 0.0..<5.0` and evaluating `underFive.contains(3.14)` returns `true`, while `contains(5.0)` returns strictly `false`[^19]. The range operators can be used as-is in pattern matching within Swift's `switch` statements, and in cases such as grading scores (e.g. `80..<90`) they eliminate boundary ambiguity entirely[^20].

### 4.3. Rust: Pattern Matching and Type Safety

Rust likewise provides two operators, but it has gone through a historical change to avoid visual ambiguity in the syntax[^5].

In early Rust, `...` was used for closed intervals, but it was deprecated because it was easy to confuse visually with the half-open `..` (miscounting dots invites fatal logic bugs). In its place, `..=`, which includes an equals sign to make inclusivity explicit, was introduced[^21].

* Half-open interval: `a..b` (`std::ops::Range`)
* Closed interval: `a..=b` (`std::ops::RangeInclusive`)

An interesting challenge with intervals in Rust arises in pattern matching on floating-point numbers (`f32`, `f64`). This is examined in depth in a later section.
## 5. Representing Intervals in Static Analysis and Type Systems

An approach has also evolved in which an interval is defined not as a runtime value but as a *type* in its own right, so that program safety can be proven at compile time or during static analysis.

### 5.1. PHPStan and Psalm: Strict Boundary Definitions for Discrete Types

PHPStan and Psalm, the powerful static analysers for PHP, apply generics syntax to offer integer intervals as their own types[^22].

In Psalm's type system the following interval types can be defined[^23]:

* `int<1, 5>`: integers from 1 to 5 (closed interval)
* `int<0, max>`: integers greater than or equal to 0 (equivalent to `non-negative-int`)
* `int<min, -1>`: negative integers (equivalent to `negative-int`)

These type systems work extremely well for discrete integers and contribute to verifying out-of-bounds array access, division by zero, and bit masks (`int-mask<1, 2, 4>`)[^23].

As the question that prompted this survey notes, however, it is rare for a static analyser to natively provide an interval type such as `float<1.0, 5.0>` for floating-point numbers. This is presumably because floating-point numbers form a continuous space with an infinite state space, and because rounding errors arise on every operation, making it computationally hard (or impossible) to deductively prove at compile time that a variable's value lies strictly within an interval[^22]. In Psalm and similar type systems, `float` is treated merely as part of the scalar supertype, and fine-grained value-range constraints are not supported[^23].

### 5.2. TypeScript and Runtime Schema Validation

In TypeScript, similarly, the ability to constrain numeric ranges natively at the type level (for example `number & Minimum & Maximum`) has long been requested but has never been implemented. The currently proposed feature `type Range = [start: number, end: number]` concerns constraints on the number of elements in a tuple type and does not specify a range of continuous values[^26].

To compensate for this limitation of static typing, the TypeScript ecosystem relies on runtime validation libraries such as ArkType, Zod, and Valibot[^28]. These take the same approach as database query builders and distinguish open from closed intervals using explicit aliases for logical comparison operators[^28]:

* gt (Greater Than): `> a` (open lower bound)
* gte (Greater Than or Equal): `≥ a` (closed lower bound)
* lt (Less Than): `< b` (open upper bound)
* lte (Less Than or Equal): `≤ b` (closed upper bound)

For example, to define the half-open interval `(1.0, 5.0]` as a schema, an object such as `{ gt: 1.0, lte: 5.0 }` is used. This is a practical and robust approach to defining strictly the boundaries of an interval with infinitely many elements, bridging the gap between type inference and runtime validation for continuous values[^28].

## 6. The Depths of Floating-Point Numbers and Interval Arithmetic: The Constraints of IEEE 754

When floating-point numbers are treated as intervals, merely specifying whether the bounds are open or closed is not enough; serious anomalies stemming from the underlying IEEE 754 standard must be taken into account.

### 6.1. The Collapse of Ordering Caused by NaN

As the Rust documentation for floating-point numbers (`f32`, `f64`) states explicitly, floating-point numbers do not have a total ordering[^31]. The chief cause is the existence of NaN (Not a Number).

NaN is not equal even to itself (`NaN == NaN` always returns false). Moreover, compared with any floating-point number, `NaN < 1.0` and `NaN > 1.0` are both false[^31]. For this reason Rust's `f32` type implements neither the `Eq` trait, which denotes an equivalence relation, nor the `Ord` trait, which denotes a total order[^32].

This mathematical property causes fatal problems for pattern matching and interval representation. What, for example, is the logical outcome of evaluating `contains(NaN)` on the floating-point interval `0.0..5.0`? Because every comparison operator returns false, the boundary check may behave unpredictably[^31]. Negative zero (`-0.0`) and positive zero (`+0.0`) are treated as equal under comparison (`==`), yet arithmetic such as division produces infinities of opposite sign (`−∞`, `+∞`) from them, so continuity can break down when they are used as interval boundaries[^32].

### 6.2. qNaN, sNaN, and Payloads

Furthermore, the IEEE 754 standard permits NaN bit patterns to carry a "payload" and a "signalling bit". Rust's implementation interprets a signalling bit of 1 as a quiet NaN (qNaN) and of 0 as a signalling NaN (sNaN)[^32]. These may produce non-deterministic bit patterns in the course of arithmetic, and it can even happen that compile-time constant evaluation (a `const` context) and runtime evaluation produce different NaNs[^32].

Because of this kind of complex non-determinism peculiar to IEEE 754, incorporating a simple "floating-point interval (`float<1.0, 5.0>`)" into a type system as a complete static proof is extremely difficult, which is the background to many languages having given up on it.

## 7. Advanced Mathematical Modeling and the Standard (IEEE 1788)

To handle complex interval operations that standard syntax (`..` or `..<`) cannot express, and to treat floating-point error with mathematical rigour, many languages offer advanced third-party or standard libraries, and even approaches based on an international standard.

### 7.1. Haskell: Allen's Interval Algebra and the `Ix` Class

In the purely functional language Haskell, the `Ix` class is used to map a contiguous subrange onto integers[^36]. The `inRange` function tests whether a value lies within the bounds, and `rangeSize` computes the size of the interval[^36]. For more advanced requirements, libraries such as `data-interval` are used; they support both open and closed intervals and implement relational operations based on Allen's Interval Algebra, which exhaustively covers containment, intersection, and adjacency between intervals[^37]. This makes it possible to prove and deduce mathematically how two intervals overlap logically[^38].

### 7.2. C++ (Boost.ICL): Static Boundaries and Tiling of Continuous Spaces

The C++ Interval Container Library (Boost.ICL) is designed with the difference between discrete and continuous types built into the very foundation of its architecture[^40].

Boost.ICL expresses interval boundaries as static type parameters:

* `right_open_interval<T>`: `[a, b)`
* `left_open_interval<T>`: `(a, b]`
* `closed_interval<T>`: `[a, b]`
* `open_interval<T>`: `(a, b)`

What deserves particular attention here is that the recommended default interval differs by data type. For discrete data such as integers and dates `discrete_interval` is used, whereas for continuous data such as real numbers and strings `continuous_interval` is used[^41]. The half-open interval (`right_open_interval`) is treated as the default for continuous data because its mathematical properties are best suited to partitioning and joining (tiling) a continuous space with neither overlap nor gaps[^41].

### 7.3. Julia: Complete Integration with Mathematical Notation

In Julia, whose focus is scientific computing, the demands on interval arithmetic are extremely high. Julia's `IntervalSets.jl` package has a design that maximizes the affinity between mathematical notation and programming[^44].

Besides expressing closed intervals with ordinary operators such as `1.0 .. 3.0` or `1.5 ± 1`, it allows strict definitions via type parameters such as `Interval{:open, :closed}(1, 3)`[^45]. Moreover, exploiting Julia's powerful string-macro facility, it offers a feature (`@iv_str`) that lets mathematical bracket notation be written directly in code[^45]:

* `iv"[1, 5]"` → closed interval `[1, 5]`
* `iv"[1, 5)"` → half-open interval `[1, 5)`
* `iv"(1, 5]"` → half-open interval `(1, 5]`
* `iv"(1, 5)"` → open interval `(1, 5)`

This is an outstanding design that leverages Julia's ability to hook into parsing at the syntax level, matching the representation of floating-point intervals with infinitely many elements exactly to the mental model of mathematicians[^45].

### 7.4. The IEEE 1788 Interval Arithmetic Standard and Decorations

The international standard that governs the accuracy and safety of arithmetic on intervals (interval arithmetic) is **IEEE 1788 (Standard for Interval Arithmetic)**[^47].

Because floating-point numbers carry rounding error, the boundary values of a computed result such as `[a, b] + [c, d]` must never fall slightly below (or above) the true mathematical boundaries. Following the principle "Thou shalt not lie", IEEE 1788 stipulates that the result of an interval operation must always be rounded outward so as to completely enclose the true solution[^49].

Extremely important in IEEE 1788 is also the concept of *decorations*[^48]. These are metadata attached to an interval that track its state: through which operations it was produced, whether the function was continuous on the interval, and so on.

| Decoration | Meaning / state |
| :---- | :---- |
| **COM** (Common) | The operation was fully valid, the interval is non-empty, and the function was continuous. |
| **DAC** (Defined and Continuous) | The interval may be unbounded, but the function is continuous. |
| **DEF** (Defined) | The value is defined, but a discontinuity may exist. |
| **TRV** (Trivial) | A trivial state such as a single point or an empty interval. |
| **ILL** (Illegal) | An invalid interval produced by an invalid operation (NaI: Not an Interval). |

For floating-point intervals, a "division by an interval straddling zero" such as `1/[−1, 2]` cannot be expressed as a simple closed interval; properly it becomes two separate intervals (or an unbounded one), as in `[−∞, −1] ∪ [0.5, ∞]`[^48]. IEEE 1788-compliant libraries (such as Julia's `IntervalArithmetic.jl`) use the decoration system to guarantee safe fallback and traceability so that the system does not break down even in such extreme situations[^53].
## 8. Persistence in Databases and the Mechanism of "Canonicalization"

The handling of intervals matters greatly not only for in-memory data structures in programming languages but also in the databases (RDBMS) that persist data. PostgreSQL natively supports powerful range types and elegantly resolves the problem of distinguishing discrete from continuous types through the concept of *canonicalization*[^1].

PostgreSQL provides the following principal range types[^54]:

| Range type | Data type | Characteristic |
| :---- | :---- | :---- |
| int4range, int8range | integer | discrete[^54] |
| daterange | date | discrete[^54] |
| numrange | numeric | continuous[^54] |
| tsrange, tstzrange | timestamp | continuous[^54] |

As constructors and in the textual representation, `[ ]` and `( )` can be used just as in mathematics to specify freely whether each bound is open or closed (for example `'[3, 7)'`, or using a function, `numrange(1.0, 14.0, '(]')`)[^54].

The most important feature of the database engine here is the automatic application of a *canonical function* to discrete data[^1]. For example, when the closed interval `'[1, 10]'` is inserted into the integer range type `int4range`, PostgreSQL recognises that this is "a discrete type with a well-defined step width" and automatically converts (canonicalizes) it to the standard form, the half-open interval `'[1, 11)'`, before storing it[^1]. This eliminates variation in representation (a mix of `[1, 10]` and `[1, 11)`) in comparison operations that use the internal B-tree and GiST indexes, enabling fast and accurate containment (`@>`) and overlap (`&&`) tests[^1].

For `numrange` over floating-point or high-precision numbers, on the other hand, this canonicalization is never performed, because in a continuous space there is no notion of a "next value" between elements[^1]. `'[1.0, 10.0]'` is stored as `'[1.0, 10.0]'`, and the system never manipulates the upper bound on its own[^59]. PostgreSQL also offers the `multirange` type (an ordered list of several non-contiguous intervals) to cope with problems such as the "splitting of an interval by division by zero" mentioned above, providing native support for complex set operations[^54].

This difference in behaviour is a perfect example of a database system understanding deeply, at the level of its type architecture, the fundamental proposition raised in the question that prompted this survey — "for integers one can rewrite with `+1`, but for floating-point numbers one cannot" — and implementing it by branching the internal representation per type[^1].

## 9. Conclusion

This survey has made clear that the syntax and features for intervals (ranges) in programming languages and related tools divide sharply in design philosophy according to whether the target data is *discrete* or *continuous*, and that each has evolved differently.

1. **The discrete compromise and Dijkstra's legacy**: In eras and contexts that dealt only with integers (array indices, slices, and so on), simple iteration syntax became the norm, based on Dijkstra's tenet that "the half-open interval `[a, b)` is supreme". As Python's slicing, Kotlin's old `until`, and PostgreSQL's canonicalization of discrete types show, for discrete data it was possible to unify processing without multiplying syntax, by means of an internal step that "converts a closed interval to a half-open one (applying `+1`)".
2. **The paradigm shift in syntax brought by continuous data**: As demand grew for processing floating-point and date-time data, the need arose to specify the boundaries of intervals with infinitely many elements accurately and intuitively. Thus, as shown by Swift's `...` and `..<`, Rust's `..=` and `..`, and Kotlin's evolution from `until` to `..<`, dedicated operator syntax that visually distinguishes open from closed boundaries at the compiler level was introduced into language after language.
3. **Adaptation by type systems and databases**: Static analysers such as PHPStan remain within the limits of integers, but TypeScript runtime validators such as Zod and ArkType, and database layers such as PostgreSQL, safely encapsulate the infinitude of continuous spaces through mappings to logical operators such as `gt` and `lte`, or through dedicated type definitions such as `numrange` that bypass canonicalization.
4. **Ultimate abstraction and mathematical rigour**: C++'s Boost.ICL, Julia's IntervalSets.jl, and the IEEE 1788 standard provide every combination of open, closed, and half-open intervals as types or macros, and go as far as tracking floating-point-specific rounding error and the continuity of functions (decorations). Here the syntax of programming languages transcends the bounded framework of computer science and integrates fully with pure mathematical notation.

Developers and language designers must choose the appropriate language features, libraries, and database type designs with a deep understanding of whether the target domain is confined to integer index processing or deals with a continuous real-number space. Control over whether interval boundaries are open or closed is no longer a mere convenience for preventing "off-by-one" or offset errors; it is an indispensable mathematical and structural foundation for modelling infinite continuous spaces safely and rigorously on a computer.

## References

[^1]: This is the effect of canonicalization in PostgreSQL - HEY World, [https://world.hey.com/dinom/this-is-the-effect-of-canonicalization-in-postgresql-3ed5507c](https://world.hey.com/dinom/this-is-the-effect-of-canonicalization-in-postgresql-3ed5507c)
[^2]: what is the difference between kotlin ..< operator and until operation for ranges, [https://stackoverflow.com/questions/76961954/what-is-the-difference-between-kotlin-operator-and-until-operation-for-range](https://stackoverflow.com/questions/76961954/what-is-the-difference-between-kotlin-operator-and-until-operation-for-range)
[^3]: Why do programmers prefer half-open intervals in Python, and what makes them easier to work with than inclusive intervals? - Quora, [https://www.quora.com/Why-do-programmers-prefer-half-open-intervals-in-Python-and-what-makes-them-easier-to-work-with-than-inclusive-intervals](https://www.quora.com/Why-do-programmers-prefer-half-open-intervals-in-Python-and-what-makes-them-easier-to-work-with-than-inclusive-intervals)
[^4]: Why Numbering Should Start At Zero - C2 Wiki, [https://wiki.c2.com/?WhyNumberingShouldStartAtZero](https://wiki.c2.com/?WhyNumberingShouldStartAtZero)
[^5]: Range syntax is confusing - language design - Rust Internals, [https://internals.rust-lang.org/t/range-syntax-is-confusing/10573](https://internals.rust-lang.org/t/range-syntax-is-confusing/10573)
[^6]: What is half open range and off the end value - Stack Overflow, [https://stackoverflow.com/questions/13066884/what-is-half-open-range-and-off-the-end-value](https://stackoverflow.com/questions/13066884/what-is-half-open-range-and-off-the-end-value)
[^7]: Python slicing operator [start:stop:step] - Stack Overflow, [https://stackoverflow.com/questions/75378378/python-slicing-operator-startstopstep](https://stackoverflow.com/questions/75378378/python-slicing-operator-startstopstep)
[^8]: slice() | Python's Built-in Functions, [https://realpython.com/ref/builtin-functions/slice/](https://realpython.com/ref/builtin-functions/slice/)
[^9]: Python Slice: Useful Methods for Everyday Coding - DataCamp, [https://www.datacamp.com/tutorial/python-slice](https://www.datacamp.com/tutorial/python-slice)
[^10]: Slices - ndindex documentation - GitHub Pages, [https://quansight-labs.github.io/ndindex/indexing-guide/slices.html](https://quansight-labs.github.io/ndindex/indexing-guide/slices.html)
[^11]: Slicing in Python: A Comprehensive Guide - Towards Data Science, [https://towardsdatascience.com/slicing-in-python-a-comprehensive-guide-a609c3bb877c/](https://towardsdatascience.com/slicing-in-python-a-comprehensive-guide-a609c3bb877c/)
[^12]: Slice Objects — Python 3.14.7 documentation, [https://docs.python.org/3/c-api/slice.html](https://docs.python.org/3/c-api/slice.html)
[^13]: Ranges — Chapel Documentation 2.9 - The Chapel Programming Language, [https://chapel-lang.org/docs/language/spec/ranges.html](https://chapel-lang.org/docs/language/spec/ranges.html)
[^14]: Proposal: Slicing · Issue #120 · dotnet/roslyn - GitHub, [https://github.com/dotnet/roslyn/issues/120](https://github.com/dotnet/roslyn/issues/120)
[^15]: String - Crystal 1.21.0 - The Crystal Programming Language, [https://crystal-lang.org/api/latest/String.html](https://crystal-lang.org/api/latest/String.html)
[^16]: Crystal Programming Language: The Ultimate Guide for Developers in 2026, [https://mustafadevstudio.netlify.app/330-2/](https://mustafadevstudio.netlify.app/330-2/)
[^17]: Ranges and progressions | Kotlin Documentation, [https://kotlinlang.org/docs/ranges.html](https://kotlinlang.org/docs/ranges.html)
[^18]: Basic Operators - Documentation | Swift.org, [https://docs.swift.org/swift-book/documentation/the-swift-programming-language/basicoperators/](https://docs.swift.org/swift-book/documentation/the-swift-programming-language/basicoperators/)
[^19]: Range | Apple Developer Documentation, [https://developer.apple.com/documentation/swift/range](https://developer.apple.com/documentation/swift/range)
[^20]: Switch With Ranges – Swift - Coddy.tech, [https://coddy.tech/learn/swift/fundamentals/switch_with_ranges](https://coddy.tech/learn/swift/fundamentals/switch_with_ranges)
[^21]: Tracking issue for `..X`, and `..=X` (`#![feature(half_open_range_patterns)]`) · Issue #67264 · rust-lang/rust - GitHub, [https://github.com/rust-lang/rust/issues/67264](https://github.com/rust-lang/rust/issues/67264)
[^22]: Writing code for static analysis - Shopware Developer Documentation, [https://developer.shopware.com/docs/resources/guidelines/code/core/writing-code-for-static-analysis.html](https://developer.shopware.com/docs/resources/guidelines/code/core/writing-code-for-static-analysis.html)
[^23]: Scalar types - Documentation - Psalm, [https://psalm.dev/docs/annotating_code/type_syntax/scalar_types/](https://psalm.dev/docs/annotating_code/type_syntax/scalar_types/)
[^24]: PHPDocタイプ - BEAR.Sunday, [https://bearsunday.github.io/manuals/1.0/ja/types.html](https://bearsunday.github.io/manuals/1.0/ja/types.html)
[^25]: How Psalm represents types - Documentation, [https://psalm.dev/docs/running_psalm/plugins/plugins_type_system/](https://psalm.dev/docs/running_psalm/plugins/plugins_type_system/)
[^26]: Documentation - TypeScript 4.0, [https://www.typescriptlang.org/docs/handbook/release-notes/typescript-4-0.html](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-4-0.html)
[^27]: Announcing TypeScript 4.0 - Microsoft Developer Blogs, [https://devblogs.microsoft.com/typescript/announcing-typescript-4-0/](https://devblogs.microsoft.com/typescript/announcing-typescript-4-0/)
[^28]: GitHub - productdevbook/unadapter: Universal, type-safe database adapter layer with a unified API for Drizzle, Prisma, Kysely, Knex, MongoDB, Sumak and in-memory backends, [https://github.com/productdevbook/unadapter](https://github.com/productdevbook/unadapter)
[^29]: BrainDAO/prompt-weaver: A powerful, extensible template engine for building prompts - GitHub, [https://github.com/IQAIcom/prompt-weaver](https://github.com/IQAIcom/prompt-weaver)
[^30]: GitHub - MunMunMiao/ck-orm: A typed ClickHouse query layer for modern JavaScript runtimes, with schema DSL, query builder, raw SQL escape hatches, session helpers, and observability hooks., [https://github.com/MunMunMiao/ck-orm](https://github.com/MunMunMiao/ck-orm)
[^31]: A Beginner's Guide to Mastering Data Types in Rust | by Uday Hiwarale | rustycrab | Medium, [https://medium.com/rustycrab/a-beginners-guide-to-mastering-data-types-in-rust-c784c79810b2](https://medium.com/rustycrab/a-beginners-guide-to-mastering-data-types-in-rust-c784c79810b2)
[^32]: f32 - Rust Documentation, [https://doc.rust-lang.org/std/primitive.f32.html](https://doc.rust-lang.org/std/primitive.f32.html)
[^33]: f32 - kernel - Rust, [https://rust.docs.kernel.org/core/primitive.f32.html](https://rust.docs.kernel.org/core/primitive.f32.html)
[^34]: Pattern Matching Limitations : r/rust - Reddit, [https://www.reddit.com/r/rust/comments/wemrdz/pattern_matching_limitations/](https://www.reddit.com/r/rust/comments/wemrdz/pattern_matching_limitations/)
[^35]: 3514-float-semantics - The Rust RFC Book, [https://rust-lang.github.io/rfcs/3514-float-semantics.html](https://rust-lang.github.io/rfcs/3514-float-semantics.html)
[^36]: Zvon - Haskell Reference, [http://www.zvon.org/other/haskell/Outputix/index.html](http://www.zvon.org/other/haskell/Outputix/index.html)
[^37]: LTS Haskell 20.24 (ghc-9.2.7) - Stackage, [https://www.stackage.org/lts-20.24](https://www.stackage.org/lts-20.24)
[^38]: Intervals and their relations : r/haskell - Reddit, [https://www.reddit.com/r/haskell/comments/gdbs7n/intervals_and_their_relations/](https://www.reddit.com/r/haskell/comments/gdbs7n/intervals_and_their_relations/)
[^39]: @hackage › project-m36 › dependencies — Flora.pm, [https://flora.pm/packages/@hackage/project-m36/1.2.6/dependencies](https://flora.pm/packages/@hackage/project-m36/1.2.6/dependencies)
[^40]: Interval Container Library, [http://www.joachim-faulhaber.de/boost_icl/doc/libs/icl/doc/html/boost_icl/examples/interval.html](http://www.joachim-faulhaber.de/boost_icl/doc/libs/icl/doc/html/boost_icl/examples/interval.html)
[^41]: Interval - Boost, [https://www.boost.org/doc/libs/latest/libs/icl/doc/html/boost_icl/examples/interval.html](https://www.boost.org/doc/libs/latest/libs/icl/doc/html/boost_icl/examples/interval.html)
[^42]: Chapter 1. Boost.Icl, [https://www.boost.org/libs/icl](https://www.boost.org/libs/icl)
[^43]: Always use [closed, open) intervals. A programmer's perspective - fhur.me, [https://fhur.me/posts/always-use-closed-open-intervals](https://fhur.me/posts/always-use-closed-open-intervals)
[^44]: IntervalSets.jl - Julia Packages, [https://juliapackages.com/p/intervalsets](https://juliapackages.com/p/intervalsets)
[^45]: Home · IntervalSets.jl, [https://juliamath.github.io/IntervalSets.jl/](https://juliamath.github.io/IntervalSets.jl/)
[^46]: Parser support for open/closed interval notation? - General Usage - Julia Discourse, [https://discourse.julialang.org/t/parser-support-for-open-closed-interval-notation/43781](https://discourse.julialang.org/t/parser-support-for-open-closed-interval-notation/43781)
[^47]: Computer Arithmetic and Validity: Theory, Implementation, and Applications 9783110301793, 9783110301731 - DOKUMEN.PUB, [https://dokumen.pub/computer-arithmetic-and-validity-theory-implementation-and-applications-9783110301793-9783110301731.html](https://dokumen.pub/computer-arithmetic-and-validity-theory-implementation-and-applications-9783110301793-9783110301731.html)
[^48]: (PDF) Interval Arithmetic with Containment Sets - ResearchGate, [https://www.researchgate.net/publication/220261171_Interval_Arithmetic_with_Containment_Sets](https://www.researchgate.net/publication/220261171_Interval_Arithmetic_with_Containment_Sets)
[^49]: A framework to test interval arithmetic libraries and their IEEE 1788-2015 compliance - arXiv, [https://arxiv.org/pdf/2307.06953](https://arxiv.org/pdf/2307.06953)
[^50]: Show HN: I made a calculator that works over disjoint sets of intervals | Hacker News, [https://news.ycombinator.com/item?id=47812341](https://news.ycombinator.com/item?id=47812341)
[^51]: An Interval Arithmetic for Robust Error Estimation - arXiv, [https://arxiv.org/pdf/2107.05784](https://arxiv.org/pdf/2107.05784)
[^52]: (PDF) Practical implementation of Interval Arithmetic - ResearchGate, [https://www.researchgate.net/publication/379413007_Practical_implementation_of_Interval_Arithmetic](https://www.researchgate.net/publication/379413007_Practical_implementation_of_Interval_Arithmetic)
[^53]: A Standards compliant Interval Arithmetic Library - Nextjournal, [https://nextjournal.com/krish8484/a-standards-compliant-interval-arithmetic-library](https://nextjournal.com/krish8484/a-standards-compliant-interval-arithmetic-library)
[^54]: Documentation: 18: 8.17. Range Types - PostgreSQL, [https://www.postgresql.org/docs/current/rangetypes.html](https://www.postgresql.org/docs/current/rangetypes.html)
[^55]: PostgreSQL - Jorge Israel Peña, [https://jip.dev/notes/postgresql/](https://jip.dev/notes/postgresql/)
[^56]: Data Types | openGauss documentation, [https://docs.opengauss.org/en/docs/5.0.0/docs/BriefTutorial/data-types.html](https://docs.opengauss.org/en/docs/5.0.0/docs/BriefTutorial/data-types.html)
[^57]: Documentation: 18: CREATE TYPE - PostgreSQL, [https://www.postgresql.org/docs/current/sql-createtype.html](https://www.postgresql.org/docs/current/sql-createtype.html)
[^58]: Update lower/upper bound of range type - postgresql - Stack Overflow, [https://stackoverflow.com/questions/18145852/update-lower-upper-bound-of-range-type](https://stackoverflow.com/questions/18145852/update-lower-upper-bound-of-range-type)
[^59]: How exactly does inclusion in postgresql ranges work? - Stack Overflow, [https://stackoverflow.com/questions/77221647/how-exactly-does-inclusion-in-postgresql-ranges-work](https://stackoverflow.com/questions/77221647/how-exactly-does-inclusion-in-postgresql-ranges-work)
[^60]: PostgreSQL 16.4 Documentation - Docs, [https://docs.jade.fyi/postgres/postgres-16.html](https://docs.jade.fyi/postgres/postgres-16.html)
