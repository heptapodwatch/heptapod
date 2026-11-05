<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Models\SystemIssue;
use Heptapod\SystemHealth\EvaluateSystemIssues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Open or resolve system issues (maintenance, backups, scheduler, mail) and mail Owners about changes')]
#[Signature('heptapod:evaluate-system-issues')]
final class EvaluateSystemIssuesCommand extends Command
{
    public function handle(EvaluateSystemIssues $evaluate): int
    {
        $evaluate(CarbonImmutable::now('UTC'));
        foreach (SystemIssue::open()->get() as $issue) {
            $this->line($issue->kind->value.' open since '.$issue->opened_at->toIso8601String());
        }

        return self::SUCCESS;
    }
}
