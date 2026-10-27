<?php

namespace App\Contracts;

use App\Data\AcquisitionTarget;
use App\Data\RemotePageResponse;

interface RemotePageTransport
{
    public function fetch(AcquisitionTarget $target): RemotePageResponse;
}
