# AGENTS.md — picohp

## Project Overview

picohp is a PHP compiler written in PHP (Laravel Zero) that emits LLVM IR and
produces native binaries. The compiler is invoked via:

```bash
./picoHP build <file.php>
# or for a project directory:
./picoHP build <directory>
```

The compiled binary lands at `<build_path>/a.out` (see `config/app.php` for
build_path).

Development is driven by an agentic TDD loop: Claude Code proposes features,
writes tests, implements them, and opens PRs for human review.

## Golden Rule

**You NEVER merge your own work.** All changes go through a PR that the
maintainer reviews and approves. No exceptions.

## Architecture

```
Source PHP → PHP-Parser AST
  → ClassToFunctionVisitor (rewrites classes to functions)
  → GlobalToMainVisitor (wraps global code in main)
  → SemanticAnalysisPass (type checking, symbol resolution)
  → IRGenerationPass (emits LLVM IR via Builder)
  → LLVM toolchain (llc/clang) → native binary
```

Key directories:
- `app/Commands/Build.php` — the artisan build command
- `app/PicoHP/LLVM/` — LLVM IR builder, values, basic blocks, functions
- `app/PicoHP/Pass/` — compiler passes (semantic analysis, IR generation)
- `app/PicoHP/SymbolTable/` — symbol table, scopes, type info
- `app/PicoHP/Tree/` — AST node interfaces
- `tests/Feature/` — Pest feature tests (compilation + execution)
- `tests/Unit/` — Pest unit tests (individual components)
- `examples/` — sample PHP programs

## Compilation Target

The compiler targets PHP code that passes PHPStan analysis at level max
with strict rules and bleeding edge enabled:
```neon
parameters:
    level: max
    paths:
    - src
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon
```

This means:
- All variables and return types are explicitly typed
- No loose comparisons (=== only)
- No mixed types
- No dynamic property access or magic methods
- Strict function signatures

Test programs should also pass PHPStan at this level. The agent should
run `vendor/bin/phpstan analyse` on test programs before considering
them valid.

## Long-term Goal

Self-compilation. The compiler should eventually be able to compile its own
source code (`app/`). Feature priorities should be informed by what constructs
the compiler's own codebase uses.

## Testing

### Test Framework

This project uses **Pest** (PHP testing framework built on PHPUnit).

Run all tests:
```bash
php artisan test
# or
./vendor/bin/pest
```

## CI

GitHub Actions runs on every PR to main:
1. PHPStan (strict rules, level max)
2. Pest (95% minimum coverage)
3. Pint (code style)

All three must pass before opening a PR. Run locally:
```bash
composer run-script phpstan
vendor/bin/pest --coverage --min=95
vendor/bin/pint --test
```

### The Oracle

The reference PHP interpreter is the oracle for correctness. For any test
program, the compiled binary's stdout must match `php <file>` stdout exactly.

**Never generate expected output from the compiler.** Always use the PHP
interpreter.

### Test Patterns

**Feature tests** (compilation + execution) go in `tests/Feature/`. The core
pattern for oracle-based tests:

```php
it('handles <feature description>', function () {
    $file = 'tests/programs/<category>/<test_name>.php';

    // Compile
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    // Run compiled binary, capture stdout
    $buildPath = config('app.build_path');
    $compiled_output = shell_exec("{$buildPath}/a.out");

    // Run through PHP interpreter, capture stdout
    $php_output = shell_exec("php {$file}");

    // Oracle check: they must match
    expect($compiled_output)->toBe($php_output);
});
```

**Unit tests** go in `tests/Unit/` for testing individual compiler components
(symbol table, type parser, AST nodes, LLVM value classes, etc.).

### Test Programs

Store test PHP programs in `tests/programs/` organized by feature:

```
tests/programs/
├── arithmetic/
│   ├── basic_ops.php
│   ├── precedence.php
│   └── division.php
├── strings/
│   ├── echo_string.php
│   └── concatenation.php
├── control_flow/
│   ├── if_else.php
│   └── while_loop.php
└── functions/
    ├── basic_function.php
    └── return_value.php
```

Each test program should be a small, focused PHP script that exercises one
specific behavior and produces output via `echo`.

## Workflow

### 1. Pick or Propose a Task

Tasks are tracked as **GitHub Issues** with the label `agent-task`.

Check for open approved tasks:
```bash
gh issue list --label agent-task --label approved --state open
```

If no approved tasks exist, you may **propose** a new one:
```bash
gh issue create --label agent-task --template agent-task.md
```

Use the issue template. The maintainer will review and label it `approved`.

### 2. Wait for Approval

**Do not begin implementation until the issue is labeled `approved`.**

```bash
gh issue view <number>
```

### 3. Create a Feature Branch

```bash
git checkout -b feature/<issue-number>-<short-name>
```

### 4. Write Tests First

1. Create test program(s) in `tests/programs/<category>/`
2. Verify they run correctly under `php`:
   ```bash
   php tests/programs/<category>/<test>.php
   ```
3. Add a Pest test in `tests/Feature/` that compiles and compares output
4. Run the test — it **must fail** (confirms the feature is missing):
   ```bash
   php artisan test --filter="<test name>"
   ```

If a new test already passes, the test is not exercising new behavior — revise it.

### 5. Implement the Feature

Modify the compiler to make the failing tests pass. Guidelines:

- Keep changes minimal and focused on the task
- Do not refactor unrelated code in the same branch
- Do not add features beyond what the issue specifies
- Commit frequently with clear messages referencing the issue number

### 6. Run Full Test Suite

```bash
php artisan test
```

**ALL tests must pass** — new and existing. No regressions.

### 7. Open a PR

```bash
gh pr create \
  --title "feat: <short description> (closes #<issue>)" \
  --body "Implements #<issue>

## Changes
<brief summary of compiler modifications>

## Test Results
\`\`\`
<paste full test output>
\`\`\`

## New Test Programs
<list of test .php files added>

## Notes
<anything the reviewer should know>"
```

### 8. Wait for Review

The maintainer will:
- **Approve and merge** — done
- **Request changes** — address feedback, push to the same branch
- **Reject** — discuss in the issue before retrying

## What NOT To Do

- **Never merge your own PR**
- **Never skip the approval step** — even if the task seems obvious
- **Never write tests that test what the code does** instead of what it
  *should* do. The PHP interpreter is the oracle.
- **Never generate expected output from the compiler itself**
- **Never modify existing passing tests** without maintainer approval
- **Never bulk-generate dozens of tasks** — propose 1–3 at a time
- **Never refactor in a feature branch** — separate PRs for refactors

## Feature Priority (rough order)

1. Integer literals and arithmetic (`+ - * / %`)
2. String literals and echo/print
3. Variables and assignment
4. Comparison operators and boolean logic
5. `if / else / elseif`
6. `while` loops
7. `for` loops
8. Functions (declaration, parameters, return)
9. Arrays (indexed)
10. Arrays (associative)
11. String operations (concatenation, interpolation)
12. Type casting / coercion
13. `foreach`
14. Classes (basic)
15. File I/O

This list is a guide. Check with the maintainer on actual priorities — some of
these may already be partially implemented.

## Conventions

- One feature per PR
- Test programs: `tests/programs/<category>/<descriptive_name>.php`
- Pest tests: `tests/Feature/<Category>Test.php`
- Commit prefixes: `feat:`, `fix:`, `test:`, `refactor:`
- Branch naming: `feature/<issue>-<short-name>`
