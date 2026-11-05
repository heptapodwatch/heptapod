<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\PublicSuffixList;
use Pdp\Rules;

final class ExamplePublicSuffixList implements PublicSuffixList
{
    private ?Rules $rules = null;

    public function rules(): \Pdp\PublicSuffixList
    {
        if (! $this->rules instanceof Rules) {
            $contents = file_get_contents(resource_path('public_suffix_list.dat'));
            if ($contents === false) {
                throw new \RuntimeException('Bundled public suffix list is missing.');
            }
            $marker = '// ===END ICANN DOMAINS===';
            if (! str_contains($contents, $marker)) {
                throw new \UnexpectedValueException('Bundled public suffix list has no ICANN section.');
            }
            $this->rules = Rules::fromString(str_replace($marker, "example\nco.example\n".$marker, $contents));
        }

        return $this->rules;
    }
}
