<?php

declare(strict_types=1);

use App\PicoHP\Precompile\ComposerGraphLoader;

it('loads composer autoload graph from project root', function () {
    $root = dirname(__DIR__, 3);
    $loader = new ComposerGraphLoader();
    $graph = $loader->load($root);

    expect($graph->classmap)->not->toBeEmpty();
    expect($graph->psr4NamespacePrefixes)->not->toBeEmpty();
});
