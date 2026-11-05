<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Illuminate\Filesystem\Filesystem;
use Pdp\Rules;

final readonly class RefreshPublicSuffixList
{
    public function __construct(private OutboundHttp $http, private Filesystem $files) {}

    public function __invoke(): void
    {
        $url = 'https://publicsuffix.org/list/public_suffix_list.dat';
        $now = CarbonImmutable::now('UTC');
        $result = $this->http->fetch(new FetchRequest(
            $url, maxWireBytes: 2097152, maxDecodedBytes: 2097152,
            redirects: RedirectPolicy::Off, timeoutSeconds: 30,
        ), new CheckContext(0, 0, 0, '', $url, $now, $now->addSeconds(30), [], [], null));
        if ($result->failure instanceof FetchFailure) {
            throw new FetchException($result->failure);
        }

        $rules = Rules::fromString($result->body);
        foreach (['ICANN', 'PRIVATE'] as $section) {
            if (preg_match('/^\/\/ ===BEGIN '.$section.' DOMAINS===\R.*?^\/\/ ===END '.$section.' DOMAINS===/ms', $result->body) !== 1) {
                throw new \UnexpectedValueException('Public Suffix List requires complete ICANN and private sections.');
            }
        }
        // Rules ignores comment-containing lines and accepts empty sections.
        // Stable entries in the official list must resolve in both parsed sections.
        if (! $rules->resolve('example.com')->suffix()->isICANN()
            || ! $rules->resolve('example.github.io')->suffix()->isPrivate()) {
            throw new \UnexpectedValueException('Public Suffix List requires parsed ICANN and private rules.');
        }

        $readme = $this->files->get(resource_path('README.md'));
        $readme = preg_replace('/^- Download date: \d{4}-\d{2}-\d{2}$/m', '- Download date: '.$now->toDateString(), $readme, count: $count);
        if ($readme === null || $count !== 1) {
            throw new \UnexpectedValueException('Public Suffix List README requires one download date.');
        }
        $this->files->replace(resource_path('public_suffix_list.dat'), $result->body);
        $this->files->replace(resource_path('README.md'), $readme);
    }
}
