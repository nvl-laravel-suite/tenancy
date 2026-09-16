<?php

declare(strict_types=1);

require_once __DIR__.'/TenancyTestCase.php';
require_once __DIR__.'/TenancyDatabaseTestCase.php';

use Nvl\Tenancy\Tests\TenancyDatabaseTestCase;
use Nvl\Tenancy\Tests\TenancyTestCase;

uses(TenancyTestCase::class)->in(...array_values(array_filter(glob(__DIR__.'/Feature/*Test.php'), static fn (string $path): bool => ! str_ends_with($path, '/TenantAdoptionTest.php'))));
uses(TenancyDatabaseTestCase::class)->in(__DIR__.'/Feature/TenantAdoptionTest.php');
