<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\SecretRedactor;

final readonly class SitemapXmlProblem
{
    public function __construct(public SitemapXmlReason $reason, public int $line = 1, public ?string $element = null) {}

    /** @param list<string> $secrets
     * @return array{xml_reason: string, xml_line: int, xml_element?: string}
     */
    public function params(array $secrets = []): array
    {
        $params = ['xml_reason' => $this->reason->value, 'xml_line' => max(1, $this->line)];
        if ($this->element !== null) {
            $strip = static fn (string $value): string => preg_replace('/[^a-zA-Z0-9:_-]/', '', $value) ?? '';
            // Redact again after stripping: removed characters could otherwise rejoin a secret; strip the mask's brackets again.
            $token = substr($strip(SecretRedactor::redact($strip(SecretRedactor::redact($this->element, $secrets)), $secrets)), 0, 40);
            $params['xml_element'] = $token === '' ? 'unknown' : $token;
        }

        return $params;
    }

    /** @param array{xml_reason?: string, xml_line?: int, xml_element?: string} $issue */
    public static function presentation(array $issue): ?CheckRunPresentationPartData
    {
        if (! isset($issue['xml_reason'])) {
            return null;
        }
        $reason = SitemapXmlReason::from($issue['xml_reason']);
        $params = array_intersect_key($issue, array_flip(['xml_line', 'xml_element']));

        return new CheckRunPresentationPartData('sitemap.xml.'.$reason->value, $params, untrusted: ['xml_element' => 'sitemap_element']);
    }
}
