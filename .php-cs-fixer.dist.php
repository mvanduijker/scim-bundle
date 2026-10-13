<?php

// Same rule sets as symfony/symfony.
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHPUnit10x0Migration:risky' => true,
        'protected_to_private' => false,
        'native_constant_invocation' => ['strict' => false],
        'declare_strict_types' => true,
    ])
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__.'/src', __DIR__.'/tests']));
