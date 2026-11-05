<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Contracts\PublicSuffixList;
use Pdp\Rules;

final class BundledPublicSuffixList implements PublicSuffixList
{
    private ?Rules $rules = null;

    public function rules(): \Pdp\PublicSuffixList
    {
        return $this->rules ??= Rules::fromPath(resource_path('public_suffix_list.dat'));
    }
}
