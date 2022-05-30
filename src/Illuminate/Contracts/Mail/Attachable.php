<?php

namespace Illuminate\Contracts\Mail;

interface Attachable
{
    /**
     * @return \Illuminate\Mail\Attachment
     */
    public function toMailAttachment();
}
