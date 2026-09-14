<?php
spl_autoload_register(static function (string $class): void {
	$map = ['PHPStan\Type\FloatRangeType' => 'FloatRangeTypeStats.php', 'PHPStan\Type\TypeCombinator' => 'TypeCombinatorStats.php', 'PHPStan\Type\UnionType' => 'UnionTypeStats.php'];
	if (isset($map[$class])) { require __DIR__ . '/' . $map[$class]; }
}, true, true);
register_shutdown_function(static function (): void {
	foreach (['PHPStan\Type\FloatRangeType', 'PHPStan\Type\TypeCombinator', 'PHPStan\Type\UnionType'] as $c) {
		if (!class_exists($c, false)) { continue; }
		$s = $c::$stats; arsort($s); fwrite(STDERR, "\n[STATS $c] " . json_encode(array_slice($s, 0, 12)) . "\n");
	}
});
