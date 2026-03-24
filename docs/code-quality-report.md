# Code quality report — picohp compiler

This document inventories **duplication**, **shortcuts**, **structural debt**, and **bootstrap risks** in the picohp codebase. It is written with three constraints in mind:

1. **Input contract** — picohp is designed around the assumption that **source code already satisfies PHPStan at level max** (plus project strict/bleeding-edge rules). Many checks are not “user-facing diagnostics” but **internal invariants** on AST shapes and earlier passes.

2. **`assert()` usage** — A large share of `assert()` calls exist to **satisfy PHPStan** (narrowing types after structural checks, documenting impossible branches) and to **fail fast** when invariants break. That is consistent with a research compiler where invalid input is out of scope. A separate concern is **runtime behavior when assertions are disabled** (`zend.assertions = -1`): invariants then silently stop firing. For a self-hosted toolchain, teams sometimes replace critical asserts with explicit `throw` or rely on CI always running with assertions on—worth deciding explicitly.

3. **Self-hosting goal** — Long term, **picohp’s own sources** and **nikic/php-parser** (at least the subset the compiler depends on) must **compile and run correctly under picohp**. Issues below are tagged where they **block**, **complicate**, or only **tangentially affect** that goal.

---

## Summary table

| Area                         | Severity (maintainability) | Self-hosting relevance                                        |
|-----------------------------|----------------------------|---------------------------------------------------------------|
| Monolithic passes           | High                       | Complicates incremental extraction of features needed for bootstrap |
| Duplicated builtin handling | Medium–High                | Drift risk as stdlib surface grows for parser/compiler code |
| Parallel destructuring logic| Medium                     | Same—every language feature may need changes in two places    |
| `instanceof` lowering       | Low–Medium (known shortcut)| Unsafe if `mixed`/untyped paths can reach `instanceof`        |
| Type modeling shortcuts     | Medium                     | May force awkward typing in real PHP code (parser, app)       |
| `PicoHPData` counter TODO   | Low–Medium                 | Unclear symbol identity; may bite when scaling to full app    |
| LLVM value model TODO       | Low                        | Mostly internal ergonomics                                    |
| Build pipeline TODOs        | Low–Medium                 | Static analysis on transformed code helps bootstrap confidence |
| Test phpstan-ignore noise   | Low                        | Hygiene only                                                  |

---

## 1. Monolithic passes

**What:** `SemanticAnalysisPass.php` (~1.1k lines) and `IRGenerationPass.php` (~1.8k lines) implement most of the language as large `if / elseif` chains over `PhpParser` node types.

**Why it matters:** Hard to navigate, test in isolation, or reuse. Adding a feature often means touching **two** giant files in parallel.

**Self-hosting:** As the compiler grows toward compiling itself and php-parser, **feature velocity and merge conflict risk** scale with file size. Splitting by node family or by phase (types vs effects) is a long-term refactor, not urgent for correctness alone.

---

## 2. Duplication

### 2.1 Builtin functions

Builtin behavior is keyed off **function name strings** in both `SemanticAnalysisPass` and `IRGenerationPass` (e.g. `strlen`, `count`, `array_key_exists`, `preg_match`).

**Risk:** Semantic rules and IR lowering can **drift**—one pass recognizes a builtin the other does not.

**Self-hosting:** Parser and compiler code will call a **large** subset of PHP builtins over time. A **single registry** (name → signature, effects, runtime symbol) would reduce duplication and make “what picohp supports for bootstrap” explicit.

### 2.2 List / array destructuring

Similar logic appears twice: type-checking / symbol binding in the semantic pass, and lowering in the IR pass (including handling of `null` slots in list positions and parallel `@phpstan-ignore-next-line` comments for skipped items).

**Risk:** Same as above—behavioral drift when the grammar or typing rules change.

### 2.3 Unimplemented `InlineHTML`

The same “string constant?” TODO appears in **both** passes for `Stmt\InlineHTML`.

**Self-hosting:** Relevant only if transformed output or user code emits inline HTML; many strict pipelines avoid it. Low priority unless the bootstrap path needs it.

### 2.4 Feature tests

Many Pest files repeat `/** @phpstan-ignore-next-line */` before `$this->artisan(...)`. That is **boilerplate duplication**, not a compiler bug.

---

## 3. Shortcuts and modeling hacks

These are **intentional** or **documented** limitations; they are problems only when real programs (including future self-hosted sources) need stricter semantics.

### 3.1 `instanceof` in IR generation

`IRGenerationPass` evaluates `Expr\Instanceof_` by **assuming the check succeeds** (emits a true boolean), after building the expression side only.

**Assumption:** The **semantic pass** and PHPStan-shaped input guarantee that `instanceof` is only used where it is consistent with static types (e.g. assertions), so runtime checks are redundant.

**Risk:** If `mixed`, untyped, or dynamic paths can reach `instanceof`, behavior will be **wrong** vs PHP.

**Self-hosting:** Depends whether **php-parser** and **picohp** sources use `instanceof` in ways that require real vtables / type IDs. If yes, this becomes a **required** feature, not a shortcut.

### 3.2 `null` and class constants in the type checker

Examples from `SemanticAnalysisPass`: `null` may be given a **placeholder** type comment (e.g. tied to ptr representation); class constants may be **assumed int** in places.

**Risk:** Mismatches between **static story** and **IR** if constants or nullability become more general in bootstrap code.

**Self-hosting:** PHPStan-max code still uses precise nullability and constants; shortcuts here may force **unnecessary casts** or block certain patterns unless generalized.

### 3.3 `PicoHPData` static counter

`PicoHPData` uses a static incrementing counter with an inline **TODO** about reset strategy and naming.

**Risk:** Unclear **identity** semantics for per-node metadata if the same pattern is relied on for debugging or codegen.

**Self-hosting:** Low immediate impact unless something depends on stable IDs across passes or functions.

### 3.4 LLVM layer (`ValueAbstract`, `Builder`)

Comments/TODOs suggest the instruction/value class hierarchy might be **finer than necessary**, and there are open questions around **pointer vs scalar** stores (`Builder`).

**Self-hosting:** Internal quality; affects velocity and bug rate when extending IR for larger codebases.

---

## 4. Pipeline and tooling (`Build` command)

Noted TODOs include: running **static analysis** on inputs or transformed output, **exception transformation**, and **filtering** the AST to relevant declarations.

**Self-hosting:** Rerunning PHPStan (or equivalent) on **transformed** AST could catch bugs in `ClassToFunctionVisitor` / `GlobalToMainVisitor` before IR. That increases confidence when the **input** is already PHPStan-clean but the **rewrite** is not.

---

## 5. Tests and stubs

- `tests/Unit/LLVMValueTest.php` — TODOs around expected output / phi nodes: **coverage gaps** for the LLVM abstraction.
- `tests/Unit/ClassConverterTest.php` — Commented TODOs for future behavior.
- `tests/programs/self_compile/` — Stubs and generators exist to probe **self-compile** paths; they are the right place to track **bootstrap blockers** as features land.

---

## 6. Compiler invariants (`CompilerInvariant`)

`assert()` has been replaced with **`CompilerInvariant::check()`**, which throws **`CompilerInvariantException`** (extends `LogicException`) when a condition fails. That gives:

- **Deterministic failures** when `zend.assertions` disables PHP’s `assert()`.
- **Explicit messages** (existing string second arguments preserved; a default is used when none was passed).
- **`@phpstan-assert true $condition`** on `check()` so PHPStan can still narrow types where the condition is a simple boolean expression.

The **`build`** command catches `CompilerInvariantException` and prints **`$e->getMessage()`** so CLI runs get a single-line error instead of an uncaught trace for those failures. Messages look like: **`{detail} (at app/PicoHP/Pass/SemanticAnalysisPass.php:687)`** — the suffix is the **call site of `CompilerInvariant::check()`** (paths relative to the project root when possible). See `tests/Feature/CompilerInvariantMessageTest.php`.

Under the **PHPStan-max input** assumption, most checks are still **internal invariants** (AST shape, registry lookups). Some paths still throw plain **`Exception`** for rule violations (e.g. return type mismatch); that is unchanged.

---

## 7. Recommended focus for self-hosting (non-exhaustive)

1. **Reduce semantic/IR duplication** for builtins and destructuring (registries + shared helpers).
2. **Revisit `instanceof` and constant/null modeling** when the first real **php-parser** or **app/** compile attempts fail.
3. **Track bootstrap failures** in `tests/programs/self_compile/` with small, named cases rather than only giant integrations.
4. **Unify “user” compile errors vs invariants** — optionally introduce a dedicated compile-error type and map both to consistent CLI output (today some failures use `Exception`, others `CompilerInvariantException`).

---

## 8. Document maintenance

This report is a **snapshot** of issues identified in a static review. It should be updated when major refactors land (e.g. pass splitting, builtin registry, real `instanceof`).
