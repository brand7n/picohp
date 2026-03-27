<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

enum LexerState
{
    case Initial;
    case InScripting;
}
