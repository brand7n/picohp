# picoir

A PHP-subset compiler pipeline with a custom internal IR (PicoIR) and pluggable backends.
Extracted from the [picoHP](../picohp) `IR_experiment` branch.

```
PHP source --(php-parser, external)--> JSON AST --(picoir)--> PicoIR --> backends
                                        + PHPStan type info                  ├─ LLVM IR text  → clang → native
                                        (external, node attributes)          └─ ARM64 asm     → clang/as → native
```

**This repo owns:** JSON AST → PicoIR lowering, IR optimization passes, code emission.
**External (by design):** PHP parsing (php-parser produces the JSON AST) and type
checking (PHPStan; resolved types are stamped onto AST node attributes by the dev tool).

## IR core (`src/`)

SSA-based IR, deliberately ~1:1 with LLVM IR: `Module`, `Function_`, `BasicBlock`,
typed `Instruction\*` classes, `Builder`, `BaseType` (int/float/bool/string/ptr/void).
Passes transform the shared IR before any backend sees it:

- `Analysis/EscapeAnalysisPass` — interprocedural escape analysis (NoEscape/Arg/Global)
- `Transform/StackPromotionPass` — heap object allocs → stack allocas
- `Transform/StoreToLoadForwardingPass` — store→load forwarding within a block
- `Transform/DeadInstructionEliminationPass` — DCE
- `Interpreter` — executes IR directly (reference/differential oracle)

Backends: `Codegen/Arm64Emitter` (native AArch64), and LLVM text via `Module::dump()`.

## Frontend (`src/Frontend/`)

`AstLoader` → `AstNode` → `LoweringPass` → PicoIR.

### Input contract

The input is php-parser's JSON (`NodeDumper`/`jsonSerialize`): a top-level **list**
of statement nodes. `AstLoader` normalizes it to `{"nodeType":"_Root","stmts":[...]}`.

Type information arrives as a node attribute stamped by the (external) dumper:

```json
{"nodeType": "Expr_Variable", "attributes": {"resolvedType": "int"}, "name": "x"}
```

`TypeMapper` maps PHPStan type strings → `BaseType` (`"int|null"` → INT,
`"non-empty-string"` → STRING, etc.). Where no `resolvedType` is stamped, declared
types (`Identifier` nodes on params/return types) are used; untyped defaults to INT.

Generate an AST (dev tool, needs nikic/php-parser):

```bash
bin/dump-ast program.php program.json
```

### Supported subset (current slice)

int/float/string/bool literals · echo · variables + assignment · `+ - * / %` ·
comparisons · boolean and/or/not · if/elseif/else · while · for · ternary ·
function declarations (typed params/returns) · function calls · return · string concat (naive)

## CLI

```bash
bin/picoir <ast.json> [--emit-llvm|--emit-arm64] [--optimize] [--interpret] [-o out]
```

```bash
bin/picoir program.json > out.ll && clang out.ll -o a.out && ./a.out
bin/picoir program.json --optimize          # store-forwarding + DCE
bin/picoir program.json --interpret         # run on the IR interpreter
bin/picoir program.json --emit-arm64        # AArch64 assembly
```

## ARM64 assembler (`src/Assembler/`)

A self-contained two-pass AArch64 assembler (GNU/Linux syntax, little-endian
raw binary output). Pipeline: `Lexer` → `Parser` → `Assembler` (labels +
addresses) → `InstructionEncoder` → `Output\RawBinary`.

```bash
bin/armasm examples/hello.s program.bin
```

Supported: `ADD/SUB/ADDS/SUBS/AND/ORR/EOR` (X & W, register + immediate),
`MOV/MOVZ/MOVK/MOVN`, `CMP`, `MUL/SDIV/MSUB/LSL/LSR/ASR`, `B/BL/B.cond`,
`CBZ/CBNZ`, `CSET`, `RET`, `LDR/STR` (incl. STUR/LDUR), `LDP/STP` (pre/post
index), float ops (`FADD/FSUB/FMUL/FDIV/FCMP/FCSEL/FMOV/SCVTF/FCVTZS`), `BRK`,
labels, and `.text`/`.section`/`.p2align`/`.global`/`.globl`. Every encoding is
verified byte-for-byte against Apple's `clang -arch arm64`.

### Outputs

- `assemble()` — raw little-endian machine code
- `assembleObject()` — Mach-O arm64 relocatable object (link with `ld`/`clang`)
- `assembleElf()` — ELF64 aarch64 relocatable object (link with `clang`/`ld` on Linux)

```bash
bin/armasm in.s out.bin            # raw binary
bin/armasm in.s out.o --object     # Mach-O object
bin/armasm in.s out.o --elf        # ELF64 object (Linux)
```

### End-to-end: PHP → native executable

`bin/picoir-run` compiles a PHP program all the way to a runnable binary:
PHP → AST → PicoIR → ARM64 asm → Mach-O object (our assembler) → native
executable (Apple's `clang`) → run. The exit code is the program's return value.

```bash
bin/picoir-run program.php; echo $?
```

Works for integer arithmetic, control flow, and function calls. `echo`/`printf`
(needs libc + relocations) and string literals are the next step.

## Tests

Oracle-based: compiled binary output must match `php <program>` exactly.

```bash
composer install
vendor/bin/pest
```

Test programs in `tests/programs/`, their dumped ASTs in `tests/Fixtures/`.
The PoC harness and pass tests live in `dev/` (`php dev/harness.php`).

## Notes / known gaps

- Float echo uses `%f`; PHP prints `%.14G` — cosmetic mismatch for now.
- String concat lowers to libc `strcat` (no length tracking — naive).
- ARM64 backend emits calls but libc I/O linking is not wired up yet.
- `Instruction::$counter` is process-global; reset per-module if lowering many modules.
