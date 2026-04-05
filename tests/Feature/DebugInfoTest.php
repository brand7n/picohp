<?php

declare(strict_types=1);

it('emits DWARF debug metadata in generated IR', function () {
    $file = 'tests/programs/functions/debug_info_probe.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));

    $ir = file_get_contents("{$buildPath}/out.ll");
    assert(is_string($ir));

    // Compile unit and file metadata
    expect($ir)->toContain('!llvm.dbg.cu');
    expect($ir)->toContain('!DICompileUnit');
    expect($ir)->toContain('!DIFile(filename: "debug_info_probe.php"');

    // Function has !dbg attached
    expect($ir)->toMatch('/define.*@add\(.*\) !dbg !\d+/');
    expect($ir)->toMatch('/define.*@main\(.*\) !dbg !\d+/');

    // DISubprogram for user function
    expect($ir)->toContain('!DISubprogram(name: "add"');
    expect($ir)->toContain('!DISubprogram(name: "main"');

    // Instructions have !dbg locations
    expect($ir)->toMatch('/call.*@pico_int_to_string.*!dbg !\d+/');

    // DILocation with real line numbers
    expect($ir)->toMatch('/!DILocation\(line: 10/');  // $result = add(3, 4);
    expect($ir)->toMatch('/!DILocation\(line: 11/');  // echo ...
});

it('produces correct oracle output with debug info', function () {
    $file = 'tests/programs/functions/debug_info_probe.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
