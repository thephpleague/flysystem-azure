<?php

return PhpCsFixer\Config::create()
    ->setRules([
        '@PSR2' => true,
        'ordered_imports' => true,
        'array_syntax' => ['syntax' => 'short'],
        'yoda_style' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()->in('src'))
    ->setUsingCache(false)
;