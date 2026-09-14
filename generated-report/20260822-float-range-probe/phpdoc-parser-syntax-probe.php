<?php
require '/Users/megurine/repo/php/phpstan-src/vendor/autoload.php';
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\{TypeParser, ConstExprParser, TokenIterator};
use PHPStan\PhpDocParser\ParserConfig;
$config = new ParserConfig([]);
$lexer = new Lexer($config);
$typeParser = new TypeParser($config, new ConstExprParser($config));
$cands = [
 'float<0.0, 1.0>', 'float<-1.5, 1.5>', 'float<0, 1>', 'float<min, max>', 'float<-inf, inf>', 'float<inf, inf>', 'float<-INF, INF>',
 'float<0.0, inf>', 'float<1e-3, 1e3>', 'float<1_000.5, 2_000.5>', 'float<.5, 1.>', 'float<-0.0, 0.0>',
 'float<0.0, 1.0)', 'float<(0.0, 1.0]>', 'float<]0.0, 1.0]>', 'float<0.0<, 1.0>', 'float<0.0, <1.0>', 'float<0.0, 1.0, open>', 'float<0.0, 1.0, closed-open>', 'float<0.0, 1.0, ClosedOpen>',
 'float<0.0, 1.0, "[)">', "float<0.0, 1.0, '[)'>", 'float<0.0, 1.0, [>', 'float<0.0, ~1.0>', 'float<~0.0, 1.0>', 'float<0.0!, 1.0>', 'float<(0.0), 1.0>', 'float<0.0..1.0>', 'float<0.0..<1.0>', 'float<0.0...1.0>',
 'float<gt 0.0, lte 1.0>', 'float<>0.0, <=1.0>', 'float<0.0, 1.0>~0.0', 'float~NAN', 'float~0.0', 'positive-float', 'non-negative-float', 'finite-float', 'float<PHP_FLOAT_MIN, PHP_FLOAT_MAX>', 'float<-PHP_FLOAT_MAX, PHP_FLOAT_MAX>',
 'int<min, max>', 'int<-5, 5>', 'int<0, 1.5>',
 'float<exclusive(0.0), 1.0>', 'float<open 0.0, 1.0>', 'float<0.0 open, 1.0>', 'float<after 0.0, 1.0>',
];
foreach ($cands as $c) {
  $tokens = new TokenIterator($lexer->tokenize($c));
  try {
    $node = $typeParser->parse($tokens);
    $rest = $tokens->isCurrentTokenType(Lexer::TOKEN_END) ? '' : ' [TRAILING: ' . $tokens->currentTokenValue() . '...]';
    $desc = (string) $node;
    $cls = (new ReflectionClass($node))->getShortName();
    $args = '';
    if ($node instanceof PHPStan\PhpDocParser\Ast\Type\GenericTypeNode) {
      $args = ' args=' . implode(' | ', array_map(fn($t) => (new ReflectionClass($t))->getShortName() . '(' . $t . ')', $node->genericTypes));
    }
    printf("%-42s OK   %-16s %s%s%s\n", $c, $cls, $desc, $args, $rest);
  } catch (\Throwable $e) {
    printf("%-42s FAIL %s\n", $c, explode("\n", $e->getMessage())[0]);
  }
}
