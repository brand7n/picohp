<?php

$llvm = FFI::cdef(
    <<<EOT
    typedef struct LLVMOpaqueTarget *LLVMTargetRef;

    char *LLVMGetDefaultTargetTriple(void);
    int LLVMGetTargetFromTriple(const char *Triple, LLVMTargetRef *OutTarget, char **ErrorMessage);
    char *LLVMGetTargetName(LLVMTargetRef T);
    char *LLVMGetTargetDescription(LLVMTargetRef T);
    void LLVMDisposeMessage(char *Message);

    LLVMInitializeAllTargetInfos();
    LLVMInitializeAllTargetMCs();
    LLVMInitializeAllAsmParsers();
    LLVMInitializeAllAsmPrinters();

    void LLVMInitializeAArch64Target(void);
    void LLVMInitializeAArch64TargetMC(void);
    void LLVMInitializeAArch64AsmPrinter(void);
    void LLVMInitializeAArch64AsmParser(void);

    EOT,
    "/opt/homebrew/Cellar/llvm/19.1.6/lib/libLLVM-C.dylib" // Load the LLVM shared library
);

// Initialize AArch64 targets
$llvm->LLVMInitializeAArch64Target();
$llvm->LLVMInitializeAArch64TargetMC();
$llvm->LLVMInitializeAArch64AsmPrinter();
$llvm->LLVMInitializeAArch64AsmParser();

$llvm->LLVMInitializeAllTargetInfos();
$llvm->LLVMInitializeAllTargetMCs();
$llvm->LLVMInitializeAllAsmParsers();
$llvm->LLVMInitializeAllAsmPrinters();


// Set the target triple to AArch64
$triple = "aarch64-apple-darwin";
echo "Using target triple: $triple\n";

$targetPtr = $llvm->new("LLVMTargetRef*");
$errorMessage = $llvm->new("char*");

if ($llvm->LLVMGetTargetFromTriple($triple, FFI::addr($targetPtr), FFI::addr($errorMessage))) {
    // Handle errors
    echo "Error: " . FFI::string($errorMessage) . "\n";
    $llvm->LLVMDisposeMessage($errorMessage);
    exit(1);
}
