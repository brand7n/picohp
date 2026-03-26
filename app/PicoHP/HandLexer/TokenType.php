<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

enum TokenType
{
    case OpenTag;
    case CloseTag;
    case Echo;
    case If;
    case Else;
    case While;
    case For;
    case Foreach;
    case Function;
    case Return;
    case ClassKeyword;
    case New;
    case Ident;
    case Variable;
    case LNumber;
    case DNumber;
    case String;
    case Semicolon;
    case Equals;
    case Plus;
    case Minus;
    case Whitespace;
    case Comment;
    case InlineHtml;
    case Eof;
    case BadChar;
}
