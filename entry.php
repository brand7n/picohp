#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @var list<string> $cliArgv */
$cliArgv = [];
return App\Commands\BuildCommand::runFromArgv($cliArgv);
