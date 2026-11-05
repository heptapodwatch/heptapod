<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Replace local demo data with the selected development scenario')]
#[Signature('heptapod:seed-demo {--scenario=attention : calm, attention, incident, empty, many-sites, maintenance, stopped, agency}')]
final class SeedDemoCommand extends Command
{
    public function handle(): int
    {
        $scenario = $this->option('scenario');
        if (! is_string($scenario) || ! in_array($scenario, DemoSeeder::SCENARIOS, true)) {
            $this->error(__('demo.scenario_invalid', ['scenarios' => implode(', ', DemoSeeder::SCENARIOS)]));

            return self::FAILURE;
        }
        app(DemoSeeder::class)->run($scenario);

        return self::SUCCESS;
    }
}
