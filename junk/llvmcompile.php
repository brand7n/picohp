<?php

// Define the LLVM C API bindings using FFI
$llvm = FFI::cdef(
    <<<EOT
    typedef struct LLVMOpaqueContext *LLVMContextRef;
    typedef struct LLVMOpaqueModule *LLVMModuleRef;
    typedef struct LLVMOpaqueMemoryBuffer *LLVMMemoryBufferRef;

    LLVMContextRef LLVMGetGlobalContext(void);
    int LLVMCreateMemoryBufferWithContentsOfFile(const char *Path, LLVMMemoryBufferRef *OutMemBuf, char **OutMessage);
    int LLVMParseIRInContext(LLVMContextRef ContextRef, LLVMMemoryBufferRef MemBuf, LLVMModuleRef *OutModule, char **OutMessage);
    int LLVMVerifyModule(LLVMModuleRef M, int Action, char **OutMessage);
    char *LLVMPrintModuleToString(LLVMModuleRef M);
    void LLVMDisposeMessage(char *Message);
    void LLVMDisposeMemoryBuffer(LLVMMemoryBufferRef MemBuf);
    void LLVMDisposeModule(LLVMModuleRef M);

    enum { LLVMReturnStatusAction = 1 };
    EOT,
    "/opt/homebrew/Cellar/llvm/19.1.6/lib/libLLVM-C.dylib" // Load the LLVM shared library
);

// Function to handle errors and safely free memory
function handleLLVMError($llvm, ?FFI\CData $error)
{
    if ($error !== null) {
        echo "Error: ", FFI::string($error), PHP_EOL;
        $llvm->LLVMDisposeMessage($error);
        exit(1);
    }
}

// Ensure the file path is provided
if ($argc !== 2) {
    echo "Usage: php compile_ir.php <input.ll>\n";
    exit(1);
}

$filePath = $argv[1];
while (1) {
    // Step 1: Read the LLVM IR file into a memory buffer
    $memBuffer = $llvm->new("LLVMMemoryBufferRef[1]");
    $error = $llvm->new("char *[1]");

    if ($llvm->LLVMCreateMemoryBufferWithContentsOfFile($filePath, $memBuffer, $error) !== 0) {
        handleLLVMError($llvm, $error[0]);
    }

    // Step 2: Parse the IR into an LLVM module
    $module = $llvm->new("LLVMModuleRef[1]");
    $context = $llvm->LLVMGetGlobalContext();

    if ($llvm->LLVMParseIRInContext($context, $memBuffer[0], $module, $error) !== 0) {
        handleLLVMError($llvm, $error[0]);
    }

    // Step 3: Verify the module
    if ($llvm->LLVMVerifyModule($module[0], $llvm->LLVMReturnStatusAction, $error) !== 0) {
        handleLLVMError($llvm, $error[0]);
    }

    echo "Module successfully loaded and verified!\n";

    // Step 4: Print the module for debugging
    $moduleStr = $llvm->LLVMPrintModuleToString($module[0]);
    echo FFI::string($moduleStr), PHP_EOL;
    $llvm->LLVMDisposeMessage($moduleStr);

    // Cleanup
    //$llvm->LLVMDisposeMemoryBuffer($memBuffer[0]);

    $llvm->LLVMDisposeModule($module[0]);
}
