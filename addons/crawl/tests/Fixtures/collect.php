<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Crawl\CrawlCollector;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// setup: a committed site and crawl check, printed as "<site id> <check id>"; the collector stores its cache only for a
// live check of a site that is not being deleted.
if (($argv[1] ?? '') === 'setup') {
    $check = Model::withoutEvents(static fn (): Check => Check::factory()->for(Site::factory())->create(['type' => CheckKey::BrokenLinks, 'failure_threshold' => 1]));
    echo $check->site_id.' '.$check->id."\n";
    exit(0);
}
$siteId = (int) $argv[1];
$checkId = (int) $argv[2];
if (($argv[3] ?? '') === 'cleanup') {
    DB::table('crawl_collections')->where('site_id', $siteId)->delete();
    $clientId = DB::table('sites')->where('id', $siteId)->value('client_id');
    DB::table('checks')->where('id', $checkId)->delete();
    DB::table('sites')->where('id', $siteId)->delete();
    DB::table('clients')->where('id', $clientId)->delete();
    exit(0);
}
$at = CarbonImmutable::now();
$artifact = $app->make(CrawlCollector::class)->ensureFresh(new CheckContext($siteId, $checkId, $checkId, '00000000-0000-0000-0000-000000000001', 'http://fixtures.test/crawl-addon/concurrent', $at, $at->addMinutes(5), [], [], null), false);
echo count($artifact->urls)."\n";
