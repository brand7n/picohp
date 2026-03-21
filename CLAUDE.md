# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

picoHP is a PHP-to-native compiler written in PHP (Laravel Zero). It parses PHP source with nikic/php-parser, runs semantic analysis, emits LLVM IR, and compiles to native binaries via clang. Long-term goal: self-compilation.

## Commands

```bash
# Build a PHP file
./picoHP build <file.php>
./picoHP build <file.php> --debug    # emit AST, IR, transformed code artifacts

# Tests (Pest framework)
php artisan test                      # all tests
php artisan test --filter="<name>"    # single test
vendor/bin/pest --coverage            # with coverage

# Static analysis (PHPStan level max, strict + bleeding edge)
composer run-script phpstan

# Code style (Laravel Pint, PSR-12)
vendor/bin/pint --test                # check only
vendor/bin/pint                       # fix

# Run all checks (pre-commit hook runs this)
composer run-script check             # phpstan + pest + pint --test
```

## Architecture

```
Source PHP → PHP-Parser AST
  → ClassToFunctionVisitor (static methods → functions)
  → GlobalToMainVisitor (wraps global code in main())
  → SemanticAnalysisPass (type checking, symbol table)
  → IRGenerationPass (emits LLVM IR via Builder)
  → LLVM toolchain (opt, clang) → native binary
```

- **Entry point**: `app/Commands/Build.php` orchestrates the full pipeline
- **LLVM layer**: `app/PicoHP/LLVM/` — Builder emits IR instructions, Module holds functions/globals
- **Passes**: `app/PicoHP/Pass/` — SemanticAnalysisPass (types/symbols), IRGenerationPass (codegen)
- **Type system**: `app/PicoHP/PicoType.php` — int(i32), float(double), bool(i1), string(ptr), void; supports nullable (`?type`) and arrays
- **Symbol table**: `app/PicoHP/SymbolTable/` — nested scopes, PHPDoc type parsing via DocTypeParser
- **Runtime**: `runtime/` — Rust library (pico-rt) providing string concatenation and other runtime functions

## Testing Conventions

**Oracle-based testing**: compiled output must match `php <file>` output exactly. Never generate expected output from the compiler.

Test programs live in `tests/programs/<category>/` (one feature per file). Feature tests in `tests/Feature/<Category>Test.php` follow this pattern:

```php
it('handles <feature>', function () {
    $file = 'tests/programs/<category>/<test>.php';
    $this->artisan("build --debug {$file}")->assertExitCode(0);
    $compiled_output = shell_exec(config('app.build_path') . "/a.out");
    $php_output = shell_exec("php {$file}");
    expect($compiled_output)->toBe($php_output);
});
```

## Compilation Target

The compiler targets PHP code that passes PHPStan at level max with strict rules: all types explicit, `===` only, no mixed types, no magic methods. Test programs must also pass PHPStan.

## Related Configuration

- **AGENTS.md** — comprehensive development workflow guide (task tracking, PR process, feature priority, do/don't rules). Claude Code loads this automatically; defer to it for workflow details.
- **`.agents/skills/`** — installed agent skills (e.g., `self-documenting-code`). Available via `/skill-name` slash commands.

## Conventions

- Commit prefixes: `feat:`, `fix:`, `test:`, `refactor:`, `chore:`
- Branch naming: `feature/<issue-number>-<short-name>`
- One feature per PR; never merge your own PR
- TDD: write failing tests first, then implement
- Never refactor in a feature branch — separate PRs
