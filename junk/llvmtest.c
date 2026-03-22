#include <llvm-c/Target.h>
#include <llvm-c/TargetMachine.h>
#include <stdio.h>

// clang -I/opt/homebrew/Cellar/llvm/19.1.6/include -L/opt/homebrew/Cellar/llvm/19.1.6/lib -lLLVM -o test_llvm llvmtest.c

int main() {
    // Initialize AArch64 target and related components
    // printf("Initializing AArch64 target...\n");
    // LLVMInitializeAArch64Target();
    // LLVMInitializeAArch64TargetMC();
    // LLVMInitializeAArch64AsmPrinter();
    // LLVMInitializeAArch64AsmParser();

    // Initialize all targets
    printf("Initializing all targets...\n");
    LLVMInitializeAllTargetInfos();
    LLVMInitializeAllTargetMCs();
    LLVMInitializeAllAsmParsers();
    LLVMInitializeAllAsmPrinters();

    // Manually set the triple to AArch64
    const char *triple = "aarch64-apple-darwin";  // Adjust as needed (this is macOS AArch64)
    printf("Using target triple: %s\n", triple);

    // Retrieve the target using the triple
    LLVMTargetRef target = NULL;
    char *errorMessage = NULL;
    if (LLVMGetTargetFromTriple(triple, &target, &errorMessage) != 0) {
        printf("Error: %s\n", errorMessage);
        //LLVMDisposeMessage(errorMessage);
        return 1;
    }

    // Print target details
    const char *targetName = LLVMGetTargetName(target);
    const char *targetDesc = LLVMGetTargetDescription(target);

    printf("Target Name: %s\n", targetName);
    printf("Target Description: %s\n", targetDesc);

    return 0;
}

