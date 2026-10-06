<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'PicoIR\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));

    // Instruction subclasses are all in Instructions.php
    if (str_starts_with($relative, 'Instruction\\')) {
        require_once __DIR__ . '/../src/Instruction/Instructions.php';
        return;
    }

    // Analysis namespace
    if (str_starts_with($relative, 'Analysis\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }

    // Codegen namespace
    if (str_starts_with($relative, 'Codegen\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }

    // Transform namespace
    if (str_starts_with($relative, 'Transform\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }

    // Values.php contains multiple classes
    $valueClasses = ['Constant', 'NullConstant', 'Param', 'Global_', 'Label', 'Void_'];
    if (in_array($relative, $valueClasses, true)) {
        require_once __DIR__ . '/../src/Values.php';
        return;
    }

    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
