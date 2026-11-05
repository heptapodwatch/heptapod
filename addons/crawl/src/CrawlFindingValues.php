<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\Findings\FindingCause;
use Spatie\Crawler\Enums\ResourceType;

final class CrawlFindingValues
{
    public const array Results = [
        'blocked (private target)' => 'crawl.result.blocked_private_target',
        'request_failed' => 'crawl.result.request_failed',
        'unchecked (timeout)' => 'crawl.result.unchecked_timeout',
        'unchecked (connect)' => 'crawl.result.unchecked_connect',
        'invalid URL' => 'crawl.result.invalid_url',
        'page too large (links not extracted)' => 'crawl.result.page_too_large',
        'bot_protection' => 'crawl.not_verifiable.bot_protection',
        'rate_limited' => 'crawl.not_verifiable.rate_limited',
        'unchecked (connection reset)' => 'crawl.result.unchecked_connection_reset',
        'login_required' => 'crawl.result.login_required',
        'access_refused' => 'crawl.result.access_refused',
        'dns_unresolved' => 'crawl.result.dns_unresolved',
        'too_many_redirects' => 'crawl.result.too_many_redirects',
        'tls_expired' => 'crawl.result.tls_expired',
        'tls_not_yet_valid' => 'crawl.result.tls_not_yet_valid',
        'tls_name_mismatch' => 'crawl.result.tls_name_mismatch',
        'tls_untrusted' => 'crawl.result.tls_untrusted',
        'tls_handshake_failed' => 'crawl.result.tls_handshake_failed',
        'cause_not_recorded' => 'crawl.result.cause_not_recorded',
        'private_target' => 'crawl.result.private_target',
    ];

    /** Agent API cause for each stored result label in Results. */
    public static function cause(string $label): FindingCause
    {
        return match ($label) {
            'blocked (private target)' => FindingCause::PrivateTarget,
            'unchecked (timeout)' => FindingCause::Timeout,
            'unchecked (connect)' => FindingCause::ConnectFailed,
            'unchecked (connection reset)' => FindingCause::ConnectionReset,
            'invalid URL' => FindingCause::InvalidUrl,
            'page too large (links not extracted)' => FindingCause::PageTooLarge,
            default => FindingCause::from($label),
        };
    }

    /** Shown in the Not verifiable group, keyed by result label. */
    public const array NotVerifiableReasons = [
        'bot_protection' => 'crawl.not_verifiable.bot_protection',
        'rate_limited' => 'crawl.not_verifiable.rate_limited',
        'login_required' => 'crawl.not_verifiable.login_required',
        'access_refused' => 'crawl.not_verifiable.access_refused',
        'dns_unresolved' => 'crawl.not_verifiable.dns_unresolved',
        'tls_untrusted' => 'crawl.not_verifiable.tls_untrusted',
        'tls_handshake_failed' => 'crawl.not_verifiable.tls_handshake_failed',
        'cause_not_recorded' => 'crawl.not_verifiable.cause_not_recorded',
        'private_target' => 'crawl.not_verifiable.private_target',
    ];

    public const array ResourceKinds = [
        'link' => 'crawl.kind.link',
        'image' => 'crawl.kind.image',
        'script' => 'crawl.kind.script',
        'stylesheet' => 'crawl.kind.stylesheet',
        'og:image' => 'crawl.kind.open_graph_image',
    ];

    public const array Kinds = [
        ...self::ResourceKinds,
        'open_graph_image' => self::ResourceKinds['og:image'],
        // Older retained demo/import findings use this kind instead of a resource type.
        'broken' => 'crawl.kind.broken',
    ];

    public const array MixedKinds = [
        'img' => 'mixed_content.kind.image',
        'script' => 'mixed_content.kind.script',
        'link' => 'mixed_content.kind.link',
        'iframe' => 'mixed_content.kind.frame',
        'source' => 'mixed_content.kind.source',
        'video' => 'mixed_content.kind.video',
        'audio' => 'mixed_content.kind.audio',
        'object' => 'mixed_content.kind.object',
        'embed' => 'mixed_content.kind.embed',
    ];

    /** @return list<string> */
    public static function crawlerKinds(): array
    {
        return array_column(ResourceType::cases(), 'value');
    }

    public static function notVerifiableShortKey(string $result): string
    {
        return isset(self::NotVerifiableReasons[$result]) ? 'crawl.not_verifiable_short.'.$result : throw new \LogicException('Unknown not-verifiable result: '.$result);
    }

    public static function resultKey(string $result): string
    {
        return self::Results[$result] ?? throw new \LogicException('Unknown crawl finding result: '.$result);
    }

    public static function kindKey(string $kind, bool $mixed): string
    {
        return ($mixed ? self::MixedKinds : self::Kinds)[$kind] ?? throw new \LogicException('Unknown crawl finding kind: '.$kind);
    }
}
