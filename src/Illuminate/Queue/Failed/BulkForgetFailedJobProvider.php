<?php

namespace Illuminate\Queue\Failed;

interface BulkForgetFailedJobProvider
{
    /**
     * Forget the failed jobs with the given IDs.
     */
    public function forgetMany(array $ids): void;
}
