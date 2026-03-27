<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function artisan(string $command): PendingArtisan
    {
        return new PendingArtisan($command);
    }
}
