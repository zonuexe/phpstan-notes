<?php
/**
 * @template T of float
 * @param T $x
 * @return T
 */
function keepNan($x, float $y)
{
	if (is_nan($y)) {
		throw new \Exception();
	}
	return $y; // base: return.type error (float is not T); branch: ?
}
/**
 * @template T of float
 * @param T $x
 * @return T
 */
function plainFloat($x, float $y)
{
	return $y; // control: must be an error on both
}
