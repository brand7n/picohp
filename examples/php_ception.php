<?php

function ffitest(): int
{
    /** @var int */
    $a = 4;
    /** @var int */
    $b = 5;
    /** @var int */
    $c = 64;
    /** @var int */
    $d = 32;
    return ($b + ($a * 3)) | ($d & ($c / 2));
}
