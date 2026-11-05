<?php

declare(strict_types=1);

use Heptapod\Addons\Crawl\CrawlFindingValues;
use Heptapod\Addons\Crawl\LinkClassificationPolicy;
use Heptapod\Addons\Sitemap\SitemapIssueCounts;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchFailureKind;

require dirname(__DIR__).'/vendor/autoload.php';
$root = $argv[1] ?? dirname(__DIR__);
$failures = 0;
$required = [
    'crawl' => [...array_values(CrawlFindingValues::Results), ...array_values(CrawlFindingValues::NotVerifiableReasons), ...array_values(CrawlFindingValues::Kinds), ...array_values(CrawlFindingValues::MixedKinds)],
    'sitemap' => SitemapIssueCounts::Types,
];
foreach (FetchFailureKind::cases() as $kind) {
    $required['sitemap'][] = 'sitemap.status.'.$kind->value;
}
foreach (AutomatedCheckBlockReason::cases() as $reason) {
    if (! array_key_exists($reason->value, CrawlFindingValues::Results)) {
        fwrite(STDERR, 'Missing declared crawl finding result: '.$reason->value.PHP_EOL);
        $failures++;
    }
    $required['crawl'][] = 'crawl.not_verifiable.'.$reason->value;
    $required['sitemap'][] = 'sitemap.not_verifiable.'.$reason->value;
}
foreach ([...LinkClassificationPolicy::UNCHECKED, ...LinkClassificationPolicy::NOT_VERIFIABLE, ...LinkClassificationPolicy::EXTERNAL_NOT_VERIFIABLE] as $label) {
    if (! array_key_exists($label, CrawlFindingValues::Results)) {
        fwrite(STDERR, 'Missing declared crawl finding result: '.$label.PHP_EOL);
        $failures++;
    }
}
foreach (array_keys(CrawlFindingValues::NotVerifiableReasons) as $label) {
    $required['crawl'][] = CrawlFindingValues::notVerifiableShortKey($label);
}
foreach ([...LinkClassificationPolicy::NOT_VERIFIABLE, ...LinkClassificationPolicy::EXTERNAL_NOT_VERIFIABLE] as $label) {
    if (! array_key_exists($label, CrawlFindingValues::NotVerifiableReasons)) {
        fwrite(STDERR, 'Missing declared not-verifiable reason: '.$label.PHP_EOL);
        $failures++;
    }
}
foreach (CrawlFindingValues::crawlerKinds() as $kind) {
    if (! array_key_exists($kind, CrawlFindingValues::Kinds)) {
        fwrite(STDERR, 'Missing declared crawl finding kind: '.$kind.PHP_EOL);
        $failures++;
    }
}
$keys = 0;
foreach ($required as $addon => $translations) {
    foreach (['en', 'de'] as $locale) {
        $path = $root.'/addons/'.$addon.'/lang/'.$locale.'.json';
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Could not read finding catalog: '.$path);
        }
        $catalog = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($catalog)) {
            throw new RuntimeException('Finding catalog must be a map: '.$path);
        }
        foreach (array_unique($translations) as $key) {
            $keys++;
            if (! is_string($catalog[$key] ?? null) || trim($catalog[$key]) === '') {
                fwrite(STDERR, 'Missing finding translation: addon='.$addon.' locale='.$locale.' key='.$key.PHP_EOL);
                $failures++;
            }
        }
    }
}
echo 'Finding translations: '.$keys.' declared keys checked; '.$failures.' missing.'.PHP_EOL;
exit($failures === 0 ? 0 : 1);
