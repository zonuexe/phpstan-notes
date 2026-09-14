<?php
require '/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/review-float-range-type/vendor/autoload.php';
require __DIR__ . '/FloatRangeTypeInstrumented.php';
register_shutdown_function(static function (): void { fwrite(STDERR, "\n[nextUp calls: " . \PHPStan\Type\FloatRangeType::$nextUpCalls . "]\n"); });
