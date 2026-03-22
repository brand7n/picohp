<?php

$llvm = FFI::cdef(
    <<<EOT
    typedef struct LLVMOpaqueContext *LLVMContextRef;
    typedef struct LLVMOpaqueModule *LLVMModuleRef;
    typedef struct LLVMOpaqueMemoryBuffer *LLVMMemoryBufferRef;
    typedef struct LLVMOpaqueTargetMachine *LLVMTargetMachineRef;
    typedef struct LLVMOpaqueTarget *LLVMTargetRef;

    typedef enum { LLVMCodeGenLevelNone, LLVMCodeGenLevelLess, LLVMCodeGenLevelDefault, LLVMCodeGenLevelAggressive } LLVMCodeGenOptLevel;
    typedef enum { LLVMRelocDefault, LLVMRelocStatic, LLVMRelocPIC, LLVMRelocDynamicNoPic } LLVMRelocMode;
    typedef enum { LLVMCodeModelDefault, LLVMCodeModelJITDefault, LLVMCodeModelTiny, LLVMCodeModelSmall, LLVMCodeModelKernel, LLVMCodeModelMedium, LLVMCodeModelLarge } LLVMCodeModel;

    LLVMContextRef LLVMGetGlobalContext(void);
    int LLVMCreateMemoryBufferWithContentsOfFile(const char *Path, LLVMMemoryBufferRef *OutMemBuf, char **OutMessage);
    int LLVMParseIRInContext(LLVMContextRef ContextRef, LLVMMemoryBufferRef MemBuf, LLVMModuleRef *OutModule, char **OutMessage);
    int LLVMVerifyModule(LLVMModuleRef M, int Action, char **OutMessage);
    LLVMTargetRef LLVMGetFirstTarget(void);
    char *LLVMGetDefaultTargetTriple(void);
    LLVMTargetMachineRef LLVMCreateTargetMachine(
        LLVMTargetRef T,
        const char *Triple,
        const char *CPU,
        const char *Features,
        LLVMCodeGenOptLevel Level,
        LLVMRelocMode Reloc,
        LLVMCodeModel CodeModel
    );
    int LLVMTargetMachineEmitToFile(LLVMTargetMachineRef T, LLVMModuleRef M, char *Filename, int FileType, char **ErrorMessage);
    void LLVMDisposeMessage(char *Message);
    void LLVMDisposeMemoryBuffer(LLVMMemoryBufferRef MemBuf);
    void LLVMDisposeModule(LLVMModuleRef M);
    void LLVMShutdown(void);

    void LLVMInitializeX86Target(void);
    void LLVMInitializeX86TargetMC(void);
    void LLVMInitializeX86AsmPrinter(void);
    void LLVMInitializeX86AsmParser(void);

    void LLVMInitializeAArch64Target(void);
    void LLVMInitializeAArch64TargetMC(void);
    void LLVMInitializeAArch64AsmPrinter(void);
    void LLVMInitializeAArch64AsmParser(void);

    char *LLVMPrintModuleToString(LLVMModuleRef M);
    EOT,
    "/opt/homebrew/Cellar/llvm/19.1.6/lib/libLLVM-C.dylib" // Load the LLVM shared library
);

// Initialize AArch64 targets
$llvm->LLVMInitializeAArch64Target();
$llvm->LLVMInitializeAArch64TargetMC();
$llvm->LLVMInitializeAArch64AsmPrinter();
$llvm->LLVMInitializeAArch64AsmParser();

// Get the first target
$firstTarget = $llvm->LLVMGetFirstTarget();

if ($firstTarget === null) {
    echo "No targets available. Ensure your LLVM library includes the required targets.\n";
    exit(1);
}
// Initialize x86 targets
// $llvm->LLVMInitializeX86Target();
// $llvm->LLVMInitializeX86TargetMC();
// $llvm->LLVMInitializeX86AsmPrinter();
// $llvm->LLVMInitializeX86AsmParser();

$llvm->LLVMInitializeAArch64Target();
$llvm->LLVMInitializeAArch64TargetMC();
$llvm->LLVMInitializeAArch64AsmPrinter();
$llvm->LLVMInitializeAArch64AsmParser();

// Helper function to handle LLVM errors
function handleLLVMError($llvm, ?FFI\CData $error)
{
    if ($error !== null) {
        echo "Error: ", FFI::string($error), PHP_EOL;
        $llvm->LLVMDisposeMessage($error);
        exit(1);
    }
}

// Ensure the file path is provided
if ($argc !== 3) {
    echo "Usage: php compile_ir.php <input.ll> <output.o>\n";
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];

// Step 1: Read the LLVM IR file
$memBuffer = $llvm->new("LLVMMemoryBufferRef[1]");
$error = $llvm->new("char *[1]");

if ($llvm->LLVMCreateMemoryBufferWithContentsOfFile($inputFile, $memBuffer, $error) !== 0) {
    handleLLVMError($llvm, $error[0]);
}

// Step 2: Parse the IR into an LLVM module
$module = $llvm->new("LLVMModuleRef[1]");
$context = $llvm->LLVMGetGlobalContext();

if ($llvm->LLVMParseIRInContext($context, $memBuffer[0], $module, $error) !== 0) {
    handleLLVMError($llvm, $error[0]);
}

// Step 3: Verify the module
if ($llvm->LLVMVerifyModule($module[0], 1 /* LLVMReturnStatusAction */, $error) !== 0) {
    handleLLVMError($llvm, $error[0]);
}

echo "Module successfully loaded and verified!\n";

// Step 4: Get the default target
$targetTriple = $llvm->LLVMGetDefaultTargetTriple();
if ($targetTriple === null) {
    echo "Failed to get the default target triple.\n";
    exit(1);
}

$targetTripleStr = FFI::string($targetTriple);

echo $targetTripleStr . PHP_EOL;

// Step 5: Get the first target
$target = $llvm->LLVMGetFirstTarget();
if ($target === null) {
    echo "Failed to get the first target.\n";
    //exit(1);
}

// Step 6: Create a target machine
$targetMachine = $llvm->LLVMCreateTargetMachine(
    $target,
    $targetTripleStr,
    "",
    "",
    $llvm->LLVMCodeGenLevelDefault,
    $llvm->LLVMRelocDefault,
    $llvm->LLVMCodeModelDefault
);

if ($targetMachine === null) {
    echo "Failed to create a target machine.\n";
    exit(1);
}

// Step 7: Emit object file
if ($llvm->LLVMTargetMachineEmitToFile(
    $targetMachine,
    $module[0],
    $outputFile,
    0, // LLVMObjectFile
    $error
) !== 0) {
    handleLLVMError($llvm, $error[0]);
}

echo "Object file successfully generated: $outputFile\n";

// Cleanup
$llvm->LLVMDisposeModule($module[0]);
//$llvm->LLVMDisposeMemoryBuffer($memBuffer[0]);
$llvm->LLVMShutdown();
