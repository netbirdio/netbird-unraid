<?php

// Lightweight hygiene checks for the plugin's PHP. We intentionally avoid
// @PSR12 / indentation / alternative-syntax fixers: the Dynamix *.page files
// (and the view-style *.php) interleave HTML and PHP templating, and reflowing
// them fights the established style. PHPStan (phpstan.dist.neon) does the
// bug-finding; this just keeps whitespace/newlines tidy.
$finder = PhpCsFixer\Finder::create()
    ->files()
    ->in(__DIR__ . '/src')
    ->name('*.php')
    ->name('*.page')
;

$config = new PhpCsFixer\Config();
return $config
    ->setRiskyAllowed(false)
    ->setRules([
        'no_trailing_whitespace'             => true,
        'no_trailing_whitespace_in_comment'  => true,
        'no_whitespace_in_blank_line'        => true,
        'single_blank_line_at_eof'           => true,
        'line_ending'                        => true,
    ])
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setFinder($finder)
;
