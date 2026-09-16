<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('runs a real database worker across tenants malformed payloads and exhausted failures', function (): void {
    $root = dirname(__DIR__, 5);
    $consumer = sys_get_temp_dir().'/nvl-tenancy-worker-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    try {
        $files->copyDirectory($root.'/vendor/orchestra/testbench-core/laravel', $consumer);
        $files->cleanDirectory($consumer.'/bootstrap/cache');
        symlink($root.'/vendor', $consumer.'/vendor');
        touch($consumer.'/database/queue.sqlite');
        $bootstrap = <<<'PHP_BOOT'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        Nvl\Support\Providers\SupportServiceProvider::class,
        Nvl\Data\Providers\DataServiceProvider::class,
        Nvl\Tenancy\Providers\TenancyServiceProvider::class,
        Nvl\Tenancy\Tests\Fixtures\WorkerProbeProvider::class,
    ])->withExceptions()->withMiddleware()->create();
PHP_BOOT;
        file_put_contents($consumer.'/bootstrap/app.php', $bootstrap);
        file_put_contents($consumer.'/bootstrap/cache/packages.php', '<?php return [];');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $consumer.'/database/queue.sqlite', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'APP_PACKAGES_CACHE' => $consumer.'/bootstrap/cache/packages.php', 'APP_SERVICES_CACHE' => $consumer.'/bootstrap/cache/services.php'];
        $seed = new Process([PHP_BINARY, __DIR__.'/../Fixtures/queue-worker-seed.php', $consumer], $consumer, $env);
        $seed->setTimeout(30)->run();
        expect($seed->isSuccessful())->toBeTrue($seed->getOutput().$seed->getErrorOutput());
        $worker = new Process([PHP_BINARY, $consumer.'/artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0', '--no-interaction'], $consumer, $env);
        $worker->setTimeout(30)->run();
        expect($worker->isSuccessful())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
        $database = new PDO('sqlite:'.$consumer.'/database/queue.sqlite');
        $rows = $database->query('select stage, tenant, label from queue_probes order by id')->fetchAll(PDO::FETCH_ASSOC);
        $a = '10000000-0000-4000-8000-000000000001';
        $b = '10000000-0000-4000-8000-000000000002';
        expect($rows)->toBe([
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'A'], ['stage' => 'handle', 'tenant' => $a, 'label' => 'A'],
            ['stage' => 'unserialize', 'tenant' => $b, 'label' => 'B'], ['stage' => 'handle', 'tenant' => $b, 'label' => 'B'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'failure-A'], ['stage' => 'handle', 'tenant' => $a, 'label' => 'failure-A'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'failure-A'], ['stage' => 'failed', 'tenant' => $a, 'label' => 'failure-A'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'exhausted-A'], ['stage' => 'failed', 'tenant' => $a, 'label' => 'exhausted-A'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'retry-A'], ['stage' => 'handle', 'tenant' => $a, 'label' => 'retry-A'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'owned-model'], ['stage' => 'model-restored', 'tenant' => $a, 'label' => 'owned-model'], ['stage' => 'handle', 'tenant' => $a, 'label' => 'owned-model'],
            ['stage' => 'unserialize', 'tenant' => $a, 'label' => 'retry-A'], ['stage' => 'handle', 'tenant' => $a, 'label' => 'retry-A'],
        ])->and($database->query('select distinct mode from worker_scopes')->fetchAll(PDO::FETCH_COLUMN))->toBe(['unresolved'])
            ->and((int) $database->query('select count(*) from jobs')->fetchColumn())->toBe(0)
            ->and((int) $database->query('select count(distinct pid) from worker_scopes')->fetchColumn())->toBe(1);
    } finally {
        $files->deleteDirectory($consumer);
    }
});
