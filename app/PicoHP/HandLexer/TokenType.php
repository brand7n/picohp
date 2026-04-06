<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

/**
 * Token type constants matching PHP's T_* values.
 * Plain int constants instead of backed enum to avoid enum machinery in compiled binaries.
 */
final class TokenType
{
    public const int OpenTag = 394;
    public const int CloseTag = 396;
    public const int Echo = 291;
    public const int If = 287;
    public const int Else = 289;
    public const int While = 293;
    public const int For = 295;
    public const int Foreach = 297;
    public const int Function = 310;
    public const int Return = 313;
    public const int ClassKeyword = 336;
    public const int New = 284;
    public const int Ident = 262;
    public const int Variable = 266;
    public const int LNumber = 260;
    public const int DNumber = 261;
    public const int ConstString = 269;
    public const int Semicolon = 59;
    public const int Equals = 61;
    public const int Plus = 43;
    public const int Minus = 45;
    public const int Whitespace = 397;
    public const int Comment = 392;
    public const int InlineHtml = 267;
    public const int Eof = 0;
    public const int BadChar = 411;
    public const int LeftParen = 40;
    public const int RightParen = 41;
    public const int LessThan = 60;
    public const int GreaterThan = 62;
    public const int LeftBracket = 91;
    public const int RightBracket = 93;
    public const int LeftBrace = 123;
    public const int RightBrace = 125;
    public const int Comma = 44;
    public const int QuestionMark = 63;
    public const int DoubleQuote = 34;
    public const int SingleQuote = 39;
    public const int Slash = 47;
    public const int Backslash = 92;
    public const int Dot = 46;
    public const int Exclamation = 33;
    public const int Asterisk = 42;
    public const int Percent = 37;
    public const int Ampersand = 38;
    public const int Pipe = 124;
    public const int Tilde = 126;
    public const int At = 64;
    public const int Colon = 58;
    public const int Hash = 35;
    public const int Caret = 94;
}
