<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ProbeRestoredModel;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantJob;
use Nvl\Tenancy\Tests\Fixtures\QueueProbeInstallation;
use Nvl\Tenancy\ValueObjects\TenantId;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid consumer application.');
}
$app->make(Kernel::class)->bootstrap();
Schema::create('jobs', function (Blueprint $table): void {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});
Schema::create('queue_probes', function (Blueprint $table): void {
    $table->id();
    $table->string('stage');
    $table->string('tenant')->nullable();
    $table->string('label');
});
Schema::create('worker_scopes', function (Blueprint $table): void {
    $table->id();
    $table->integer('pid');
    $table->string('mode');
});
$a = new TenantId('10000000-0000-4000-8000-000000000001');
$b = new TenantId('10000000-0000-4000-8000-000000000002');
foreach ([[$a, 'A', false], [$b, 'B', false], [$a, 'malformed', false], [$a, 'failure-A', true], [$a, 'exhausted-A', false], [$a, 'retry-A', false]] as [$tenant, $label, $throws]) {
    $id = app(TenantRunner::class)->run($tenant, function () use ($label, $throws): mixed {
        $probe = new ProbeTenantJob($label, $throws);
        if ($label === 'retry-A') {
            $probe->tries = 2;
        }

        return Queue::push($probe);
    });
    if ($label === 'malformed') {
        $serialized = DB::table('jobs')->where('id', $id)->value('payload');
        if (! is_string($serialized)) {
            throw new RuntimeException('Missing queued payload.');
        }
        $payload = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null) || ! is_array($payload['data']['nvl_tenancy'] ?? null)) {
            throw new RuntimeException('Missing queued envelope.');
        }
        $payload['data']['nvl_tenancy']['version'] = 99;
        DB::table('jobs')->where('id', $id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
    if ($label === 'exhausted-A') {
        DB::table('jobs')->where('id', $id)->update(['attempts' => 1]);
    }
}

Schema::create('tenancy_test_records', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->string('name');
    $table->softDeletes();
});
QueueProbeInstallation::install();
foreach ([[$a, 'owned-model'], [$b, 'foreign-model']] as [$owner, $label]) {
    $record = ProbeRestoredModel::create(['tenant_id' => $owner->value, 'name' => $label]);
    app(TenantRunner::class)->run($a, fn () => Queue::push(new ProbeTenantJob($label, record: $record)));
}
