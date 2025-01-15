<?php

declare(strict_types=1);


it('builds a picoHP program', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/example1.php')->assertExitCode(0);

    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/example2.php')->assertExitCode(0);
    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $exe = "{$buildPath}/a.out";
    exec($exe, result_code: $result);
    expect($result)->toBe(49);
});
/*
object(PhpParser\Node\Scalar\Int_)#2189 (2) {
  ["attributes":protected]=>
  array(9) {
    ["startLine"]=>
    int(12)
    ["startTokenPos"]=>
    int(49)
    ["startFilePos"]=>
    int(166)
    ["endLine"]=>
    int(12)
    ["endTokenPos"]=>
    int(49)
    ["endFilePos"]=>
    int(166)
    ["rawValue"]=>
    string(1) "5"
    ["kind"]=>
    int(10)
    ["depth"]=>
    int(5)
  }
  ["value"]=>
  int(5)
}
#0 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(102): App\PicoHP\SymbolTable\PicoHPData::getPData()
#1 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(221): App\PicoHP\Pass\IRGenerationPass->buildExpr()
#2 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(105): App\PicoHP\Pass\IRGenerationPass->buildExpr()
#3 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(82): App\PicoHP\Pass\IRGenerationPass->buildExpr()
#4 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(44): App\PicoHP\Pass\IRGenerationPass->buildStmt()
#5 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(76): App\PicoHP\Pass\IRGenerationPass->buildStmts()
#6 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(44): App\PicoHP\Pass\IRGenerationPass->buildStmt()
#7 /home/runner/work/picohp/picohp/app/PicoHP/Pass/IRGenerationPass.php(34): App\PicoHP\Pass\IRGenerationPass->buildStmts()
#8 /home/runner/work/picohp/picohp/app/Commands/Build.php(79): App\PicoHP\Pass\IRGenerationPass->exec()
#9 /home/runner/work/picohp/picohp/vendor/illuminate/container/BoundMethod.php(36): App\Commands\Build->handle()
#10 /home/runner/work/picohp/picohp/vendor/illuminate/container/Util.php(43): Illuminate\Container\BoundMethod::{closure:Illuminate\Container\BoundMethod::call():35}()
#11 /home/runner/work/picohp/picohp/vendor/illuminate/container/BoundMethod.php(95): Illuminate\Container\Util::unwrapIfClosure()
#12 /home/runner/work/picohp/picohp/vendor/illuminate/container/BoundMethod.php(35): Illuminate\Container\BoundMethod::callBoundMethod()
#13 /home/runner/work/picohp/picohp/vendor/illuminate/container/Container.php(694): Illuminate\Container\BoundMethod::call()
#14 /home/runner/work/picohp/picohp/vendor/illuminate/console/Command.php(213): Illuminate\Container\Container->call()
#15 /home/runner/work/picohp/picohp/vendor/symfony/console/Command/Command.php(279): Illuminate\Console\Command->execute()
#16 /home/runner/work/picohp/picohp/vendor/illuminate/console/Command.php(182): Symfony\Component\Console\Command\Command->run()
#17 /home/runner/work/picohp/picohp/vendor/symfony/console/Application.php(1094): Illuminate\Console\Command->run()
#18 /home/runner/work/picohp/picohp/vendor/symfony/console/Application.php(342): Symfony\Component\Console\Application->doRunCommand()
#19 /home/runner/work/picohp/picohp/vendor/symfony/console/Application.php(193): Symfony\Component\Console\Application->doRun()
#20 /home/runner/work/picohp/picohp/vendor/illuminate/console/Application.php(164): Symfony\Component\Console\Application->run()
#21 /home/runner/work/picohp/picohp/vendor/laravel-zero/foundation/src/Illuminate/Foundation/Console/Kernel.php(423): Illuminate\Console\Application->call()
#22 /home/runner/work/picohp/picohp/vendor/laravel-zero/framework/src/Kernel.php(275): Illuminate\Foundation\Console\Kernel->call()
#23 /home/runner/work/picohp/picohp/vendor/illuminate/testing/PendingCommand.php(331): LaravelZero\Framework\Kernel->call()
#24 /home/runner/work/picohp/picohp/vendor/illuminate/testing/PendingCommand.php(532): Illuminate\Testing\PendingCommand->run()
#25 /home/runner/work/picohp/picohp/tests/Feature/BuildTest.php(8): Illuminate\Testing\PendingCommand->__destruct()
#26 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Factories/TestCaseMethodFactory.php(168): P\Tests\Feature\BuildTest->{closure:/home/runner/work/picohp/picohp/tests/Feature/BuildTest.php:6}()
#27 [internal function]: P\Tests\Feature\BuildTest->{closure:Pest\Factories\TestCaseMethodFactory::getClosure():158}()
#28 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Concerns/Testable.php(419): call_user_func_array()
#29 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Support/ExceptionTrace.php(26): P\Tests\Feature\BuildTest->{closure:Pest\Concerns\Testable::__callClosure():419}()
#30 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Concerns/Testable.php(419): Pest\Support\ExceptionTrace::ensure()
#31 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Concerns/Testable.php(321): P\Tests\Feature\BuildTest->__callClosure()
#32 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(169) : eval()'d code(17): P\Tests\Feature\BuildTest->__runTest()
#33 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestCase.php(1232): P\Tests\Feature\BuildTest->__pest_evaluable_it_builds_a_picoHP_program()
#34 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestCase.php(513): PHPUnit\Framework\TestCase->runTest()
#35 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestRunner/TestRunner.php(87): PHPUnit\Framework\TestCase->runBare()
#36 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestCase.php(360): PHPUnit\Framework\TestRunner->run()
#37 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestSuite.php(369): PHPUnit\Framework\TestCase->run()
#38 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestSuite.php(369): PHPUnit\Framework\TestSuite->run()
#39 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/Framework/TestSuite.php(369): PHPUnit\Framework\TestSuite->run()
#40 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/TextUI/TestRunner.php(64): PHPUnit\Framework\TestSuite->run()
#41 /home/runner/work/picohp/picohp/vendor/phpunit/phpunit/src/TextUI/Application.php(210): PHPUnit\TextUI\TestRunner->run()
#42 /home/runner/work/picohp/picohp/vendor/pestphp/pest/src/Kernel.php(103): PHPUnit\TextUI\Application->run()
#43 /home/runner/work/picohp/picohp/vendor/pestphp/pest/bin/pest(184): Pest\Kernel->handle()
#44 /home/runner/work/picohp/picohp/vendor/pestphp/pest/bin/pest(192): {closure:/home/runner/work/picohp/picohp/vendor/pestphp/pest/bin/pest:18}()
#45 /home/runner/work/picohp/picohp/vendor/bin/pest(119): include('...')
#46 {main}*/