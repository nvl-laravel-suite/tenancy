<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use __PHP_Incomplete_Class;
use Illuminate\Bus\BatchRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use Throwable;

/** Inspects native PHP payload data without instantiating command or model classes. @internal */
final readonly class TenantQueueCommand
{
    /** Resolve ownership services from the current worker scope. */
    public function __construct(private Container $container) {}

    /**
     * Validate the native root class and all supported model identifiers before user deserialization.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data, TenantJobEnvelope $envelope): void
    {
        if ($envelope->context->mode === TenantContextMode::Tenant
            && (! is_string($data['commandName']) || (! is_subclass_of($data['commandName'], TenantQueuedJob::class) && ! $this->container->make(TenantQueueCarrier::class)->nativeWrapper($data['commandName'])))) {
            throw new TenantBoundaryViolation('Enabled tenant commands require the captured envelope contract.');
        }
        $serialized = $data['command'];
        if (! is_string($serialized) || ! is_string($data['commandName'])) {
            throw new TenantBoundaryViolation('The queued command representation is invalid.');
        }
        if (! str_starts_with($serialized, 'O:')) {
            if (! $this->container->bound(Encrypter::class)) {
                throw new TenantBoundaryViolation('Encrypted queue jobs require the native encrypter.');
            }
            $plaintext = $this->container->make(Encrypter::class)->decrypt($serialized, false);
            if (! is_string($plaintext)) {
                throw new TenantBoundaryViolation('The native queue encrypter must return a serialized string.');
            }
            $serialized = $this->decode($plaintext);
            if (! is_string($serialized)) {
                throw new TenantBoundaryViolation('The encrypted queue command must contain a serialized string.');
            }
        }
        $header = 'O:'.strlen($data['commandName']).':"'.$data['commandName'].'":';
        if (! str_starts_with($serialized, $header)) {
            throw new TenantBoundaryViolation('The serialized root class differs from its payload declaration.');
        }
        $value = $this->decode($serialized);
        if (! $value instanceof __PHP_Incomplete_Class || ((array) $value)['__PHP_Incomplete_Class_Name'] !== $data['commandName']) {
            throw new TenantBoundaryViolation('The queued command class differs from its payload declaration.');
        }
        $properties = (array) $value;
        unset($properties['__PHP_Incomplete_Class_Name']);
        if ($envelope->context->mode === TenantContextMode::Tenant) {
            $carriers = match ($data['commandName']) {
                SendQueuedMailable::class => [$properties['mailable'] ?? null],
                SendQueuedNotifications::class => [$properties['notification'] ?? null],
                CallQueuedListener::class => is_array($properties['data'] ?? null) ? $properties['data'] : [],
                default => [$value],
            };
            $found = false;
            foreach ($carriers as $carrier) {
                if ($carrier instanceof __PHP_Incomplete_Class && is_string(((array) $carrier)['__PHP_Incomplete_Class_Name'] ?? null)
                    && is_subclass_of(((array) $carrier)['__PHP_Incomplete_Class_Name'], TenantQueuedJob::class)
                    && $this->containsEnvelope($carrier, 0)) {
                    $found = true;
                } elseif (is_object($carrier)) {
                    throw new TenantBoundaryViolation('The native wrapper carrier lacks explicit tenant capture.');
                }
            }
            if (! $found) {
                throw new TenantBoundaryViolation('Tenant commands and native wrappers must carry a serialized captured envelope.');
            }
            $batchId = $properties['batchId'] ?? null;
            if ($batchId !== ($data['batchId'] ?? null)) {
                throw new TenantBoundaryViolation('The serialized command differs from its declared batch.');
            }
            if (is_string($batchId)) {
                $repository = $this->container->make(BatchRepository::class);
                if (! TenantDatabaseBatchRepository::compatible($repository)) {
                    throw new TenantConfigurationInvalid('Tenant batches require a compatible native database batch repository.');
                }
                $batch = $repository->find($batchId);
                if ($batch === null || ($batch->options['nvl_tenancy'] ?? null) !== $this->container->make(TenantQueuePayload::class)->encode($envelope)) {
                    throw new TenantBoundaryViolation('The persisted batch differs from the queued command context.');
                }
            }
        }
        $this->inspect($properties, $envelope, 0);
    }

    /** Parse with all user classes disabled; malformed bytes never reach native deserialization. */
    private function decode(string $serialized): mixed
    {
        if (str_contains($serialized, '__PHP_Incomplete_Class_Name')) {
            throw new TenantBoundaryViolation('Reserved serialization inspection metadata is not supported in queued commands.');
        }
        set_error_handler(static function (): never {
            throw new TenantBoundaryViolation('The serialized queued command is malformed.');
        });
        try {
            return unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 64]);
        } catch (Throwable) {
            throw new TenantBoundaryViolation('The serialized queued command is malformed.');
        } finally {
            restore_error_handler();
        }
    }

    /** Inspect inert values with bounded depth, including collections and private job properties. */
    private function inspect(mixed $value, TenantJobEnvelope $envelope, int $depth): void
    {
        if ($depth > 64) {
            throw new TenantBoundaryViolation('The queued command graph exceeds the supported depth.');
        }
        if (is_object($value)) {
            if ($envelope->context->mode === TenantContextMode::Unresolved) {
                throw new TenantBoundaryViolation('Global identity jobs support scalar properties and arrays only.');
            }
            if ($value instanceof __PHP_Incomplete_Class) {
                $properties = (array) $value;
                $class = $properties['__PHP_Incomplete_Class_Name'];
                if ($class === 'Laravel\\SerializableClosure\\Serializers\\Signed') {
                    if (! is_string($properties['serializable'] ?? null)) {
                        throw new TenantBoundaryViolation('Signed callbacks require their native serialized closure representation.');
                    }
                    $this->inspect($this->decode($properties['serializable']), $envelope, $depth + 1);
                }
                if (is_string($class) && is_a($class, Model::class, true) && $envelope->context->mode === TenantContextMode::Tenant) {
                    throw new TenantBoundaryViolation('Hydrated model objects cannot replace canonical queued model identifiers.');
                }
                if ($class === TenantJobEnvelope::class && $envelope->context->mode === TenantContextMode::Tenant) {
                    $context = is_object($properties['context'] ?? null) ? (array) $properties['context'] : [];
                    $tenant = is_object($context['tenantId'] ?? null) ? (array) $context['tenantId'] : [];
                    if (($properties['version'] ?? null) !== 1 || ($context['mode'] ?? null) !== TenantContextMode::Tenant
                        || ($tenant['value'] ?? null) !== $envelope->context->tenantId?->value) {
                        throw new TenantBoundaryViolation('A carried envelope differs from the queued tenant boundary.');
                    }
                }
                if ($class === ModelIdentifier::class && $envelope->context->mode === TenantContextMode::Tenant) {
                    $this->validateModel($properties);
                }
                unset($properties['__PHP_Incomplete_Class_Name']);
                $this->inspect($properties, $envelope, $depth + 1);
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === 'chained' && is_array($item)) {
                    foreach ($item as $serialized) {
                        if (! is_string($serialized) || preg_match('/\AO:([0-9]+):"([^"]+)":/', $serialized, $matches) !== 1
                            || (int) $matches[1] !== strlen($matches[2])) {
                            throw new TenantBoundaryViolation('Chains require native serialized object commands.');
                        }
                        $chain = $this->decode($serialized);
                        if (! $chain instanceof __PHP_Incomplete_Class || ((array) $chain)['__PHP_Incomplete_Class_Name'] !== $matches[2]) {
                            throw new TenantBoundaryViolation('The chained command representation is invalid.');
                        }
                        if ($envelope->context->mode === TenantContextMode::Tenant
                            && (! is_subclass_of($matches[2], TenantQueuedJob::class) || ! $this->containsEnvelope($chain, $depth + 1))) {
                            throw new TenantBoundaryViolation('Chained tenant jobs require the captured envelope contract.');
                        }
                        $this->inspect($chain, $envelope, $depth + 1);
                    }
                }
                $this->inspect($item, $envelope, $depth + 1);
            }
        }
    }

    /** Recheck canonical ownership of restored models before handle or failed callbacks. */
    public function validateRestored(mixed $command): void
    {
        $seen = new \SplObjectStorage;
        $this->inspectRestored($command, $seen, 0);
    }

    /** @param \SplObjectStorage<object, mixed> $seen */
    private function inspectRestored(mixed $value, \SplObjectStorage $seen, int $depth): void
    {
        if ($depth > 64) {
            throw new TenantBoundaryViolation('The restored command graph exceeds the supported depth.');
        }
        if (is_object($value)) {
            if ($seen->contains($value)) {
                return;
            }
            $seen->attach($value);
            if ($value instanceof Model) {
                $resource = $this->container->make(TenantResourceRegistry::class)->forModel($value);
                $this->container->make(TenantBoundary::class)->assertRecord($value, $resource->key);

                return;
            }
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->inspectRestored($item, $seen, $depth + 1);
            }
        }
    }

    /** Locate immutable capture in inert objects without invoking carrier code. */
    private function containsEnvelope(mixed $value, int $depth): bool
    {
        if ($depth > 64) {
            return false;
        }
        if ($value instanceof __PHP_Incomplete_Class) {
            $value = (array) $value;
            if (($value['__PHP_Incomplete_Class_Name'] ?? null) === TenantJobEnvelope::class) {
                return true;
            }
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsEnvelope($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Decode and inspect native batch options without executing callbacks. @internal */
    public function batchEnvelope(string $serialized): TenantJobEnvelope
    {
        $options = $this->decode($serialized);
        if (! is_array($options) || ! is_array($options['nvl_tenancy'] ?? null)) {
            throw new TenantBoundaryViolation('Tenant batches require explicitly captured options.');
        }
        $envelope = $this->container->make(TenantQueuePayload::class)->decode($options['nvl_tenancy']);
        $current = TenantJobEnvelope::capture($this->container->make(TenantContext::class));
        if ($envelope->context->mode !== TenantContextMode::Tenant
            || $this->container->make(TenantQueuePayload::class)->encode($current) !== $options['nvl_tenancy']) {
            throw new TenantBoundaryViolation('Persisted batch callbacks belong to a different tenant scope.');
        }
        $this->container->make(TenantOwnershipConfiguration::class)->assertReady();
        $this->inspect($options, $envelope, 0);

        return $envelope;
    }

    /** @param array<array-key, mixed> $properties */
    private function validateModel(array $properties): void
    {
        $definition = null;
        foreach ($this->container->make(TenantResourceRegistry::class)->all() as $resource) {
            if ($resource->model === ($properties['class'] ?? null)) {
                $definition = $resource;
                break;
            }
        }
        if ($definition === null || ($properties['relations'] ?? null) !== [] || ($properties['collectionClass'] ?? null) !== null) {
            throw new TenantBoundaryViolation('Queued model restoration requires a registered resource without serialized relations or custom collections.');
        }
        $model = new $definition->model;
        $connection = $properties['connection'] ?? null;
        if ($connection !== null && $connection !== $model->getConnection()->getName()) {
            throw new TenantBoundaryViolation('Queued models must use their canonical connection.');
        }
        $ids = $properties['id'] ?? null;
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            if (! is_string($id) && ! is_int($id)) {
                throw new TenantBoundaryViolation('Queued model identifiers must be scalar.');
            }
            $record = new $definition->model;
            $record->setRawAttributes([$record->getKeyName() => $id], true);
            $record->exists = true;
            $this->container->make(TenantBoundary::class)->assertRecord($record, $definition->key);
        }
    }
}
