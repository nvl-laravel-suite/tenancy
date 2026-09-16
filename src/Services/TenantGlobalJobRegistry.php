<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionClass;

/** Explicitly admits specific application-owned global identity jobs. */
final class TenantGlobalJobRegistry
{
    /** @var array<class-string, true> */
    private array $jobs = [];

    /** @param class-string $jobClass */
    public function register(string $jobClass): void
    {
        if (! class_exists($jobClass) || ! is_subclass_of($jobClass, ShouldQueue::class)
            || ! (new ReflectionClass($jobClass))->isInstantiable()
            || str_starts_with($jobClass, 'Illuminate\\')) {
            throw new TenantConfigurationInvalid('Global identity jobs must be specific application queue classes, never framework wrappers.');
        }
        $class = new ReflectionClass($jobClass);
        do {
            if (str_starts_with($class->getName(), 'Illuminate\\') || $class->isAnonymous()) {
                throw new TenantConfigurationInvalid('Generic framework wrappers cannot become global identity jobs through inheritance.');
            }
            $class = $class->getParentClass();
        } while ($class !== false);
        $this->jobs[$jobClass] = true;
    }

    /** Check an exact class registration without constructing it. @internal */
    public function allows(string $jobClass): bool
    {
        return isset($this->jobs[$jobClass]);
    }
}
