<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface PublicSuffixList
{
    public function rules(): \Pdp\PublicSuffixList;
}
