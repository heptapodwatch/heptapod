<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Crawl\CrawlCollector;
use Heptapod\Contracts\CheckContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$siteId = (int) $argv[1];
if (($argv[2] ?? '') === 'cleanup') {
    DB::table('crawl_collections')->where('site_id', $siteId)->delete();
    exit(0);
}
$at = CarbonImmutable::now();
$artifact = $app->make(CrawlCollector::class)->ensureFresh(new CheckContext($siteId, $siteId, $siteId, '00000000-0000-0000-0000-000000000001', 'http://fixtures.test/crawl-addon/concurrent', $at, $at->addMinutes(5), [], [], null), false);
echo count($artifact->urls)."\n";
