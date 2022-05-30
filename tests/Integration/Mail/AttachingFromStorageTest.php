<?php

namespace Illuminate\Tests\Integration\Mail;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\File;

class AttachingFromStorageTest extends TestCase
{
    public function testItCanAttachFromStorage()
    {
        Storage::disk('local')->put('/dir/foo.png', 'expected body contents');
        $mail = new MailMessage();
        $attachment = Attachment::fromStorageDisk('local', '/dir/foo.png')
            ->as('bar')
            ->withMime('text/css');

        $attachment->attachTo($mail);

        $this->assertSame([
            'data' => 'expected body contents',
            'name' => 'bar',
            'options' => [
                'mime' => 'text/css',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/dir/foo.png');
   }

    public function testItCanAttachFromStorageAndFallbackToStorageNameAndMime()
    {
        Storage::disk()->put('/dir/foo.png', 'expected body contents');
        $mail = new MailMessage();
        $attachment = Attachment::fromStorageDisk('local', '/dir/foo.png');

        $attachment->attachTo($mail);

        $this->assertSame([
            'data' => 'expected body contents',
            'name' => 'foo.png',
            'options' => [
                'mime' => 'image/png',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/dir/foo.png');
   }
}
