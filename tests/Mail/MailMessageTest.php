<?php

namespace Illuminate\Tests\Mail;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Message;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailMessageTest extends TestCase
{
    /**
     * @var \Illuminate\Mail\Message
     */
    protected $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->message = new Message(new Email());
    }

    public function testFromMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->from('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getFrom()[0]);
    }

    public function testSenderMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->sender('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getSender());
    }

    public function testReturnPathMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->returnPath('foo@bar.baz'));
        $this->assertEquals(new Address('foo@bar.baz'), $message->getSymfonyMessage()->getReturnPath());
    }

    public function testToMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->to('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getTo()[0]);

        $this->assertInstanceOf(Message::class, $message = $this->message->to(['bar@bar.baz' => 'Bar']));
        $this->assertEquals(new Address('bar@bar.baz', 'Bar'), $message->getSymfonyMessage()->getTo()[0]);
    }

    public function testToMethodWithOverride()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->to('foo@bar.baz', 'Foo', true));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getTo()[0]);
    }

    public function testCcMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->cc('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getCc()[0]);
    }

    public function testBccMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->bcc('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getBcc()[0]);
    }

    public function testReplyToMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->replyTo('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getReplyTo()[0]);
    }

    public function testSubjectMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->subject('foo'));
        $this->assertSame('foo', $message->getSymfonyMessage()->getSubject());
    }

    public function testPriorityMethod()
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->priority(1));
        $this->assertEquals(1, $message->getSymfonyMessage()->getPriority());
    }

    public function testBasicAttachment()
    {
        $path = __DIR__.'/foo.jpg';
        file_put_contents($path, 'expected attachment body');

        $this->message->attach($path, ['as' => 'foo.jpg', 'mime' => 'image/jpeg']);

        $this->assertSame('expected attachment body', $this->message->getSymfonyMessage()->getAttachments()[0]->getBody());

        unlink($path);
    }

    public function testDataAttachment()
    {
        $message = new Message(new Email());
        $message->attachData('foo', 'foo.jpg', ['mime' => 'image/jpeg']);

        $this->assertSame('foo', $message->getSymfonyMessage()->getAttachments()[0]->getBody());
    }

    public function testItAttachesFilesViaAttachableContractFromPath()
    {
        $path = __DIR__.'/foo.jpg';
        file_put_contents($path, 'expected attachment body');

        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromPath(__DIR__.'/foo.jpg');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: image/jpeg; name=foo.jpg',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg'
        ], $attachment->getPreparedHeaders()->toArray());

        unlink($path);
    }

    public function testItAttachesFilesViaAttachableContractFromPathWithFilename()
    {
        $path = __DIR__.'/foo.jpg';
        file_put_contents($path, 'expected attachment body');

        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromPath(__DIR__.'/foo.jpg')->as('bar');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: image/jpeg; name=bar',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=bar; filename=bar'
        ], $attachment->getPreparedHeaders()->toArray());

        unlink($path);
    }

    public function testItAttachesFilesViaAttachableContractFromPathWithMime()
    {
        $path = __DIR__.'/foo.jpg';
        file_put_contents($path, 'expected attachment body');

        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromPath(__DIR__.'/foo.jpg')->withMime('text/css');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: text/css; name=foo.jpg',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg'
        ], $attachment->getPreparedHeaders()->toArray());

        unlink($path);
    }

    public function testItAttachesFilesViaAttachableContractFromData()
    {
        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromData('expected attachment body', 'foo.jpg');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: application/octet-stream; name=foo.jpg',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg'
        ], $attachment->getPreparedHeaders()->toArray());
    }

    public function testItAttachesFilesViaAttachableContractFromDataWithMime()
    {
        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromData('expected attachment body', 'foo.jpg')
                    ->withMime('image/jpeg');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: image/jpeg; name=foo.jpg',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg'
        ], $attachment->getPreparedHeaders()->toArray());
    }

    public function testItAttachesFilesViaAttachableContractFromDataWithMimeWithClosure()
    {
        $this->message->attach(new class () implements Attachable {
            public function toMailAttachment()
            {
                return Attachment::fromData(fn () => 'expected attachment body', 'foo.jpg')
                    ->withMime('image/jpeg');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame([
            'Content-Type: image/jpeg; name=foo.jpg',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg'
        ], $attachment->getPreparedHeaders()->toArray());
    }
}
