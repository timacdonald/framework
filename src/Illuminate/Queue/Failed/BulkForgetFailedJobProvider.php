<?php

namespace Illuminate\Queue\Failed;

interface BulkForgetFailedJobProvider
{
    /**
     * Forget the failed jobs with the given IDs.
     *
     * @param  list<string>  $ids
     */
    public function forgetMany(array $ids): void;
}
