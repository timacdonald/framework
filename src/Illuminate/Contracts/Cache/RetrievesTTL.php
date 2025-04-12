<?php

namespace Illuminate\Contracts\Cache;

use Illuminate\Cache\Ttl;

interface RetrievesTTL
{
    public function ttl(string $key): ?Ttl;
}

