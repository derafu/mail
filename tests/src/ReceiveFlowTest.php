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
use Derafu\Mail\Component\Exchange\Worker\Receiver\Handler\ReceiveHandler;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\Abstract\AbstractMailboxStrategy;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\ImapStrategy;
use Derafu\Mail\Component\Exchange\Worker\ReceiverWorker;
use Derafu\Mail\Contract\MailboxInterface;
use Derafu\Mail\Exception\MailException;
use Derafu\Mail\Factory\EnvelopeFactory;
use Derafu\Mail\Model\Envelope;
use Derafu\Mail\Model\Message;
use Derafu\Mail\Model\Postman;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\UndefinedOptionsException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Mailbox that serves the fixtures instead of an IMAP server.
 */
final class FixtureMailbox implements MailboxInterface
{
    /** @var list<string> */
    public array $criteria = [];

    /** @var list<int> */
    public array $markedAsRead = [];

    /** @param array<int, string> $mails Name of the fixture of each UID. */
    public function __construct(private readonly array $mails)
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function status(?string $folder = null): stdClass
    {
        return new stdClass();
    }

    public function countUnreadMails(?string $folder = null): int
    {
        return count($this->mails);
    }

    public function getMailbox(): Client
    {
        throw new LogicException('The fixture mailbox has no IMAP client.');
    }

    public function searchMailbox(
        string $criteria = 'ALL',
        bool $disableServerEncoding = false
    ): array {
        $this->criteria[] = $criteria;

        return array_keys($this->mails);
    }

    public function getMail(int $mailId, bool $markAsSeen = true): ImapMessage
    {
        $path = __DIR__ . '/../fixtures/eml/' . $this->mails[$mailId] . '.eml';
        $eml = is_file($path) ? file_get_contents($path) : false;
        if ($eml === false) {
            throw new RuntimeException('Fixture not found: ' . $this->mails[$mailId]);
        }

        $mail = ImapMessage::fromString($eml);
        $mail->__set('msgn', $mailId);
        $mail->__set('uid', $mailId);

        return $mail;
    }

    public function markMailsAsRead(array $mailIds): void
    {
        $this->markedAsRead = array_merge($this->markedAsRead, $mailIds);
    }
}

/**
 * IMAP strategy that reads from a mailbox given instead of connecting.
 */
final class FixtureMailboxStrategy extends ImapStrategy
{
    public function __construct(private readonly MailboxInterface $mailbox)
    {
        parent::__construct(new EnvelopeFactory());
    }

    protected function createMailbox(OptionsInterface $options): MailboxInterface
    {
        return $this->mailbox;
    }
}

#[CoversClass(ReceiverWorker::class)]
#[CoversClass(ReceiveHandler::class)]
#[CoversClass(AbstractMailboxStrategy::class)]
#[CoversClass(ImapStrategy::class)]
#[CoversClass(EnvelopeFactory::class)]
#[CoversClass(Envelope::class)]
#[CoversClass(Message::class)]
#[CoversClass(Postman::class)]
final class ReceiveFlowTest extends TestCase
{
    /**
     * @param array<string, mixed> $search
     * @return array{ReceiverWorker, Postman}
     */
    private function receiverFor(MailboxInterface $mailbox, array $search = [], string $strategy = 'imap'): array
    {
        $worker = new ReceiverWorker([
            'receive' => new ReceiveHandler([
                'imap' => new FixtureMailboxStrategy($mailbox),
            ]),
        ]);
        $postman = new Postman([
            'strategy' => $strategy,
            'transport' => ['username' => 'user', 'password' => 'pass', 'search' => $search],
        ]);

        return [$worker, $postman];
    }

    #[Test]
    public function everyMailOfTheSearchBecomesAnEnvelope(): void
    {
        $mailbox = new FixtureMailbox([10 => 'only-from', 11 => 'with-attachment']);
        [$worker, $postman] = $this->receiverFor($mailbox);

        $envelopes = $worker->receive($postman);

        $this->assertCount(2, $envelopes);
        $subjects = [];
        foreach ($envelopes as $envelope) {
            foreach ($envelope->getMessages() as $message) {
                $this->assertInstanceOf(Message::class, $message);
                $subjects[] = $message->getSubject();
            }
        }
        $this->assertSame(['Only From', 'With attachment'], $subjects);
        $this->assertCount(2, $postman->getEnvelopes());
    }

    #[Test]
    public function theDefaultCriteriaIsUnseenAndTheMailsAreLeftUnread(): void
    {
        $mailbox = new FixtureMailbox([10 => 'only-from']);
        [$worker, $postman] = $this->receiverFor($mailbox);

        $worker->receive($postman);

        $this->assertSame(['UNSEEN'], $mailbox->criteria);
        $this->assertSame([], $mailbox->markedAsRead);
    }

    #[Test]
    public function theCriteriaOfTheOptionsIsUsedToSearch(): void
    {
        $mailbox = new FixtureMailbox([]);
        [$worker, $postman] = $this->receiverFor($mailbox, ['criteria' => 'UNSEEN SINCE 2026-01-01']);

        $worker->receive($postman);

        $this->assertSame(['UNSEEN SINCE 2026-01-01'], $mailbox->criteria);
    }

    #[Test]
    public function theMailsAreMarkedAsReadWhenTheyAreAskedTo(): void
    {
        $mailbox = new FixtureMailbox([10 => 'only-from', 11 => 'no-subject']);
        [$worker, $postman] = $this->receiverFor($mailbox, ['markAsSeen' => true]);

        $worker->receive($postman);

        $this->assertSame([10, 11], $mailbox->markedAsRead);
    }

    #[Test]
    public function theAttachmentFiltersOfTheOptionsAreApplied(): void
    {
        $mailbox = new FixtureMailbox([10 => 'attachments-mixed']);
        [$worker, $postman] = $this->receiverFor($mailbox, [
            'attachmentFilters' => ['extension' => ['pdf']],
        ]);

        $envelopes = $worker->receive($postman);

        $message = $envelopes[0]->getMessages()[0];
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['BOLETA.PDF'], array_map(
            fn ($attachment) => $attachment->getFilename(),
            $message->getAttachments()
        ));
    }

    #[Test]
    public function aMailThatFailsStopsTheReceptionAndNoneIsMarkedAsRead(): void
    {
        $mailbox = new FixtureMailbox([10 => 'only-from', 11 => 'does-not-exist']);
        [$worker, $postman] = $this->receiverFor($mailbox, ['markAsSeen' => true]);

        try {
            $worker->receive($postman);
            $this->fail('A mail that cannot be processed must be reported.');
        } catch (MailException $exception) {
            $this->assertStringContainsString('Fixture not found: does-not-exist', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame([], $mailbox->markedAsRead);
        }
    }

    #[Test]
    public function anUnknownFilterIsRejected(): void
    {
        [$worker, $postman] = $this->receiverFor(
            new FixtureMailbox([]),
            ['attachmentFilters' => ['subtipe' => ['xml']]]
        );

        try {
            $worker->receive($postman);
            $this->fail('An unknown filter must be rejected.');
        } catch (MailException $exception) {
            $this->assertInstanceOf(UndefinedOptionsException::class, $exception->getPrevious());
            $this->assertStringContainsString('subtipe', $exception->getMessage());
        }
    }

    #[Test]
    public function aStrategyThatIsNotAStringIsRejected(): void
    {
        [$worker] = $this->receiverFor(new FixtureMailbox([]));

        $this->expectException(InvalidOptionsException::class);
        $worker->receive(new Postman(['strategy' => 123]));
    }
}
