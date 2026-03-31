<?php

declare(strict_types=1);

function throw_ia(): void
{
    throw new InvalidArgumentException('x');
}

try {
    throw_ia();
} catch (InvalidArgumentException $e) {
    echo 'ok';
}
