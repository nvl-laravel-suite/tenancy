<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use PDO;

/**
 * Coordinates authorized, audited, resumable package adoption without transactional DDL assumptions.
 *
 * @phpstan-import-type Graph from TenantAdoptionGraph
 * @phpstan-import-type Run from TenantAdoptionStore
 * @phpstan-import-type Checkpoints from TenantAdoptionStore
 */
final readonly class TenantAdoptionCoordinator
{
    /** Create the stable write boundary for adoption runs and installation markers. */
    public function __construct(
        private Container $container,
        private Repository $configuration,
        private EffectiveTenantConnection $connections,
        private TenantAdoptionGraph $graphs,
        private TenantAdoptionStore $store,
        private TenantAdoptionLock $lock,
        private TenantOwnershipConfiguration $ownership,
        private TenantInstallationState $installation,
        private TenantOperationRecorder $audit,
        private TenantAdoptionScope $scope,
    ) {}

    /**
     * Persist immutable reviewed input and block the selected graph before invoking package DDL.
     *
     * @param  list<string>  $packages
     * @param  iterable<TenantAssignment>  $mappings
     */
    public function prepare(array $packages, iterable $mappings, PlatformOperation $operation): TenantAdoptionPlan
    {
        return $this->mutate($operation, function () use ($packages, $mappings): TenantAdoptionPlan {
            $graph = $this->graphs->resolve($packages);
            $connection = $this->connections->core();
            $this->assertNoInterruptedRun($graph);
            $id = (string) Str::uuid();
            $configurationHash = $this->ownership->hash($graph['resources']);
            $checkpoints = ['manifest' => $graph['manifest'], 'adapters' => []];
            foreach ($graph['packages'] as $package) {
                $checkpoints['adapters'][$package] = ['prepared' => false, 'cursor' => null, 'done' => false, 'activated' => false];
            }
            try {
                $plan = $this->scope->preparation($id, fn () => $connection->transaction(function () use ($id, $configurationHash, $graph, $checkpoints, $mappings, $connection): TenantAdoptionPlan {
                    $connection->table(TenancyTables::AdoptionRuns)->insert([
                        'id' => $id, 'status' => 'prepared', 'mapping_hash' => hash('sha256', ''), 'configuration_hash' => $configurationHash,
                        'packages' => json_encode($graph['packages'], JSON_THROW_ON_ERROR), 'checkpoints' => json_encode($checkpoints, JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $mappingHash = $this->store->ingest($id, $mappings, $graph);
                    $connection->table(TenancyTables::AdoptionRuns)->where('id', $id)->update(['mapping_hash' => $mappingHash]);
                    foreach ($graph['resources'] as $resource) {
                        $connection->table(TenancyTables::InstallationState)->updateOrInsert(['resource' => $resource], [
                            'schema_version' => 1, 'state' => 'prepared', 'configuration_hash' => $this->ownership->fingerprint($resource),
                            'run_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }

                    return new TenantAdoptionPlan($id, $this->connections->name($connection->getName()), $mappingHash, $configurationHash);
                }));
            } finally {
                $this->installation->invalidate();
            }
            $this->prepareAdapters($plan, $graph, $checkpoints);

            return $plan;
        });
    }

    /** Run at most one bounded batch per selected adapter and persist stable progress. */
    public function backfill(TenantAdoptionPlan $plan, int $limit, PlatformOperation $operation): bool
    {
        if ($limit < 1 || $limit > 10000) {
            throw new TenantConfigurationInvalid('Backfill limits must be between 1 and 10000.');
        }

        return $this->mutate($operation, function () use ($plan, $limit): bool {
            [$run, $graph] = $this->validated($plan);
            if ($run['status'] === 'active') {
                return true;
            }
            $checkpoints = $run['checkpoints'];
            $this->prepareAdapters($plan, $graph, $checkpoints);
            foreach ($graph['adapters'] as $package => $adapter) {
                $checkpoint = $checkpoints['adapters'][$package];
                if ($checkpoint['done']) {
                    continue;
                }
                $result = $this->invoke($plan, $adapter, 'backfill', fn () => $adapter->backfill($plan, $checkpoint['cursor'], $limit));
                if ($result->processed < 0 || $result->processed > $limit || ($result->nextCursor !== null
                    && ($result->processed === 0 || $result->nextCursor === $checkpoint['cursor'] || $result->nextCursor === '' || strlen($result->nextCursor) > 191))) {
                    throw new TenantBoundaryViolation("The [{$package}] adoption adapter returned invalid or stuck batch progress.");
                }
                $checkpoints['adapters'][$package] = [...$checkpoint, 'cursor' => $result->nextCursor, 'done' => $result->nextCursor === null];
                $this->store->checkpoint($plan->id, 'backfilling', $checkpoints);
                $this->installation->invalidate();
                if ($result->nextCursor !== null) {
                    return false;
                }
            }
            $this->store->checkpoint($plan->id, 'backfilled', $checkpoints);
            $this->installation->invalidate();

            return true;
        });
    }

    /** Inspect every package and actual schema without changing checkpoints or authorizing mutation. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $this->assertTransactionEntry();

        return $this->lock->during($this->connections->core(), function () use ($plan): TenantVerification {
            [$run, $graph] = $this->validated($plan);

            return $this->verifyGraph($plan, $run, $graph, false);
        });
    }

    /** Apply all final constraints before publishing the entire graph as active together. */
    public function activate(TenantAdoptionPlan $plan, PlatformOperation $operation): void
    {
        $this->ownership->assertReady();
        $this->mutate($operation, function () use ($plan): void {
            [$run, $graph] = $this->validated($plan);
            if (! $this->verifyGraph($plan, $run, $graph)->passed()) {
                throw new TenantSchemaNotReady('The complete adoption graph must pass verification before activation.');
            }
            if ($run['status'] === 'active') {
                return;
            }
            $checkpoints = $run['checkpoints'];
            foreach ($graph['adapters'] as $package => $adapter) {
                // DDL may already have committed before an interruption: adapters must retry idempotently.
                $this->invoke($plan, $adapter, 'activate', fn () => $adapter->activate($plan));
                $checkpoints['adapters'][$package] = [...$checkpoints['adapters'][$package], 'activated' => true];
                $this->store->checkpoint($plan->id, 'activating', $checkpoints);
                $this->installation->invalidate();
            }
            if (! $this->verifyGraph($plan, [...$run, 'checkpoints' => $checkpoints], $graph)->passed()) {
                throw new TenantSchemaNotReady('The final adoption schema failed verification.');
            }
            $connection = $this->connections->core();
            $pdo = $connection->getPdo();
            $this->validated($plan);
            $this->assertCallbackBoundary($connection, $pdo, true, true);
            $connection->transaction(function () use ($plan, $checkpoints): void {
                $this->connections->core()->table(TenancyTables::InstallationState)->where('run_id', $plan->id)->update(['state' => 'active', 'updated_at' => now()]);
                $this->store->checkpoint($plan->id, 'active', $checkpoints);
            });
            $this->installation->invalidate();
        });
    }

    /** Reload and validate immutable input without granting permission or performing DDL. */
    public function resume(string $runId): TenantAdoptionPlan
    {
        $this->assertTransactionEntry();

        return $this->lock->during($this->connections->core(), function () use ($runId): TenantAdoptionPlan {
            $plan = $this->store->load($runId)['plan'];
            $this->validated($plan);

            return $plan;
        });
    }

    /**
     * Revalidate actual preparation schema and persist completion only after each adapter succeeds.
     *
     * @param  Graph  $graph
     * @param  Checkpoints  $checkpoints
     */
    private function prepareAdapters(TenantAdoptionPlan $plan, array $graph, array &$checkpoints): void
    {
        foreach ($graph['adapters'] as $package => $adapter) {
            $this->invoke($plan, $adapter, 'prepare', fn () => $adapter->prepare($plan));
            $checkpoints['adapters'][$package] = [...$checkpoints['adapters'][$package], 'prepared' => true];
            $this->store->checkpoint($plan->id, 'prepared', $checkpoints);
            $this->installation->invalidate();
        }
    }

    /** @param Graph $graph */
    private function assertNoInterruptedRun(array $graph): void
    {
        $connection = $this->connections->core();
        if ($connection->table(TenancyTables::InstallationState)->whereIn('resource', $graph['resources'])->where('state', '!=', 'active')->exists()) {
            throw new TenantSchemaNotReady('A selected resource has an interrupted adoption; resume its original run.');
        }
        foreach ($connection->table(TenancyTables::AdoptionRuns)->where('status', '!=', 'active')->get() as $run) {
            if (! is_string($run->packages)) {
                throw new TenantConfigurationInvalid('Invalid adoption package history.');
            }
            $packages = json_decode($run->packages, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($packages) || count(array_filter($packages, 'is_string')) !== count($packages)) {
                throw new TenantConfigurationInvalid('Invalid adoption package history.');
            }
            if (array_intersect($graph['packages'], array_filter($packages, 'is_string')) !== []) {
                throw new TenantSchemaNotReady('A selected package has an interrupted adoption; resume its original run.');
            }
        }
    }

    /** @return array{Run, Graph} */
    private function validated(TenantAdoptionPlan $plan): array
    {
        $run = $this->store->load($plan->id);
        $graph = $this->graphs->resolve($run['packages']);
        if ($run['plan'] != $plan || $graph['packages'] !== $run['packages'] || ! hash_equals($graph['manifest'], $run['checkpoints']['manifest'])
            || array_diff(array_keys($run['checkpoints']['adapters']), $run['packages']) !== []
            || array_diff($run['packages'], array_keys($run['checkpoints']['adapters'])) !== []
            || ! hash_equals($this->ownership->hash($graph['resources']), $plan->configurationHash)
            || ! hash_equals($this->store->mappingHash($plan->id, $graph), $plan->mappingHash)
            || ! in_array($run['status'], ['prepared', 'backfilling', 'backfilled', 'activating', 'active'], true)) {
            throw new TenantConfigurationInvalid('The adoption input, adapter graph, or ownership configuration changed.');
        }
        $markers = $this->connections->core()->table(TenancyTables::InstallationState)->where('run_id', $plan->id)->get();
        if ($markers->count() !== count($graph['resources'])) {
            throw new TenantSchemaNotReady('The adoption run no longer owns its complete resource graph.');
        }
        foreach ($markers as $marker) {
            if (! is_string($marker->resource) || ! in_array($marker->resource, $graph['resources'], true)
                || $marker->configuration_hash !== $this->ownership->fingerprint($marker->resource)
                || ! in_array($marker->schema_version, [1, '1'], true) || $marker->state !== ($run['status'] === 'active' ? 'active' : 'prepared')) {
                throw new TenantSchemaNotReady('The adoption installation markers changed.');
            }
        }

        return [$run, $graph];
    }

    /**
     * Verify the graph without persisting an authorization-free verification flag.
     *
     * @param  Run  $run
     * @param  Graph  $graph
     */
    private function verifyGraph(TenantAdoptionPlan $plan, array $run, array $graph, bool $requiresMaintenance = true): TenantVerification
    {
        $errors = [];
        foreach ($graph['adapters'] as $package => $adapter) {
            if (! $run['checkpoints']['adapters'][$package]['prepared'] || ! $run['checkpoints']['adapters'][$package]['done']) {
                $errors[] = $package.':backfill_incomplete';

                continue;
            }
            foreach ($this->invoke($plan, $adapter, 'verify', fn () => $adapter->verify($plan), $requiresMaintenance)->errors as $error) {
                if (mb_strlen($error) > 255) {
                    throw new TenantConfigurationInvalid('Verification errors must be bounded codes or record identifiers.');
                }
                $errors[] = $package.':'.$error;
                if (count($errors) >= 100) {
                    return new TenantVerification($errors);
                }
            }
        }

        return new TenantVerification($errors);
    }

    /**
     * Confine a registered adapter invocation to its immutable run and live connection session.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function invoke(TenantAdoptionPlan $plan, TenantAdoptionAdapter $adapter, string $phase, Closure $callback, bool $requiresMaintenance = true): mixed
    {
        $connection = $this->connections->core();
        $pdo = $connection->getPdo();
        $enabled = $this->configuration->get('tenancy.enabled') === true;
        $maintenance = $this->container->make(MaintenanceMode::class)->active();
        if ($requiresMaintenance && (! $enabled || ! $maintenance)) {
            throw new TenantBoundaryViolation('Adoption requires enabled tenancy and application maintenance mode.');
        }
        $this->validated($plan);
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);
        $result = $this->scope->during($plan->id, $adapter::class, $phase, $callback);
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);
        $this->validated($plan);
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);

        return $result;
    }

    /** Preserve live connection and maintenance state across every package callback. */
    private function assertCallbackBoundary(Connection $connection, PDO $pdo, bool $enabled, bool $maintenance): void
    {
        if ($this->connections->core() !== $connection || $connection->getRawPdo() !== $pdo || $connection->transactionLevel() !== 0
            || ($this->configuration->get('tenancy.enabled') === true) !== $enabled
            || $this->container->make(MaintenanceMode::class)->active() !== $maintenance) {
            throw new TenantBoundaryViolation('The adoption maintenance or connection boundary changed inside an adapter.');
        }
    }

    /** Reject caller-owned transactions without changing their state or invoking package code. */
    private function assertTransactionEntry(): void
    {
        foreach ($this->connections->participating() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new TenantBoundaryViolation('Adoption cannot enter inside a participating transaction.');
            }
        }
    }

    /**
     * Authorize and durably audit each mutation under a session-preserving connection lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function mutate(PlatformOperation $operation, Closure $callback): mixed
    {
        if ($this->configuration->get('tenancy.enabled') !== true || ! $this->container->make(MaintenanceMode::class)->active()) {
            throw new TenantBoundaryViolation('Adoption requires enabled tenancy and application maintenance mode.');
        }
        $this->container->make(PlatformAccess::class)->authorize($operation);
        $this->assertTransactionEntry();

        return $this->lock->during($this->connections->core(), function () use ($operation, $callback): mixed {
            $this->audit->record($operation);

            return $callback();
        });
    }
}
