<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

enum SitemapXmlReason: string
{
    case EmptyBody = 'empty_body';
    case Html = 'html';
    case Syntax = 'syntax';
    case TagNotClosed = 'unclosed_tag';
    case MismatchedTag = 'mismatched_tag';
    case InvalidEncoding = 'invalid_encoding';
    case DocumentType = 'doctype';
    case Root = 'root';
    case Namespace = 'namespace';
    case Child = 'child';
    case MissingLocation = 'missing_loc';
    case DuplicateLocation = 'duplicate_loc';
    case InvalidLocation = 'invalid_loc';
    case NoEntries = 'no_entries';
    case Gzip = 'gzip';
}
