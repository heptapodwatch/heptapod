<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\SiteIconBacking;

final readonly class ClassifySiteIconBacking
{
    public function __invoke(string $png): SiteIconBacking
    {
        // Shared by stored site icons (64px) and client avatar conversions (128px).
        if (strlen($png) > FetchSiteIcon::ByteCap) {
            throw new \RuntimeException('Stored site icon exceeds the favicon byte cap.');
        }
        $info = @getimagesizefromstring($png);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > 128 || $info[1] > 128) {
            throw new \RuntimeException('Stored site icon must be a normalized PNG of at most 128px.');
        }
        $image = @imagecreatefromstring($png);
        if ($image === false) {
            throw new \RuntimeException('Cannot decode stored site icon.');
        }
        $coverage = 0.0;
        $darkCoverage = 0.0;
        $lightCoverage = 0.0;
        for ($y = 0; $y < $info[1]; $y++) {
            for ($x = 0; $x < $info[0]; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                if ($pixel === false) {
                    throw new \RuntimeException('Cannot read stored site icon pixel.');
                }
                $color = imagecolorsforindex($image, $pixel);
                $opacity = 1 - $color['alpha'] / 127;
                $coverage += $opacity;
                $channels = array_map(static fn (int $channel): float => $channel / 255, [$color['red'], $color['green'], $color['blue']]);
                $chroma = max($channels) - min($channels);
                $linear = array_map(static fn (float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4, $channels);
                $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
                if ($chroma < 0.15 && $luminance < 0.15) {
                    $darkCoverage += $opacity;
                } elseif ($chroma < 0.15 && $luminance > 0.85) {
                    $lightCoverage += $opacity;
                }
            }
        }

        // Only mostly transparent icons need backing. Invisible RGB does not count
        // when classifying visible ink; an entirely transparent image has no ink to back.
        if ($coverage === 0.0 || $coverage > $info[0] * $info[1] / 2) {
            return SiteIconBacking::None;
        }

        if ($darkCoverage > $coverage / 2) {
            return SiteIconBacking::LightBackingInDark;
        }

        return $lightCoverage > $coverage / 2 ? SiteIconBacking::DarkBackingInLight : SiteIconBacking::None;
    }
}
