<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->name('*.php');

$config = new PhpCsFixer\Config();
$config
    ->setRules([
        '@PSR2' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'return_assignment' => true,
        'align_multiline_comment' => ['comment_type' => 'phpdocs_like'],
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => true,
        'cast_spaces' => true,
        'phpdoc_separation' => true,
        'class_definition' => ['multi_line_extends_each_single_line' => true],
        'concat_space' => ['spacing' => 'one'],
        'declare_equal_normalize' => ['space' => 'single'],
        'function_typehint_space' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_whitespace_in_blank_line' => true,
        'echo_tag_syntax' => ['format' => 'short'],
        'no_useless_else' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_trailing_comma_in_singleline_array' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'normalize_index_brace' => true,
        'object_operator_without_whitespace' => true,
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,
        'phpdoc_order' => true,
        'phpdoc_return_self_reference' => true,
        'phpdoc_scalar' => true,
        'short_scalar_cast' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'ternary_to_null_coalescing' => true,
        'trailing_comma_in_multiline' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setLineEnding("\r\n")
    ->setFinder($finder);

return $config;