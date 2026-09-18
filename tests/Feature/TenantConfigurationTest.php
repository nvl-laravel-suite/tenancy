<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Config\PackageConfigurationMerger;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenancyConfiguration;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Tests\Fixtures\EmptyAdoptionAdapter;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\TestTenantDirectory;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

it('derives application ownership and accepts compatible explicit platform families without probing SQL', function (): void {
    DB::connection()->enableQueryLog();
    $resource = new TenantResourceDefinition('pages.pages', 'pages', OwnedRecord::class, allowsPlatformRows: true);
    app(TenantResourceRegistry::class)->register($resource);
    $ownership = app(TenantOwnershipConfiguration::class);
    expect($ownership->mode($resource))->toBe('tenant');
    config()->set('tenancy.resources.pages', 'platform');
    $ownership->validate();
    expect($ownership->mode($resource))->toBe('platform')->and(DB::connection()->getQueryLog())->toBe([]);
});

it('bounds invalid values and key labels before producing diagnostics', function (string $case): void {
    $long = str_repeat('private-value-', 1000);
    match ($case) {
        'value' => config()->set('tenancy.strategy', $long),
        'key' => config()->set('tenancy.'.$long, true),
        'family' => config()->set('tenancy.resources', [$long => 'tenant']),
        'mode' => config()->set('tenancy.resources', [$long => 'bad']),
    };
    try {
        app(TenancyConfiguration::class)->validate();
        app(TenantOwnershipConfiguration::class)->validate();
        $this->fail('Invalid configuration was accepted.');
    } catch (TenantConfigurationInvalid $exception) {
        expect(strlen($exception->getMessage()))->toBeLessThan(512)
            ->and($exception->getMessage())->not->toContain($long);
    }
})->with(['value', 'key', 'family', 'mode']);

it('preserves deep maps and replaces neutral lists atomically while rejecting resolver lists', function (): void {
    config()->set('tenancy', ['sharing' => ['media' => 'copy']]);
    (new TenancyServiceProvider(app()))->register();
    expect(config('tenancy.sharing'))->toBe(['media' => 'copy', 'metafields' => 'none', 'templates' => 'none']);
    expect(PackageConfigurationMerger::merge(['list' => ['a', 'b']], ['list' => ['c']]))->toBe(['list' => ['c']]);
    config()->set('tenancy.resolvers.http', [TestTenantDirectory::class]);
    expect(fn () => app(TenancyConfiguration::class)->validate())->toThrow(TenantConfigurationInvalid::class, 'class string');
});

it('rejects an unknown configured core connection alias', function (): void {
    config()->set('tenancy.connection', 'not-configured');

    expect(fn () => app(TenancyConfiguration::class)->validate())
        ->toThrow(TenantConfigurationInvalid::class, 'configured Laravel database connection');
});

it('keeps host directory precedence and validates serializable cached class configuration', function (): void {
    app()->bind(TenantDirectory::class, TestTenantDirectory::class);
    $original = app()->getBindings()[TenantDirectory::class];
    config()->set('tenancy.directory', ['driver' => 'host', 'adapter' => TestTenantDirectory::class]);
    $cached = eval('return '.var_export(config('tenancy'), true).';');
    config()->set('tenancy', $cached);
    app(TenancyConfiguration::class)->validate();
    $provider = new TenancyServiceProvider(app());
    $provider->register();
    app()->call([$provider, 'boot']);
    expect(app()->getBindings()[TenantDirectory::class])->toBe($original);
});

it('reports feature configuration connection and adoption as separate readiness facts', function (): void {
    expect(Artisan::call('nvl:tenancy:doctor', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['configuration'])->toMatchArray(['enabled' => false, 'profile' => 'application', 'connection' => 'sqlite', 'resources' => []])
        ->and(array_column($report['checks'], 'key'))->toContain('tenancy.configuration', 'tenancy.core');
});

it('requires only an adoption adapter for a loaded zero-resource csv integration', function (): void {
    config()->set('tenancy.enabled', true);
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('providerIsLoaded')->andReturnUsing(static fn (string $provider): bool => $provider === 'Nvl\\Csv\\Providers\\CsvServiceProvider');
    $application->shouldReceive('make')->with(TenantAdoptionRegistry::class)->andReturn(app(TenantAdoptionRegistry::class));
    $ownership = new TenantOwnershipConfiguration(config(), app(TenantResourceRegistry::class), app(EffectiveTenantConnection::class), $application);
    expect($ownership->incompatibleFamilies())->toBe(['csv']);
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    $ownership->assertReady();
    expect($ownership->incompatibleFamilies())->toBe([])
        ->and(app(TenantResourceRegistry::class)->all())->toBe([]);
});

it('reports actual configured model tables and aliases without a schema probe', function (): void {
    $model = new class extends OwnedRecord
    {
        public function getTable(): string
        {
            return 'consumer_custom_records';
        }
    };
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('host.records', 'host', $model::class));
    DB::connection()->enableQueryLog();
    $report = app(TenantOwnershipConfiguration::class)->inspect();
    expect($report['resources']['host.records'])->toMatchArray(['model' => $model::class, 'table' => 'consumer_custom_records', 'connection' => 'sqlite', 'mode' => 'tenant'])
        ->and(DB::connection()->getQueryLog())->toBe([]);
});

it('treats storage exceptions as diagnostic failure without exposing SQL or credentials', function (): void {
    DB::connection()->beforeExecuting(static fn () => throw new PDOException('private-credential'));
    expect(Artisan::call('nvl:tenancy:doctor', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($output)->not->toContain('private-credential')
        ->and($report['configuration']['schema'])->toBe('unavailable')
        ->and(array_column($report['checks'], 'key'))->toContain('tenancy.storage');
});

it('shows feature and connection facts in human-readable doctor output', function (): void {
    expect(Artisan::call('nvl:tenancy:doctor'))->toBe(0);
    expect(Artisan::output())->toContain('tenancy.feature', 'disabled', 'tenancy.connection', 'sqlite');
});
