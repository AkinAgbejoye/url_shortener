<?php

namespace App\Enums;

enum UrlLifecycleState: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Expired = 'expired';
    case Deleted = 'deleted';
}
