<?php

namespace Heptapod\Support;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\File;

class AreaTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $loader, private readonly string $langPath) {}

    /** @return array<string, mixed> */
    public function load($locale, $group, $namespace = null): array
    {
        /** @var array<string, mixed> $lines */
        $lines = $this->loader->load($locale, $group, $namespace);

        if ($group !== '*' || $namespace !== '*' || ! in_array($locale, ['en', 'de'], true)) {
            return $lines;
        }

        foreach (File::files($this->langPath.'/'.$locale) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            /** @var array<string, string> $area */
            $area = json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
            $lines = array_merge($lines, $area);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->loader->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }
}
