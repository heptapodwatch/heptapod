<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Dom\HTMLDocument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\HtmlPage;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Data\Ui\IconRefreshResultData;
use Heptapod\Exceptions\IconRefreshFailed;
use Heptapod\Models\Site;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\Image\Image;

final readonly class FetchSiteIcon
{
    public const int ByteCap = 262144;

    /** Icon links in the start-page head sit inside this prefix; parsing the rest would only add DOM work. */
    private const int DiscoveryByteCap = 262144;

    public function __construct(private OutboundHttp $http) {}

    public function __invoke(Site $site): string
    {
        $now = now()->toImmutable();
        $context = new CheckContext($site->id, 0, 0, '', $site->url, $now, $now->addSeconds(40), [], [], null);
        $page = $this->fetch($site->url, $context, HtmlPage::MAX_HTML_BYTES);
        $base = $page->effectiveUrl ?? $site->url;
        $candidates = [];
        $reason = new IconRefreshResultData('icons.none');
        if ($page->failure instanceof FetchFailure) {
            $reason = $page->failure->kind === FetchFailureKind::TooLarge ? new IconRefreshResultData('icons.page_too_large') : $this->failureReason($page->failure);
            if (! in_array($page->failure->kind, [FetchFailureKind::HttpStatus, FetchFailureKind::TooLarge], true)) {
                throw new IconRefreshFailed($reason);
            }
        } else {
            $headers = array_change_key_case($page->headers, CASE_LOWER);
            $contentType = strtolower(trim(explode(';', $headers['content-type'][0] ?? 'text/html', 2)[0]));
            if ($page->body !== '' && in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
                try {
                    $discovery = $this->candidates($page->body, $base);
                    $candidates = $discovery['urls'];
                    $reason = new IconRefreshResultData($discovery['skipped_svg'] ? 'icons.unsupported' : 'icons.none');
                } catch (\InvalidArgumentException) {
                    $reason = new IconRefreshResultData('icons.invalid_request');
                }
            }
        }
        $candidates[] = (string) UriResolver::resolve(new Uri($base), new Uri('/favicon.ico'));
        foreach (array_unique($candidates) as $url) {
            $result = $this->fetch($url, $context);
            if ($result->failure instanceof FetchFailure) {
                if ($result->failure->status !== 404) {
                    $reason = $this->failureReason($result->failure);
                }

                continue;
            }
            if ($result->body === '') {
                $reason = new IconRefreshResultData('icons.none');

                continue;
            }
            try {
                return $this->normalize($result->body);
            } catch (IconRefreshFailed $exception) {
                if (! in_array($exception->result->key, ['icons.unsupported', 'icons.too_large', 'icons.decode'], true)) {
                    throw $exception;
                }
                $reason = $exception->result;
            }
        }
        throw new IconRefreshFailed($reason);
    }

    private function failureReason(FetchFailure $failure): IconRefreshResultData
    {
        // A capped HTTP 200 response is still too large, not an HTTP-status failure.
        return $failure->kind === FetchFailureKind::HttpStatus && $failure->status !== null
            ? new IconRefreshResultData('icons.http_status_code', ['status' => $failure->status])
            : new IconRefreshResultData('icons.'.$failure->kind->value);
    }

    private function fetch(#[\SensitiveParameter] string $url, CheckContext $context, int $byteCap = self::ByteCap): FetchResult
    {
        return $this->http->fetch(new FetchRequest($url, headers: ['Cache-Control' => 'no-cache'],
            maxWireBytes: $byteCap, maxDecodedBytes: $byteCap, redirects: RedirectPolicy::Untrusted,
            maxRedirects: 3, timeoutSeconds: 8, publicTargetsOnly: true), $context);
    }

    /** @return array{urls: list<string>, skipped_svg: bool} */
    private function candidates(#[\SensitiveParameter] string $html, #[\SensitiveParameter] string $base): array
    {
        $document = HTMLDocument::createFromString(substr($html, 0, self::DiscoveryByteCap), LIBXML_NOERROR);
        foreach ($document->getElementsByTagName('base') as $element) {
            if (! $element->hasAttribute('href')) {
                continue;
            }
            $href = trim($element->getAttribute('href') ?? '');
            try {
                $uri = UriResolver::resolve(new Uri($base), new Uri($href));
            } catch (\InvalidArgumentException) {
                break;
            }
            if (in_array(strtolower($uri->getScheme()), ['http', 'https'], true) && $uri->getUserInfo() === '') {
                $base = (string) $uri;
            }
            break;
        }
        $icons = [];
        $skippedSvg = false;
        foreach ($document->getElementsByTagName('link') as $link) {
            if (preg_match('/(?:^|\s)(?:icon|apple-touch-icon)(?:\s|$)/i', ($link->getAttribute('rel') ?? '')) !== 1) {
                continue;
            }
            $href = trim($link->getAttribute('href') ?? '');
            if ($href === '') {
                continue;
            }
            $size = 0;
            if (preg_match_all('/(\d+)x(\d+)/i', ($link->getAttribute('sizes') ?? ''), $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $width = (int) $match[1];
                    $height = (int) $match[2];
                    if ($width <= 256 && $height <= 256) {
                        $size = max($size, min($width, $height));
                    }
                }
                if ($size === 0) {
                    continue;
                }
            }
            try {
                $uri = UriResolver::resolve(new Uri($base), new Uri($href));
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
                continue;
            }
            $type = strtolower(trim(explode(';', $link->getAttribute('type') ?? '', 2)[0]));
            $extension = strtolower(pathinfo($uri->getPath(), PATHINFO_EXTENSION));
            if ($type === 'image/svg+xml' || $extension === 'svg') {
                $skippedSvg = true;

                continue;
            }
            $supported = in_array($type, ['image/png', 'image/webp', 'image/jpeg', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon'], true)
                || in_array($extension, ['png', 'webp', 'jpg', 'jpeg', 'gif', 'ico'], true);
            $icons[] = ['url' => (string) $uri, 'size' => $size, 'supported' => $supported];
        }
        usort($icons, static fn (array $a, array $b): int => [$b['supported'], $b['size']] <=> [$a['supported'], $a['size']]);

        // Three declared candidates plus the root ICO keep each weekly discovery cycle's load bounded.
        return ['urls' => array_slice(array_values(array_unique(array_column($icons, 'url'))), 0, 3), 'skipped_svg' => $skippedSvg];
    }

    public function normalize(#[\SensitiveParameter] string $bytes): string
    {
        if (strlen($bytes) > self::ByteCap) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.too_large'));
        }
        if (str_starts_with($bytes, "\0\0\1\0")) {
            $bytes = $this->icoPng($bytes);
        }
        // GD warnings from malformed external bytes become the explicit format failure below.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.unsupported'));
        }
        // A tiny compressed payload can still allocate gigabytes when decoded.
        if ($info[0] > 2048 || $info[1] > 2048) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.too_large'));
        }
        $input = tempnam(sys_get_temp_dir(), 'site-icon-');
        if ($input === false) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.storage'));
        }
        $output = $input.'.png';
        try {
            if (file_put_contents($input, $bytes) === false) {
                throw new IconRefreshFailed(new IconRefreshResultData('icons.storage'));
            }
            try {
                Image::useImageDriver(ImageDriver::Gd)->loadFile($input)->fit(Fit::Contain, 64, 64)->resizeCanvas(64, 64, backgroundColor: 'rgba(255, 255, 255, 0)')->save($output);
            } catch (CouldNotLoadImage $exception) {
                throw new IconRefreshFailed(new IconRefreshResultData('icons.decode'), previous: $exception);
            }
            $png = file_get_contents($output);
            if ($png === false) {
                throw new IconRefreshFailed(new IconRefreshResultData('icons.storage'));
            }

            return $png;
        } finally {
            unlink($input);
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    /** GD cannot decode ICO containers. Only unwrap PNG frames; never decode DIB ourselves. */
    private function icoPng(#[\SensitiveParameter] string $bytes): string
    {
        if (strlen($bytes) < 6) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.unsupported'));
        }
        $count = ord($bytes[4]) | (ord($bytes[5]) << 8);
        if ($count < 1 || 6 + $count * 16 > strlen($bytes)) {
            throw new IconRefreshFailed(new IconRefreshResultData('icons.unsupported'));
        }
        $best = '';
        $largest = 0;
        for ($index = 0; $index < $count; $index++) {
            /** @var array{width: int, height: int, colors: int, reserved: int, planes: int, bits: int, length: int, offset: int}|false $entry */
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', substr($bytes, 6 + $index * 16, 16));
            if ($entry === false || $entry['offset'] < 6 + $count * 16 || $entry['length'] < 8 || strlen($bytes) < $entry['offset'] + $entry['length']) {
                throw new IconRefreshFailed(new IconRefreshResultData('icons.unsupported'));
            }
            $payload = substr($bytes, $entry['offset'], $entry['length']);
            $area = ($entry['width'] === 0 ? 256 : $entry['width']) * ($entry['height'] === 0 ? 256 : $entry['height']);
            if (str_starts_with($payload, "\x89PNG\r\n\x1a\n") && $area > $largest) {
                $best = $payload;
                $largest = $area;
            }
        }

        return $best !== '' ? $best : throw new IconRefreshFailed(new IconRefreshResultData('icons.unsupported'));
    }
}
