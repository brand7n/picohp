#!/usr/bin/env bash
# Self-compile picoHP using the picoHP compiler itself.
# Usage: ./scripts/self-compile.sh [extra-args...]
#
# Overrides PhpParser\Token with our compat stub (avoids token_get_all).
# Extra args (e.g. -vv for verbose) are passed through.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

exec php -d memory_limit=1G "$PROJECT_DIR/picoHP" build --debug --entry=picoHP \
  --override-class 'PhpParser\Token' compat/PhpParser/Token.php \
  --override-class 'PhpParser\Lexer' compat/PhpParser/Lexer.php \
  --override-class 'PhpParser\Modifiers' compat/PhpParser/Modifiers.php \
  --override-class 'PhpParser\NodeTraverser' compat/PhpParser/NodeTraverser.php \
  --override-class 'PhpParser\ParserAbstract' compat/PhpParser/ParserAbstract.php \
  --override-class 'PhpParser\Parser\Php8' compat/PhpParser/Parser/Php8.php \
  --override-class 'App\PicoHP\HandLexer\TokenAdapter' compat/App/PicoHP/HandLexer/TokenAdapter.php \
  "$@" "$PROJECT_DIR"
