<?php

// Regelwerk aus StylePHP, aber ohne das Stub-Submodul tests/stubs (fremder Code, wird nicht formatiert)
$config = require __DIR__ . '/.style/.php-cs-fixer.php';

return $config->setFinder(
    PhpCsFixer\Finder::create()
        ->in(__DIR__)
        ->exclude('tests/stubs')
);
