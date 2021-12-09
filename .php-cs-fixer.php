<?php
$finder = PhpCsFixer\Finder::create()
//->exclude('somedir')
//->notPath('src/Symfony/Component/Translation/Tests/fixtures/resources.php')
->in(__DIR__ . "/test");

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@PSR12' => true,
    ])
    ->setUsingCache(false)
    ->setFinder($finder)
;