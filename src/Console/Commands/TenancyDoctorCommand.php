<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Tenancy\Services\TenancyDoctor;

/**
 * Renders the package-owned read-only installation diagnostics.
 */
final class TenancyDoctorCommand extends Command
{
    protected $signature = 'nvl:tenancy:doctor {--json : Output JSON} {--strict : Treat warnings as failures} {--format=text : Output format: text or json}';

    protected $description = 'Inspect tenant ownership configuration and adoption readiness';

    /** Report bounded configuration and schema readiness facts. */
    public function handle(TenancyDoctor $doctor): int
    {

        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            $this->error('Output format must be text or json.');

            return self::FAILURE;
        }
        $report = $doctor->inspect();
        $checks = $report['checks'];
        $deployment = $report['configuration'];
        $failed = collect($checks)->contains(fn (array $check): bool => ! $check['passed'] && ($this->option('strict') || $check['severity'] === 'error'));
        if ($this->option('json') || $this->option('format') === 'json') {
            $this->line(json_encode(['healthy' => ! $failed, 'configuration' => $deployment, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf('[%s] %s: %s', $check['passed'] ? 'PASS' : strtoupper($check['severity']), $check['key'], $check['message']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
