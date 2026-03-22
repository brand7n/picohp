.assembly Hello {}
.assembly extern mscorlib {}
.method static void Main()
{
    .entrypoint
    ldstr "Hello, world!"
    call void [mscorlib]System.Console::WriteLine(string)
    // Load constant 2 onto the stack
    ldc.i4.2
    
    // Load constant 3 onto the stack
    ldc.i4.3
    
    // Add the two numbers (pop both from the stack, add, and push the result back onto the stack)
    add
    
    // Print the result to the console
    call void [mscorlib]System.Console::WriteLine(int32)
    ret
}