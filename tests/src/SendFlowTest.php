<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Email Sending and Receiving Library.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsMail;

use Derafu\Config\Contract\OptionsInterface;
use Derafu\Mail\Component\Exchange\Worker\Sender\Handler\SendHandler;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\Abstract\AbstractMailerStrategy;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\SmtpStrategy;
use Derafu\Mail\Component\Exchange\Worker\SenderWorker;
use Derafu\Mail\Exception\MailException;
use Derafu\Mail\Model\Envelope;
use Derafu\Mail\Model\Message;
use Derafu\Mail\Model\Postman;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

/**
 * SMTP strategy whose transport can not be created, like a server that is down.
 */
final class UnreachableSmtpStrategy extends SmtpStrategy
{
    protected function createMailer(OptionsInterface $options): Mailer
    {
        throw new RuntimeException('Connection refused.');
    }
}

#[CoversClass(SenderWorker::class)]
#[CoversClass(SendHandler::class)]
#[CoversClass(AbstractMailerStrategy::class)]
#[CoversClass(SmtpStrategy::class)]
#[CoversClass(Envelope::class)]
#[CoversClass(Message::class)]
#[CoversClass(Postman::class)]
final class SendFlowTest extends TestCase
{
    private SenderWorker $worker;

    protected function setUp(): void
    {
        $this->worker = new SenderWorker([
            'send' => new SendHandler([
                'smtp' => new SmtpStrategy(),
            ]),
        ]);
    }

    /** The `null` transport of Symfony Mailer accepts the messages and sends nothing. */
    private function postman(): Postman
    {
        return new Postman([
            'transport' => ['username' => 'user', 'password' => 'pass', 'dsn' => 'null://null'],
        ]);
    }

    private function message(string $subject, bool $withBody = true): Message
    {
        $message = new Message();
        $message->subject($subject);
        if ($withBody) {
            $message->text('Body of ' . $subject);
        }

        return $message;
    }

    #[Test]
    public function theMessagesOfTheEnvelopesAreSent(): void
    {
        $postman = $this->postman();
        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $first = $this->message('First');
        $second = $this->message('Second');
        $envelope->addMessage($first)->addMessage($second);
        $postman->addEnvelope($envelope);

        $sent = $this->worker->send($postman);

        $this->assertSame([$envelope], $sent);
        $this->assertFalse($first->hasError());
        $this->assertFalse($second->hasError());
    }

    #[Test]
    public function aMessageThatFailsIsMarkedWithItsErrorAndTheOthersAreStillSent(): void
    {
        $postman = $this->postman();
        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $broken = $this->message('Without body', withBody: false);
        $good = $this->message('Good');
        $envelope->addMessage($broken)->addMessage($good);
        $postman->addEnvelope($envelope);

        $this->worker->send($postman);

        $this->assertTrue($broken->hasError());
        $this->assertNotNull($broken->getError());
        $this->assertFalse($good->hasError());
    }

    #[Test]
    public function aFailureOfTheStrategyIsReportedAsAMailException(): void
    {
        $worker = new SenderWorker([
            'send' => new SendHandler(['smtp' => new UnreachableSmtpStrategy()]),
        ]);

        try {
            $worker->send($this->postman());
            $this->fail('A failure of the strategy must be reported.');
        } catch (MailException $exception) {
            $this->assertSame('Connection refused.', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function aServerThatRefusesTheConnectionMarksTheMessageWithTheError(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $postman = new Postman(['transport' => [
            'username' => 'user',
            'password' => 'pass',
            'host' => '127.0.0.1',
            'port' => $port,
            'encryption' => null,
            'verify_peer' => false,
        ]]);
        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $message = $this->message('Nobody listens');
        $envelope->addMessage($message);
        $postman->addEnvelope($envelope);

        $this->worker->send($postman);

        $this->assertTrue($message->hasError());
        $this->assertInstanceOf(TransportExceptionInterface::class, $message->getError());
    }

    #[Test]
    public function aStrategyThatIsNotAStringIsRejected(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->worker->send(new Postman(['strategy' => 123]));
    }
}
