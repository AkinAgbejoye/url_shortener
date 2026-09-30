<?php

namespace App\Contracts;

interface UrlOwnership
{
    public function ownerId(): ?int;

    public function isOwned(): bool;

    public function isAnonymous(): bool;
}
