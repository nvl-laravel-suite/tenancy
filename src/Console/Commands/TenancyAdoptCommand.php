<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Console\Commands;

use Generator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Nvl\Tenancy\Exceptions\TenancyException;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Runs reviewed maintenance adoption phases; actor options never replace host authorization. */
final class TenancyAdoptCommand extends Command
{
    protected $signature = 'nvl:tenancy:adopt {phase : prepare|backfill|verify|activate} {--packages=} {--mapping=} {--run=} {--limit=500} {--actor-type=} {--actor-id=} {--purpose=}';

    protected $description = 'Coordinate explicit resumable resource adoption during maintenance';

    /** Validate CLI input and delegate all lifecycle authorization to the coordinator. */
    public function handle(TenantAdoptionCoordinator $coordinator): int
    {
        try {
            $phase = $this->argument('phase');
            if (! in_array($phase, ['prepare', 'backfill', 'verify', 'activate'], true)) {
                throw new TenantConfigurationInvalid('Unknown adoption phase.');
            }
            $operation = new PlatformOperation($this->textOption('purpose'), $this->textOption('actor-type'), $this->textOption('actor-id'));
            if ($phase === 'prepare') {
                if ($this->textOption('run') !== '') {
                    throw new TenantConfigurationInvalid('Prepare creates a new reviewed run; use backfill to resume.');
                }
                $packages = array_values(array_filter(array_map('trim', explode(',', $this->textOption('packages'))), static fn (string $package): bool => $package !== ''));
                $plan = $coordinator->prepare($packages, $this->mappings($this->textOption('mapping')), $operation);
                $this->line($plan->id);

                return self::SUCCESS;
            }
            if ($this->textOption('mapping') !== '' || $this->textOption('packages') !== '') {
                throw new TenantConfigurationInvalid('Resumption uses persisted packages and mappings; replacement input is forbidden.');
            }
            $plan = $coordinator->resume($this->textOption('run'));
            if ($phase === 'backfill') {
                $limit = filter_var($this->textOption('limit'), FILTER_VALIDATE_INT);
                if (! is_int($limit)) {
                    throw new TenantConfigurationInvalid('Backfill limit must be an integer.');
                }
                $this->line($coordinator->backfill($plan, $limit, $operation) ? 'Backfill complete.' : 'Batch complete; resume backfill.');
            } elseif ($phase === 'verify') {
                $result = $coordinator->verify($plan);
                $this->line(json_encode(['passed' => $result->passed(), 'errors' => $result->errors], JSON_THROW_ON_ERROR));

                return $result->passed() ? self::SUCCESS : self::FAILURE;
            } else {
                $coordinator->activate($plan, $operation);
                $this->line('Adoption active. Restart drained processes before reopening traffic.');
            }

            return self::SUCCESS;
        } catch (TenancyException|InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** Return a scalar CLI option without silently coercing unexpected shapes. */
    private function textOption(string $name): string
    {
        $value = $this->option($name);
        if ($value !== null && ! is_string($value)) {
            throw new TenantConfigurationInvalid('Adoption options must be scalar strings.');
        }

        return $value ?? '';
    }

    /** Stream bounded JSONL lines into typed reviewed assignments. @return Generator<int, TenantAssignment> */
    private function mappings(string $path): Generator
    {
        if ($path === '') {
            return;
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new TenantConfigurationInvalid('The reviewed mapping file is not readable.');
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new TenantConfigurationInvalid('The reviewed mapping file cannot be opened.');
        }
        try {
            while (($line = fgets($handle, 65538)) !== false) {
                if (strlen($line) > 65536) {
                    throw new TenantConfigurationInvalid('A mapping line exceeds 65536 bytes.');
                }
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($row) || array_diff(array_keys($row), ['resource', 'record_id', 'tenant_id', 'metadata']) !== []
                    || ! is_string($row['resource'] ?? null) || ! is_string($row['record_id'] ?? null) || ! is_string($row['tenant_id'] ?? null)
                    || (array_key_exists('metadata', $row) && ! is_array($row['metadata']))) {
                    throw new TenantConfigurationInvalid('Invalid reviewed JSONL assignment shape.');
                }
                /** @var array<string, mixed> $metadata */
                $metadata = $row['metadata'] ?? [];
                yield new TenantAssignment($row['resource'], $row['record_id'], new TenantId($row['tenant_id']), $metadata);
            }
            if (! feof($handle)) {
                throw new TenantConfigurationInvalid('The reviewed mapping file could not be read completely.');
            }
        } finally {
            fclose($handle);
        }
    }
}
