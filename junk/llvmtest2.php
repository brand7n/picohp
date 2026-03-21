<?php

// Load the LLVM library
$llvm = FFI::cdef(
    "
    typedef void* LLVMTargetRef;
    typedef void* LLVMTargetMachineRef;
    typedef char* LLVMBool;

    const char* LLVMGetDefaultTargetTriple(void);
    void LLVMInitializeAArch64Target(void);
    void LLVMInitializeAArch64TargetMC(void);
    void LLVMInitializeAArch64AsmPrinter(void);
    void LLVMInitializeAArch64AsmParser(void);


    LLVMBool LLVMGetTargetFromTriple(const char *Triple, LLVMTargetRef *OutTarget, char **ErrorMessage);
    const char* LLVMGetTargetName(LLVMTargetRef T);
    const char* LLVMGetTargetDescription(LLVMTargetRef T);
    void LLVMDisposeMessage(char *Message);
",
    "/opt/homebrew/Cellar/llvm/19.1.6/lib/libLLVM-C.dylib" // Load the LLVM shared library
);

echo "Initializing AArch64 target...\n";
//Initialize AArch64 components
$llvm->LLVMInitializeAArch64Target();
$llvm->LLVMInitializeAArch64TargetMC();
$llvm->LLVMInitializeAArch64AsmPrinter();
$llvm->LLVMInitializeAArch64AsmParser();


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

// Retrieve target details
$target = $targetPtr[0];
$targetName = $llvm->LLVMGetTargetName($target);
$targetDescription = $llvm->LLVMGetTargetDescription($target);

echo "Target Name: " . FFI::string($targetName) . "\n";
echo "Target Description: " . FFI::string($targetDescription) . "\n";
