<?php

function calc(): int
{
    /** @var int */
    $a = 4;
    /** @var int */
    $b = 5;
    /** @var int */
    $c = 64;
    /** @var int */
    $d = 32;
    /** @var bool */
    $e = true;
    /** @var float */
    $f = 4.234;
    return ($b + ((int)$f * 3)) | ($d & ($c / 2)) + (int)$e;
}

return calc();
