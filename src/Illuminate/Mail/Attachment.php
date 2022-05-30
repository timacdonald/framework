<?php

namespace Illuminate\Mail;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Traits\Macroable;

class Attachment
{
    use Macroable;

    /**
     * The attached file's filename.
     *
     * @var string|null
     */
    protected $as;

    /**
     * The attached file's mime type.
     *
     * @var string|null
     */
    protected $mime;

    /**
     * Attaches the attachment to the mail message.
     *
     * @var \Closure
     */
    protected $attacher;

    /**
     * Create a mail attachment.
     *
     * @param  \Closure  $attacher
     */
    private function __construct($attacher)
    {
        $this->attacher = $attacher;
    }

    /**
     * Create a mail attachment from a file on disk.
     *
     * @param  string  $file
     * @return static
     */
    public static function fromPath($file)
    {
        return new static(fn ($message, $attachment) => $message->attach(
            $file, ['as' => $attachment->as, 'mime' => $attachment->mime]
        ));
    }

    /**
     * Create a mail attachment from a file in the default storage disk.
     *
     * @param  string  $path
     * @return static
     */
    public static function fromStorage($path)
    {
        return static::fromStorageDisk(null, $path);
    }

    /**
     * Create a mail attachment from a file in the specified storage disk.
     *
     * @param  string  $disk
     * @param  string  $path
     * @return static
     */
    public static function fromStorageDisk($disk, $path)
    {
        return new static(function ($message, $attachment) use ($disk, $path) {
            $storage = Container::getInstance()->make(
                FilesystemFactory::class
            )->disk($disk);

            $message->attachData(
                $storage->get($path),
                $attachment->as ?? basename($path),
                ['mime' => $attachment->mime ?? $storage->mimeType($path)]
            );
        });
    }

    /**
     * Create a mail attachment from in-memory data.
     *
     * @param  string|\Closure  $data
     * @param  string  $name
     * @return static
     */
    public static function fromData($data, $name)
    {
        return (new static(fn ($message, $attachment) => $message->attachData(
            value($data), $attachment->as, ['mime' => $attachment->mime]
        )))->as($name);
    }

    /**
     * Set the attached file's filename.
     *
     * @param  string  $name
     */
    public function as($name)
    {
        $this->as = $name;

        return $this;
    }

    /**
     * Set the attached file's mime type.
     *
     * @param  string  $mime
     */
    public function withMime($mime)
    {
        $this->mime = $mime;

        return $this;
    }

    /**
     * Attach the file to the message.
     *
     * @param  mixed  $message
     */
    public function attachTo($message)
    {
        return tap($message, fn ($message) => ($this->attacher)($message, $this));
    }
}
