<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use DOMDocument;
use DOMXPath;

final readonly class SitemapDocumentReader
{
    public function read(string $body, int $maxBytes): DOMDocument|SitemapXmlProblem
    {
        $body = $this->xmlBody($body, $maxBytes);

        return $body instanceof SitemapXmlProblem ? $body : $this->validateXml($body);
    }

    private function xmlBody(string $body, int $maxBytes): string|SitemapXmlProblem
    {
        // A bare .xml.gz file is not HTTP Content-Encoding; only file-format decoding belongs here.
        if (! str_starts_with($body, "\x1f\x8b")) {
            return $body;
        }
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            throw new \RuntimeException('Cannot initialise gzip inflater.');
        }
        $xml = '';
        for ($offset = 0, $length = strlen($body); $offset < $length; $offset += 1024) {
            $chunk = @inflate_add($inflater, substr($body, $offset, 1024), $offset + 1024 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                return new SitemapXmlProblem(SitemapXmlReason::Gzip);
            }
            if (strlen($xml) + strlen($chunk) > $maxBytes) {
                throw new SitemapSizeExceeded;
            }
            $xml .= $chunk;
        }

        return inflate_get_status($inflater) === ZLIB_STREAM_END ? $xml : new SitemapXmlProblem(SitemapXmlReason::Gzip);
    }

    private function validateXml(string $body): DOMDocument|SitemapXmlProblem
    {
        if (trim($body) === '') {
            return new SitemapXmlProblem(SitemapXmlReason::EmptyBody);
        }
        // Detect common HTML error pages before parsing or rejecting their DOCTYPE; a leading XML declaration or comments may precede the markup.
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*\?>\s*)?(?:<!--.*?-->\s*)*(?:<!doctype\s+html\b|<html\b)/is', $body) === 1) {
            return new SitemapXmlProblem(SitemapXmlReason::Html);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            libxml_clear_errors();
            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_BIGLINES)) {
                $errors = libxml_get_errors();
                $error = $errors === [] ? null : $errors[0];
                $reason = match ($error?->code) {
                    77 => SitemapXmlReason::TagNotClosed,
                    76 => SitemapXmlReason::MismatchedTag,
                    // 9 is "Input is not proper UTF-8" (e.g. a Latin-1 byte in a UTF-8 document).
                    9, 31, 32, 81 => SitemapXmlReason::InvalidEncoding,
                    default => SitemapXmlReason::Syntax,
                };

                return new SitemapXmlProblem($reason, $error->line ?? 1);
            }
            if ($document->doctype instanceof \DOMDocumentType) {
                return new SitemapXmlProblem(SitemapXmlReason::DocumentType, $document->doctype->getLineNo());
            }
            $root = $document->documentElement;
            if (! $root instanceof \DOMElement) {
                return new SitemapXmlProblem(SitemapXmlReason::Syntax);
            }
            if (! in_array($root->localName, ['urlset', 'sitemapindex'], true)) {
                return $root->localName === 'html'
                    ? new SitemapXmlProblem(SitemapXmlReason::Html, $root->getLineNo())
                    : new SitemapXmlProblem(SitemapXmlReason::Root, $root->getLineNo(), $root->nodeName);
            }
            if (! in_array($root->namespaceURI, [null, '', 'http://www.sitemaps.org/schemas/sitemap/0.9'], true)) {
                return new SitemapXmlProblem(SitemapXmlReason::Namespace, $root->getLineNo());
            }
            // Extension namespaces are metadata, never sitemap documents or page locations.
            $extensions = new DOMXPath($document)->query('//*[namespace-uri() != "'.($root->namespaceURI ?? '').'"]');
            if ($extensions === false) {
                throw new \RuntimeException('Cannot select sitemap extension elements.');
            }
            foreach ($extensions as $extension) {
                /** @var \DOMElement $extension */
                $extension->parentNode?->removeChild($extension);
            }
            $itemName = $root->localName === 'urlset' ? 'url' : 'sitemap';
            foreach ($root->childNodes as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                if ($node->localName !== $itemName || $node->namespaceURI !== $root->namespaceURI) {
                    return new SitemapXmlProblem(SitemapXmlReason::Child, $node->getLineNo(), $node->nodeName);
                }
                $locations = $node->getElementsByTagNameNS($root->namespaceURI ?? '', 'loc');
                $location = $locations->item(0);
                if ($locations->length === 0) {
                    return new SitemapXmlProblem(SitemapXmlReason::MissingLocation, $node->getLineNo());
                }
                if ($locations->length > 1) {
                    return new SitemapXmlProblem(SitemapXmlReason::DuplicateLocation, $node->getLineNo());
                }
                if (! $location instanceof \DOMElement || $location->parentNode !== $node || $location->namespaceURI !== $root->namespaceURI) {
                    return new SitemapXmlProblem(SitemapXmlReason::MissingLocation, $node->getLineNo());
                }
                if (filter_var(trim($location->textContent), FILTER_VALIDATE_URL) === false || ! in_array(parse_url(trim($location->textContent), PHP_URL_SCHEME), ['http', 'https'], true)) {
                    // Even malformed URLs can contain credentials or tokens; retain only the line.
                    return new SitemapXmlProblem(SitemapXmlReason::InvalidLocation, $location->getLineNo());
                }
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
