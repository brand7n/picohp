<?php

declare(strict_types=1);

final class PromotedBox
{
    public function __construct(
        public string $label = 'ok',
    ) {
    }
}

$b = new PromotedBox();
echo $b->label;
