<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use App\PHPStan\Rules\PicoHPCompatibilityRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<PicoHPCompatibilityRule>
 */
final class PicoHPCompatibilityRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PicoHPCompatibilityRule();
    }

    public function testForbiddenConstructs(): void
    {
        $this->analyse([__DIR__ . '/fixtures/picohp-forbidden.php'], [
            ['picoHP cannot compile eval().', 5],
            ['picoHP cannot compile goto statements.', 8],
            ['picoHP cannot compile yield (generators).', 12],
            ['picoHP cannot compile global variable declarations.', 16],
            ['picoHP cannot compile the @ error suppression operator.', 19],
            ['picoHP cannot compile extract() calls.', 21],
            ['picoHP cannot compile compact() calls.', 22],
            ['picoHP cannot compile dynamic function calls ($func()).', 25],
            ['picoHP cannot compile dynamic method calls ($obj->$method()).', 29],
            ['picoHP cannot compile dynamic class instantiation (new $className()).', 32],
            ['picoHP cannot compile dynamic property access ($obj->$prop).', 35],
            ['picoHP cannot compile magic method __get().', 39],
            ['picoHP cannot compile Reflection API usage (ReflectionClass).', 44],
            ['picoHP cannot compile Closure::bind().', 46],
            ['picoHP cannot compile dynamic include/require paths.', 48],
            ['picoHP cannot compile call_user_func() calls.', 50],
        ]);
    }

    public function testAllowedConstructs(): void
    {
        $this->analyse([__DIR__ . '/fixtures/picohp-allowed.php'], []);
    }
}
