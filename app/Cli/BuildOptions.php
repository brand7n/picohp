<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Parsed `picohp build …` arguments (tokens after the {@code build} subcommand).
 */
final class BuildOptions
{
    public function __construct(
        public bool $debug = false,
        public bool $sharedLib = false,
        public bool $precompilePlan = false,
        public bool $dumpTokens = false,
        public bool $dumpAst = false,
        public int $verbosity = 0,
        public string $out = 'a.out',
        public string $withOptLl = 'off',
        public string $entry = 'src/main.php',
        public ?string $filename = null,
        /** @var array<string, string> FQCN => path (absolute or relative to project root for directory builds) */
        public array $classPathOverrides = [],
    ) {
    }

    /**
     * @param list<string> $tokens argv tokens after {@code build}
     */
    public static function parse(array $tokens): self
    {
        $o = new self();
        $positionals = [];
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $t = $tokens[$i];
            if ($t === '-d' || $t === '--debug') {
                $o->debug = true;
                $i++;

                continue;
            }
            if ($t === '--shared-lib') {
                $o->sharedLib = true;
                $i++;

                continue;
            }
            if ($t === '--precompile-plan') {
                $o->precompilePlan = true;
                $i++;

                continue;
            }
            if ($t === '--dump-tokens') {
                $o->dumpTokens = true;
                $i++;

                continue;
            }
            if ($t === '--dump-ast') {
                $o->dumpAst = true;
                $i++;

                continue;
            }
            if ($t === '-v' || $t === '--verbose') {
                $o->verbosity = max($o->verbosity, 1);
                $i++;

                continue;
            }
            if ($t === '-vv' || $t === '-vvv') {
                $o->verbosity = max($o->verbosity, 2);
                $i++;

                continue;
            }
            if (str_starts_with($t, '--out=')) {
                $o->out = substr($t, strlen('--out='));
                $i++;

                continue;
            }
            if ($t === '--out') {
                $i++;
                if ($i < $n && !str_starts_with($tokens[$i], '-')) {
                    $o->out = $tokens[$i];
                    $i++;
                }

                continue;
            }
            if (str_starts_with($t, '--with-opt-ll=')) {
                $o->withOptLl = substr($t, strlen('--with-opt-ll='));
                $i++;

                continue;
            }
            if ($t === '--with-opt-ll') {
                $i++;
                if ($i < $n && !str_starts_with($tokens[$i], '-')) {
                    $o->withOptLl = $tokens[$i];
                    $i++;
                } else {
                    $o->withOptLl = 'off';
                }

                continue;
            }
            if (str_starts_with($t, '--entry=')) {
                $o->entry = substr($t, strlen('--entry='));
                $i++;

                continue;
            }
            if ($t === '--entry') {
                $i++;
                if ($i < $n && !str_starts_with($tokens[$i], '-')) {
                    $o->entry = $tokens[$i];
                    $i++;
                }

                continue;
            }
            if ($t === '--override-class') {
                $i++;
                if ($i >= $n) {
                    throw new \InvalidArgumentException('--override-class requires a fully-qualified class name and a PHP file path');
                }
                $fqcn = $tokens[$i++];
                if ($i >= $n) {
                    throw new \InvalidArgumentException('--override-class requires a PHP file path after the class name');
                }
                $path = $tokens[$i++];
                $o->classPathOverrides[$fqcn] = $path;

                continue;
            }
            if (str_starts_with($t, '-')) {
                throw new \InvalidArgumentException("Unknown option: {$t}");
            }
            $positionals[] = $t;
            $i++;
        }
        if (count($positionals) > 1) {
            throw new \InvalidArgumentException('Too many arguments: expected one file or directory');
        }
        if (count($positionals) > 0) {
            $o->filename = $positionals[0];
        }

        return $o;
    }
}
