<?php

declare(strict_types=1);

require_once __DIR__.'/TenancyTestCase.php';
require_once __DIR__.'/TenancyDatabaseTestCase.php';
require_once __DIR__.'/TenancySupportedDatabaseTestCase.php';

use Nvl\Tenancy\Tests\TenancyDatabaseTestCase;
use Nvl\Tenancy\Tests\TenancySupportedDatabaseTestCase;
use Nvl\Tenancy\Tests\TenancyTestCase;

uses(TenancyTestCase::class)->in(...array_values(array_filter(glob(__DIR__.'/Feature/*Test.php'), static fn (string $path): bool => ! in_array(basename($path), ['TenantAdoptionTest.php', 'TenantSupportedDatabaseTest.php'], true))));
uses(TenancyDatabaseTestCase::class)->in(__DIR__.'/Feature/TenantAdoptionTest.php');

uses(TenancySupportedDatabaseTestCase::class)->in(__DIR__.'/Feature/TenantSupportedDatabaseTest.php');
