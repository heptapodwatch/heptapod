<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('keeps site identity and priority columns readable across list widths', function (string $locale): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $owner = User::query()->where('email', 'test@example.test')->sole();
    $owner->update(['locale' => $locale]);
    $this->actingAs($owner);

    $page = visit('/sites');
    foreach ([1440, 1280, 1024, 768, 390] as $width) {
        $page->resize($width, 900)->assertMissing('header button[role=combobox]');
        $layout = $page->script('(() => {
            const visible = element => element.getBoundingClientRect().width > 0;
            const fits = (element, cell) => {
                const range = document.createRange();
                range.selectNodeContents(element);
                const text = range.getBoundingClientRect(), bounds = cell.getBoundingClientRect();
                return text.width > 0 && text.left >= bounds.left - 1 && text.right <= bounds.right + 1;
            };
            if (!visible(document.querySelector("main table"))) {
                const name = document.querySelector("main ul li [data-site-row] [data-entity-name]");
                return [visible(name) && fits(name, name), document.documentElement.scrollWidth <= innerWidth];
            }
            const tableContainer = document.querySelector("main [data-slot=table-container]");
            const headers = [...document.querySelectorAll("main thead th")].filter(visible);
            const labels = headers.map(header => header.textContent.trim());
            const row = [...document.querySelectorAll("main tbody tr")].find(row => row.getAttribute("aria-label")?.startsWith("Backup:"));
            const site = row.querySelector("td [data-entity-name]");
            const status = row.querySelector("td:first-child [data-slot=badge]");
            const checked = row.querySelector("td:last-child time");
            return [
                ["Status", document.documentElement.lang === "de" ? "Website" : "Site", document.documentElement.lang === "de" ? "Grund" : "Why", document.documentElement.lang === "de" ? "Geprüft" : "Checked"].every(label => labels.includes(label)),
                headers.every(header => fits(header.querySelector("button span"), header)),
                visible(site) && fits(site, site.closest("td")),
                visible(status) && status.getBoundingClientRect().right <= status.closest("td").getBoundingClientRect().right,
                visible(checked) && fits(checked, checked.closest("td")),
                document.documentElement.scrollWidth <= innerWidth,
                ...(innerWidth === 1440 ? [[document.documentElement.lang === "de" ? "Prüfungen" : "Checks", "24h", document.documentElement.lang === "de" ? "Verfügbarkeit · 30 Tage" : "Uptime 30d"].every(label => labels.includes(label))] : []),
                ...([1024, 1280].includes(innerWidth) ? (() => {
                    const heading = document.querySelector("main h1");
                    return [
                        ...(innerWidth === 1024 ? [
                            tableContainer.scrollWidth <= tableContainer.clientWidth,
                            checked.getBoundingClientRect().right <= tableContainer.getBoundingClientRect().right + 1,
                            fits(headers[0].querySelector("button span"), tableContainer),
                        ] : []),
                        visible(heading) && fits(heading, heading.closest("header")),
                    ];
                })() : []),
            ];
        })()');
        expect($layout)->toBe(array_fill(0, count($layout), true), "{$locale} at {$width}px");
    }
    $page->assertNoJavaScriptErrors();
})->with(['English' => 'en', 'German' => 'de']);
