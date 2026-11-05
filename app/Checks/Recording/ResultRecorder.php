<?php

declare(strict_types=1);

namespace Heptapod\Checks\Recording;

interface ResultRecorder
{
    public function record(RecordableRun $run): RecordedRun;
}
