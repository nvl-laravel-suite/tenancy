<?php

declare(strict_types=1);

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Queue\TenantCallQueuedHandler;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;
use Nvl\Tenancy\Services\TenantMaintenanceQueueGuard;
use Nvl\Tenancy\Services\TenantQueueContext;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\EncryptedGlobalProbeJob;
use Nvl\Tenancy\Tests\Fixtures\EncryptedProbeTenantJob;
use Nvl\Tenancy\Tests\Fixtures\GlobalMailableWrapper;
use Nvl\Tenancy\Tests\Fixtures\MaintenanceProbeJob;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\ProbeRestoredModel;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantEvent;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantJob;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantListener;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantMail;
use Nvl\Tenancy\Tests\Fixtures\ProbeTenantNotification;
use Nvl\Tenancy\Tests\Fixtures\QueueModelIdentifierSubtype;
use Nvl\Tenancy\Tests\Fixtures\QueueNamedConnectionModel;
use Nvl\Tenancy\Tests\Fixtures\QueueProbeInstallation;
use Nvl\Tenancy\Tests\Fixtures\UniqueProbeTenantJob;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

beforeEach(function (): void {
    config()->set('tenancy.enabled', true);
    $this->a = new TenantId('10000000-0000-4000-8000-000000000001');
    $this->b = new TenantId('10000000-0000-4000-8000-000000000002');
    app()->instance(TenantDirectory::class, new ArrayTenantDirectory([
        $this->a->value => new TenantDescriptor($this->a, TenantStatus::Active),
        $this->b->value => new TenantDescriptor($this->b, TenantStatus::Active),
    ]));
    ProbeTenantJob::$observations = [];
    ProbeRestoredModel::$restored = [];
});

/** Create Laravel's actual final payload while the producer tenant is active. */
function queueProbePayload(TenantId $tenant, ?ProbeTenantJob $probe = null): string
{
    return app(TenantRunner::class)->run($tenant, fn () => (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))->invoke(Queue::connection('sync'), $probe ?? new ProbeTenantJob, 'default'));
}

it('restores before command deserialization and restores the calling tenant', function (): void {
    $payload = queueProbePayload($this->a);
    app(TenantRunner::class)->run($this->b, function () use ($payload): void {
        (new SyncJob(app(), $payload, 'sync', 'default'))->fire();
        expect(app(TenantContext::class)->requireTenant()->value)->toBe($this->b->value);
    });
    expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value])
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('restores before failure deserialization when handling never started', function (): void {
    $job = new SyncJob(app(), queueProbePayload($this->a), 'sync', 'default');
    $job->fail(new RuntimeException('Attempts exhausted before handle.'));
    expect(array_column(ProbeTenantJob::$observations, 'stage'))->toBe(['unserialize', 'failed'])
        ->and(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value]);
});

it('rejects missing metadata before both normal and failure command deserialization', function (bool $failure): void {
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    unset($payload['data']['nvl_tenancy']);
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $failure ? $job->fail(new RuntimeException) : $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
})->with([false, true]);

it('rejects uncaptured tenant jobs even when a different tenant is active at serialization', function (): void {
    $job = new MaintenanceProbeJob;
    expect(fn () => app(TenantRunner::class)->run($this->b, fn () => Queue::push($job)))
        ->toThrow(TenantBoundaryViolation::class);
});

it('captures native afterResponse work before the callback runs under another tenant', function (): void {
    $dispatcher = app(Dispatcher::class);
    app(TenantRunner::class)->run($this->a, fn () => $dispatcher->dispatchAfterResponse(new ProbeTenantJob));
    app(TenantRunner::class)->run($this->b, fn () => app()->terminate());
    expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value])
        ->and(app(Dispatcher::class))->toBe($dispatcher);
});

it('retains capture when sync afterCommit delays payload creation until a real commit', function (): void {
    $probe = app(TenantRunner::class)->run($this->a, fn () => (new ProbeTenantJob)->afterCommit());
    app(TenantRunner::class)->run($this->b, function () use ($probe): void {
        DB::transaction(function () use ($probe): void {
            Bus::dispatchSync($probe);
            expect(ProbeTenantJob::$observations)->toBe([]);
        });
        expect(app(TenantContext::class)->requireTenant()->value)->toBe($this->b->value);
    });
    expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value]);
});

it('rejects malformed envelopes before all user deserialization', function (array $metadata): void {
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = $metadata;
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
})->with([
    [['version' => 2, 'mode' => 'tenant', 'tenant_id' => '10000000-0000-4000-8000-000000000001']],
    [['version' => 1, 'mode' => 'platform', 'tenant_id' => null]],
    [['version' => 1, 'mode' => 'disabled', 'tenant_id' => null]],
    [['version' => 1, 'mode' => 'tenant', 'tenant_id' => ['foreign']]],
]);

it('preserves nested host data hooks and native serialized command fields', function (): void {
    Queue::createPayloadUsing(null);
    Queue::createPayloadUsing(static fn (?string $connection, ?string $queue, array $payload): array => ['data' => array_merge($payload['data'], ['host' => ['trace' => 'retained']])]);
    TenantMaintenanceQueueGuard::register();
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['host'])->toBe(['trace' => 'retained'])
        ->and($payload['data']['commandName'])->toBe(ProbeTenantJob::class)
        ->and($payload['data']['command'])->toBeString()
        ->and($payload['data']['nvl_tenancy'])->toBe(['version' => 1, 'mode' => 'tenant', 'tenant_id' => $this->a->value]);
});

it('checks carried envelope equality before handle and failure callbacks', function (): void {
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy']['tenant_id'] = $this->b->value;
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(array_column(ProbeTenantJob::$observations, 'stage'))->not->toContain('handle', 'failed');
});

it('rejects foreign canonical model ownership before restoration in normal and failed paths', function (): void {
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    QueueProbeInstallation::install();
    $foreign = ProbeRestoredModel::create(['tenant_id' => $this->b->value, 'name' => 'foreign']);
    $probe = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob(record: $foreign));
    $payload = queueProbePayload($this->a, $probe);
    $job = new SyncJob(app(), $payload, 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([])
        ->and(ProbeRestoredModel::$restored)->toBe([]);
});

it('permits only scalar registered global jobs and refuses spoofed command classes before deserialization', function (): void {
    $class = MaintenanceProbeJob::class;
    app(TenantGlobalJobRegistry::class)->register($class);
    $command = new $class;
    $payload = (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))->invoke(Queue::connection('sync'), $command, 'default');
    (new SyncJob(app(), $payload, 'sync', 'default'))->fire();
    $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $data['data']['command'] = serialize(app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob));
    $job = new SyncJob(app(), json_encode($data, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('keeps native encrypted tenant job support', function (): void {
    $probe = app(TenantRunner::class)->run($this->a, fn () => new EncryptedProbeTenantJob);
    (new SyncJob(app(), queueProbePayload($this->a, $probe), 'sync', 'default'))->fire();
    expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value]);
});

it('restores context after native exception and failure handling', function (): void {
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => Queue::push(new ProbeTenantJob(throws: true))))->toThrow(RuntimeException::class, 'Queue probe failed.');
    expect(array_column(ProbeTenantJob::$observations, 'stage'))->toBe(['unserialize', 'handle', 'unserialize', 'failed'])
        ->and(array_unique(array_column(ProbeTenantJob::$observations, 'tenant')))->toBe([$this->a->value])
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('rejects a Disabled transition in an enabled worker through the internal runner seam', function (): void {
    expect(fn () => app(TenantRunner::class)->withoutTenant(new TenantContextSnapshot(TenantContextMode::Disabled), fn () => null))->toThrow(TenantBoundaryViolation::class);
});

it('preserves compatible host handlers and diagnoses incompatible handlers', function (): void {
    $compatible = app(TenantCallQueuedHandler::class);
    app()->instance(CallQueuedHandler::class, $compatible);
    expect(app(CallQueuedHandler::class))->toBe($compatible);
    app()->instance(CallQueuedHandler::class, new CallQueuedHandler(app(Dispatcher::class), app()));
    expect(fn () => queueProbePayload($this->a))->toThrow(TenantConfigurationInvalid::class);
    $this->artisan('nvl:tenancy:doctor', ['--json' => true])->expectsOutputToContain('tenancy.queue_handler')->assertFailed();
});

it('keeps native encryption for explicitly registered scalar global identity work', function (): void {
    app(TenantGlobalJobRegistry::class)->register(EncryptedGlobalProbeJob::class);
    EncryptedGlobalProbeJob::$executions = 0;
    app(TenantRunner::class)->run($this->a, fn () => Queue::push(new EncryptedGlobalProbeJob));
    expect(EncryptedGlobalProbeJob::$executions)->toBe(1);
});

it('refuses serialized root metadata collisions before global identity deserialization', function (): void {
    $allowed = MaintenanceProbeJob::class;
    app(TenantGlobalJobRegistry::class)->register($allowed);
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['nvl_tenancy'] = ['version' => 1, 'mode' => 'unresolved', 'tenant_id' => null];
    $payload['data']['commandName'] = $allowed;
    $foreign = ProbeTenantJob::class;
    $payload['data']['command'] = 'O:'.strlen($foreign).':"'.$foreign.'":1:{s:27:"__PHP_Incomplete_Class_Name";s:'.strlen($allowed).':"'.$allowed.'";}';
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('preserves captured context through native chains and batch callbacks', function (): void {
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    app(TenantRunner::class)->run($this->a, function (): void {
        Bus::chain([new ProbeTenantJob('chain-first'), new ProbeTenantJob('chain-next')])->dispatch();
        app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob('batch')])->then(static function (): void {
            ProbeTenantJob::$observations[] = ['stage' => 'batch-then', 'tenant' => app(TenantContext::class)->requireTenant()->value, 'label' => 'batch'];
        }))->dispatch();
    });
    expect(array_unique(array_column(ProbeTenantJob::$observations, 'tenant')))->toBe([$this->a->value])
        ->and(array_column(ProbeTenantJob::$observations, 'stage'))->toContain('batch-then')
        ->and(array_column(ProbeTenantJob::$observations, 'label'))->toContain('chain-next');
});

it('acquires distinct producer unique locks and consumer overlap locks from captured tenant IDs', function (): void {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    foreach ([$this->a, $this->b, $this->a] as $tenant) {
        app(TenantRunner::class)->run($tenant, fn () => UniqueProbeTenantJob::dispatch('same')->onConnection('database'));
    }
    expect(DB::table('jobs')->count())->toBe(2);
    $held = new WithoutOverlapping($this->a->value.':same');
    $probe = app(TenantRunner::class)->run($this->a, fn () => new UniqueProbeTenantJob('same'));
    $lock = Cache::lock($held->getLockKey($probe), 30);
    expect($lock->get())->toBeTrue();
    try {
        $a = Queue::connection('database')->pop();
        $a->fire();
        expect($a->isReleased())->toBeTrue()->and(ProbeTenantJob::$observations)->toHaveCount(1);
        $b = Queue::connection('database')->pop();
        // The released A may become available immediately; reserve it so B is selected next.
        if ($b->payload()['data']['nvl_tenancy']['tenant_id'] === $this->a->value) {
            $b = Queue::connection('database')->pop();
        }
        $b->fire();
        expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toContain($this->b->value);
    } finally {
        $lock->release();
    }
});

it('keeps retained queue services safe across two requests in one application instance', function (): void {
    $service = app(TenantQueueContext::class);
    $envelopes = [];
    foreach ([$this->a, $this->b] as $tenant) {
        $envelopes[$tenant->value] = app(TenantRunner::class)->run($tenant, fn () => TenantJobEnvelope::capture(app(TenantContext::class)));
    }
    Route::get('/queue-context/{tenant}', fn (string $tenant) => ['tenant' => $service->run($envelopes[$tenant], fn () => app(TenantContext::class)->requireTenant()->value)]);
    $this->get('/queue-context/'.$this->a->value)->assertOk()->assertJson(['tenant' => $this->a->value]);
    $old = app(TenantContext::class);
    app()->forgetScopedInstances();
    $this->get('/queue-context/'.$this->b->value)->assertOk()->assertJson(['tenant' => $this->b->value]);
    expect($old->snapshot()->mode)->toBe(TenantContextMode::Unresolved)
        ->and(app(TenantContext::class))->not->toBe($old)
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('restores an owned model and rechecks changed persisted ownership before retry', function (): void {
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    QueueProbeInstallation::install();
    $record = ProbeRestoredModel::create(['tenant_id' => $this->a->value, 'name' => 'owned']);
    $probe = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob(record: $record));
    $payload = queueProbePayload($this->a, $probe);
    (new SyncJob(app(), $payload, 'sync', 'default'))->fire();
    expect(array_column(ProbeTenantJob::$observations, 'tenant'))->toBe([$this->a->value, $this->a->value]);
    ProbeTenantJob::$observations = [];
    DB::table('tenancy_test_records')->where('id', $record->id)->update(['tenant_id' => $this->b->value]);
    expect(fn () => (new SyncJob(app(), $payload, 'sync', 'default'))->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('rejects unsupported model relations and custom collections before command deserialization', function (string $shape): void {
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    QueueProbeInstallation::install();
    $record = ProbeRestoredModel::create(['tenant_id' => $this->a->value, 'name' => 'owned']);
    if ($shape === 'relations') {
        $record->setRelation('parent', $record);
    }
    $probe = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob(record: $record));
    $payload = json_decode(queueProbePayload($this->a, $probe), true, flags: JSON_THROW_ON_ERROR);
    if ($shape === 'collection') {
        $payload['data']['command'] = str_replace('s:15:"collectionClass";N;', 's:15:"collectionClass";s:8:"stdClass";', $payload['data']['command']);
    }
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
})->with(['relations', 'collection']);

it('rejects nested incomplete-class metadata collisions before command deserialization', function (): void {
    $payload = json_decode(queueProbePayload($this->a), true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['command'] = str_replace('s:5:"probe";', 'O:8:"stdClass":1:{s:27:"__PHP_Incomplete_Class_Name";s:8:"stdClass";}', $payload['data']['command']);
    expect(fn () => (new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default'))->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('validates chained command envelopes before native chain deserialization', function (): void {
    $next = app(TenantRunner::class)->run($this->b, fn () => new ProbeTenantJob('next-B'));
    $first = app(TenantRunner::class)->run($this->a, fn () => (new ProbeTenantJob('first-A'))->chain([$next]));
    expect(fn () => (new SyncJob(app(), queueProbePayload($this->a, $first), 'sync', 'default'))->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('preserves disabled legacy dispatch but rejects disabled adopted storage', function (): void {
    config()->set('tenancy.enabled', false);
    app()->forgetScopedInstances();
    MaintenanceProbeJob::$executions = 0;
    Queue::push(new MaintenanceProbeJob);
    expect(MaintenanceProbeJob::$executions)->toBe(1);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
    QueueProbeInstallation::install();
    config()->set('tenancy.enabled', false);
    app()->forgetScopedInstances();
    expect(fn () => Queue::push(new MaintenanceProbeJob))->toThrow(TenantSchemaNotReady::class)
        ->and(MaintenanceProbeJob::$executions)->toBe(1);
});

it('never registers generic notification or mailable wrappers as global identity work', function (string $class): void {
    expect(fn () => app(TenantGlobalJobRegistry::class)->register($class))->toThrow(TenantConfigurationInvalid::class);
})->with([SendQueuedMailable::class, SendQueuedNotifications::class]);

it('rejects host subclasses of generic framework wrappers as global identity registrations', function (): void {
    expect(fn () => app(TenantGlobalJobRegistry::class)->register(GlobalMailableWrapper::class))->toThrow(TenantConfigurationInvalid::class);
});

it('restores native queued mail notification and listener carriers before their deserialization', function (): void {
    config()->set(['mail.default' => 'array', 'mail.from.address' => 'probe@example.test']);
    Event::listen(ProbeTenantEvent::class, ProbeTenantListener::class);
    [$mail, $notification, $event] = app(TenantRunner::class)->run($this->a, fn () => [new ProbeTenantMail, new ProbeTenantNotification, new ProbeTenantEvent]);
    app(TenantRunner::class)->run($this->b, function () use ($mail, $notification, $event): void {
        Mail::to('local@example.test')->queue($mail);
        Notification::route('mail', 'local@example.test')->notify($notification);
        Event::dispatch($event);
    });
    expect(array_unique(array_column(ProbeTenantJob::$observations, 'tenant')))->toBe([$this->a->value])
        ->and(array_column(ProbeTenantJob::$observations, 'stage'))->toContain('mail', 'notification', 'listener', 'wrapper-unserialize');
});

it('rejects uncaptured or wrong-tenant native wrappers before carrier deserialization', function (): void {
    $mail = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantMail);
    $wrapper = new SendQueuedMailable($mail);
    $payload = (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))->invoke(Queue::connection('sync'), $wrapper, 'default');
    $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $data['data']['nvl_tenancy']['tenant_id'] = $this->b->value;
    $job = new SyncJob(app(), json_encode($data, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([])
        ->and(fn () => app(TenantRunner::class)->run($this->a, fn () => Queue::push(new SendQueuedMailable(new Mailable))))->toThrow(TenantBoundaryViolation::class);
});

/** Create native batch and database-queue storage without outer test transactions. */
function createTenantQueueBatchStorage(): void
{
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

it('rejects mixed-tenant and uncaptured batches before publishing any work', function (): void {
    createTenantQueueBatchStorage();
    $foreign = app(TenantRunner::class)->run($this->b, fn () => new ProbeTenantJob);
    app(TenantRunner::class)->run($this->a, function () use ($foreign): void {
        expect(fn () => app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob, $foreign])))->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => Bus::batch([new ProbeTenantJob])->onConnection('database')->dispatch())->toThrow(TenantBoundaryViolation::class);
    });
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('job_batches')->count())->toBe(0);
});

it('rejects changed persisted batch ownership before command or failure callback deserialization', function (): void {
    createTenantQueueBatchStorage();
    $pending = app(TenantRunner::class)->run($this->a, function () {
        $pending = app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob])->onConnection('database')->catch(static function (): void {
            ProbeTenantJob::$observations[] = ['stage' => 'batch-catch', 'tenant' => app(TenantContext::class)->snapshot()->tenantId?->value, 'label' => 'tampered'];
        }));
        $pending->dispatch();

        return $pending;
    });
    $pending->options['nvl_tenancy']['tenant_id'] = $this->b->value;
    DB::table('job_batches')->update(['options' => serialize($pending->options)]);
    $job = Queue::connection('database')->pop();
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('restores captured tenant for native batch failure callbacks', function (): void {
    createTenantQueueBatchStorage();
    expect(fn () => app(TenantRunner::class)->run($this->a, function (): void {
        app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob(throws: true)])->catch(static function (): void {
            ProbeTenantJob::$observations[] = ['stage' => 'batch-catch', 'tenant' => app(TenantContext::class)->requireTenant()->value, 'label' => 'failure'];
        }))->dispatch();
    }))->toThrow(RuntimeException::class, 'Queue probe failed.');
    expect(array_column(ProbeTenantJob::$observations, 'stage'))->toContain('batch-catch')
        ->and(array_unique(array_column(ProbeTenantJob::$observations, 'tenant')))->toBe([$this->a->value]);
});

it('inspects signed callback captured models before native callback deserialization', function (): void {
    createTenantQueueBatchStorage();
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    QueueProbeInstallation::install();
    $foreign = ProbeRestoredModel::create(['tenant_id' => $this->b->value, 'name' => 'foreign']);
    $pending = app(TenantRunner::class)->run($this->a, fn () => app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob])->onConnection('database')->then(static function () use ($foreign): void {
        ProbeTenantJob::$observations[] = ['stage' => 'foreign-callback', 'tenant' => $foreign->tenant_id, 'label' => 'foreign'];
    })));
    expect(serialize($pending->options))->toContain('Serializers\\Signed', 'ModelIdentifier');
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => $pending->dispatch()))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeRestoredModel::$restored)->toBe([])
        ->and(ProbeTenantJob::$observations)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('preserves and diagnoses an incompatible host batch repository', function (): void {
    $repository = new DatabaseBatchRepository(app(BatchFactory::class), DB::connection(), 'host_batches');
    app()->instance(BatchRepository::class, $repository);
    expect(app(BatchRepository::class))->toBe($repository)
        ->and(fn () => app(TenantRunner::class)->run($this->a, fn () => app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob]))))->toThrow(TenantConfigurationInvalid::class);
    $this->artisan('nvl:tenancy:doctor', ['--json' => true])->expectsOutputToContain('tenancy.batch_repository')->assertFailed();
});

it('validates the native PostgreSQL base64 batch representation before callback deserialization', function (): void {
    $connection = new PostgresConnection(new PDO('sqlite::memory:'));
    $repository = new TenantDatabaseBatchRepository(app(BatchFactory::class), $connection, 'job_batches');
    app(TenantRunner::class)->run($this->a, function () use ($repository): void {
        $options = ['nvl_tenancy' => ['version' => 1, 'mode' => 'tenant', 'tenant_id' => $this->a->value]];
        $bytes = (new ReflectionMethod($repository, 'serialize'))->invoke($repository, $options);
        expect($bytes)->toBe(base64_encode(serialize($options)))
            ->and((new ReflectionMethod($repository, 'unserialize'))->invoke($repository, $bytes))->toBe($options);
        $options['nvl_tenancy']['tenant_id'] = $this->b->value;
        expect(fn () => (new ReflectionMethod($repository, 'unserialize'))->invoke($repository, base64_encode(serialize($options))))->toThrow(TenantBoundaryViolation::class);
    });
});

it('rejects a native afterResponse batch published in a later unrelated scope', function (): void {
    createTenantQueueBatchStorage();
    app(TenantRunner::class)->run($this->a, fn () => app(TenantQueueContext::class)->captureBatch(Bus::batch([new ProbeTenantJob])->onConnection('database'))->dispatchAfterResponse());
    expect(fn () => app(TenantRunner::class)->run($this->b, fn () => app()->terminate()))->toThrow(TenantBoundaryViolation::class)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ProbeTenantJob::$observations)->toBe([]);
});

it('accepts pre-installation legacy object payloads only in disabled unadopted workers', function (): void {
    config()->set('tenancy.enabled', false);
    app()->forgetScopedInstances();
    MaintenanceProbeJob::$executions = 0;
    $payload = (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))->invoke(Queue::connection('sync'), new MaintenanceProbeJob, 'default');
    $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    unset($data['data']['nvl_tenancy']);
    (new SyncJob(app(), json_encode($data, JSON_THROW_ON_ERROR), 'sync', 'default'))->fire();
    expect(MaintenanceProbeJob::$executions)->toBe(1);
});

it('checks native null connection identity before normal and failure restoration', function (bool $failure, bool $differentDefault): void {
    $originalDefault = DB::getDefaultConnection();
    config()->set('database.connections.queue_canonical', config('database.connections.'.$originalDefault));
    config()->set('tenancy.connection', 'queue_canonical');
    DB::setDefaultConnection('queue_canonical');
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', QueueNamedConnectionModel::class));
    QueueProbeInstallation::install();
    $record = QueueNamedConnectionModel::create(['tenant_id' => $this->a->value, 'name' => 'owned']);
    $probe = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob(record: $record));
    $payload = json_decode(queueProbePayload($this->a, $probe), true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['command'] = str_replace('s:10:"connection";s:15:"queue_canonical";', 's:10:"connection";N;', $payload['data']['command'], $replacements);
    expect($replacements)->toBe(1);
    if ($differentDefault) {
        DB::setDefaultConnection($originalDefault);
        Schema::create('tenancy_test_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->softDeletes();
        });
        DB::table('tenancy_test_records')->insert(['id' => $record->id, 'tenant_id' => $this->b->value, 'name' => 'foreign default']);
    }
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    $execute = fn () => $failure ? $job->fail(new RuntimeException) : $job->fire();
    if ($differentDefault) {
        expect($execute)->toThrow(TenantBoundaryViolation::class)
            ->and(ProbeTenantJob::$observations)->toBe([])
            ->and(ProbeRestoredModel::$restored)->toBe([]);
    } else {
        $execute();
        expect(array_column(ProbeTenantJob::$observations, 'stage'))->toBe(['unserialize', $failure ? 'failed' : 'handle'])
            ->and(ProbeRestoredModel::$restored)->toBe([$record->id]);
    }
})->with([false, true])->with([false, true]);

it('recognizes native identifier hierarchy aliases and case before foreign restoration', function (string $representation): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    QueueProbeInstallation::install();
    $foreign = ProbeRestoredModel::create(['tenant_id' => $this->b->value, 'name' => 'foreign']);
    $probe = app(TenantRunner::class)->run($this->a, fn () => new ProbeTenantJob(record: $foreign));
    $payload = json_decode(queueProbePayload($this->a, $probe), true, flags: JSON_THROW_ON_ERROR);
    $base = ModelIdentifier::class;
    if (! class_exists('QueueIdentifierAlias', false)) {
        class_alias(QueueModelIdentifierSubtype::class, 'QueueIdentifierAlias');
        class_alias($base, 'QueueBaseIdentifierAlias');
    }
    $class = match ($representation) {
        'subtype' => QueueModelIdentifierSubtype::class,
        'subtype-case' => strtolower(QueueModelIdentifierSubtype::class),
        'subtype-alias' => 'QueueIdentifierAlias',
        'base-case' => strtolower($base),
        'base-alias' => 'QueueBaseIdentifierAlias',
    };
    $payload['data']['command'] = str_replace('O:'.strlen($base).':"'.$base.'":', 'O:'.strlen($class).':"'.$class.'":', $payload['data']['command'], $replacements);
    expect($replacements)->toBe(1);
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $job->fire())->toThrow(TenantBoundaryViolation::class)
        ->and(fn () => $job->fail(new RuntimeException))->toThrow(TenantBoundaryViolation::class)
        ->and(ProbeTenantJob::$observations)->toBe([])
        ->and(ProbeRestoredModel::$restored)->toBe([]);
})->with(['subtype', 'subtype-case', 'subtype-alias', 'base-case', 'base-alias']);

it('admits disabled direct batch reads before any callback object restoration', function (bool $adopted, string $metadata): void {
    createTenantQueueBatchStorage();
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
    if ($adopted) {
        QueueProbeInstallation::install();
    } else {
        Schema::create('tenancy_test_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->softDeletes();
        });
    }
    $record = ProbeRestoredModel::create(['tenant_id' => $this->a->value, 'name' => 'batch model']);
    config()->set('tenancy.enabled', false);
    app()->forgetScopedInstances();
    $probe = new ProbeTenantJob;
    $pending = Bus::batch([])->then(static function () use ($record, $probe): void {
        $probe->handle();
        ProbeRestoredModel::$restored[] = $record->id;
    });
    if ($metadata !== 'absent') {
        $pending->options['nvl_tenancy'] = match ($metadata) {
            'tenant' => ['version' => 1, 'mode' => 'tenant', 'tenant_id' => $this->a->value],
            'disabled' => ['version' => 1, 'mode' => 'disabled', 'tenant_id' => null],
            'malformed' => null,
        };
    }
    $bytes = serialize($pending->options);
    expect($bytes)->toContain('Serializers\\Signed', 'ModelIdentifier');
    DB::table('job_batches')->insert(['id' => 'direct-read', 'name' => '', 'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => $bytes, 'created_at' => time()]);
    $read = fn () => app(BatchRepository::class)->find('direct-read');
    if ($metadata === 'tenant' || $metadata === 'malformed' || $adopted) {
        expect($read)->toThrow($metadata === 'tenant' || $metadata === 'malformed' ? TenantBoundaryViolation::class : TenantSchemaNotReady::class)
            ->and(ProbeRestoredModel::$restored)->toBe([])
            ->and(ProbeTenantJob::$observations)->toBe([]);
    } else {
        expect($read()?->id)->toBe('direct-read')
            ->and(ProbeRestoredModel::$restored)->toBe([$record->id])
            ->and(array_column(ProbeTenantJob::$observations, 'stage'))->toBe(['unserialize']);
    }
})->with([false, true])->with(['tenant', 'absent', 'disabled', 'malformed']);
