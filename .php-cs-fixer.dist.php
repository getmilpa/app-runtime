<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(['src', 'tests'])
    ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache']);

return (new Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_unused_imports' => true,
        'phpdoc_align' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'trailing_comma_in_multiline' => true,
        'ternary_operator_spaces' => true,
        'align_multiline_comment' => true,
    ])
    ->setFinder($finder);