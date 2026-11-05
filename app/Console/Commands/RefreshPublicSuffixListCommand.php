<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\RefreshPublicSuffixList;
use Heptapod\Contracts\Http\FetchException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pdp\UnableToLoadPublicSuffixList;

#[Description('Refresh the bundled Public Suffix List from its official source')]
#[Signature('heptapod:psl:refresh')]
final class RefreshPublicSuffixListCommand extends Command
{
    public function handle(RefreshPublicSuffixList $refresh): int
    {
        if (app()->isProduction()) {
            $this->error(__('psl.refresh_source_only'));

            return self::FAILURE;
        }

        try {
            $refresh();
        } catch (FetchException|UnableToLoadPublicSuffixList|\UnexpectedValueException $exception) {
            report($exception);
            $this->error(__('psl.refresh_failed'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
