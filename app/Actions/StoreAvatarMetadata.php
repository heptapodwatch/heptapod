<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

final readonly class StoreAvatarMetadata
{
    public function __construct(private ClassifySiteIconBacking $classify) {}

    public function __invoke(ConversionHasBeenCompletedEvent $event): void
    {
        if ($event->conversion->getName() !== 'avatar') {
            return;
        }
        $media = $event->media;
        $png = Storage::disk($media->conversions_disk)->get($media->getPathRelativeToRoot('avatar')) ?? throw new \RuntimeException('Avatar conversion is missing from storage.');
        $media->setCustomProperty('avatar_hash', hash('sha256', $png));
        $media->setCustomProperty('avatar_backing', ($this->classify)($png)->value);
        $media->save();
    }
}
