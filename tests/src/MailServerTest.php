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

use DateTimeImmutable;
use Derafu\Mail\Component\Exchange\ExchangeComponent;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Handler\ReceiveHandler;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\Abstract\AbstractMailboxStrategy;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\ImapStrategy;
use Derafu\Mail\Component\Exchange\Worker\ReceiverWorker;
use Derafu\Mail\Component\Exchange\Worker\Sender\Handler\SendHandler;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\Abstract\AbstractMailerStrategy;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\SmtpStrategy;
use Derafu\Mail\Component\Exchange\Worker\SenderWorker;
use Derafu\Mail\Exception\MailException;
use Derafu\Mail\Factory\EnvelopeFactory;
use Derafu\Mail\MailPackage;
use Derafu\Mail\Model\Envelope;
use Derafu\Mail\Model\Mailbox;
use Derafu\Mail\Model\Message;
use Derafu\Mail\Model\Postman;
use Derafu\TestsMail\Fixture\GreenMailServer;
use Derafu\TestsMail\Fixture\MailContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;

/**
 * Sends and receives mails against a real mail server (GreenMail, in Docker).
 */
#[CoversClass(SenderWorker::class)]
#[CoversClass(SendHandler::class)]
#[CoversClass(AbstractMailerStrategy::class)]
#[CoversClass(SmtpStrategy::class)]
#[CoversClass(ReceiverWorker::class)]
#[CoversClass(ReceiveHandler::class)]
#[CoversClass(AbstractMailboxStrategy::class)]
#[CoversClass(ImapStrategy::class)]
#[CoversClass(Mailbox::class)]
#[CoversClass(EnvelopeFactory::class)]
#[CoversClass(Envelope::class)]
#[CoversClass(Message::class)]
#[CoversClass(Postman::class)]
#[CoversClass(MailPackage::class)]
#[CoversClass(ExchangeComponent::class)]
final class MailServerTest extends TestCase
{
    private static GreenMailServer $server;

    private SenderWorker $sender;

    private ReceiverWorker $receiver;

    public static function setUpBeforeClass(): void
    {
        self::$server = GreenMailServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->purge();

        $this->sender = new SenderWorker([
            'send' => new SendHandler(['smtp' => new SmtpStrategy()]),
        ]);
        $this->receiver = new ReceiverWorker([
            'receive' => new ReceiveHandler(['imap' => new ImapStrategy(new EnvelopeFactory())]),
        ]);
    }

    /**
     * Sends a mail from `ana` to `beto`.
     *
     * @param array<string, string> $attachments Content of each attachment by name.
     */
    private function send(string $subject, array $attachments = [], string $text = 'Body'): Message
    {
        $message = new Message();
        $message->subject($subject);
        $message->text($text);
        foreach ($attachments as $name => $content) {
            $message->attach($content, $name, 'application/octet-stream');
        }

        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $envelope->addMessage($message);
        $postman = new Postman(['transport' => self::$server->smtpTransport()]);
        $postman->addEnvelope($envelope);

        $this->sender->send($postman);
        $this->assertNull($message->getError());

        return $message;
    }

    /**
     * Receives the mails of `beto`.
     *
     * @param array<string, mixed> $search
     * @return list<Message>
     */
    private function receive(array $search = [], string $user = 'beto'): array
    {
        $postman = new Postman(['transport' => self::$server->imapTransport($user, $search)]);

        $messages = [];
        foreach ($this->receiver->receive($postman) as $envelope) {
            foreach ($envelope->getMessages() as $message) {
                $this->assertInstanceOf(Message::class, $message);
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function mailbox(string $user = 'beto', string $folder = 'INBOX'): Mailbox
    {
        return new Mailbox(self::$server->imapDsn($folder), $user, GreenMailServer::PASSWORD);
    }

    #[Test]
    public function aSentMailIsReceivedWithItsContent(): void
    {
        $this->send('Hello', ['dte.xml' => '<dte/>'], 'The text.');

        $messages = $this->receive();

        $this->assertCount(1, $messages);
        $this->assertSame('Hello', $messages[0]->getSubject());
        $this->assertSame('The text.', trim((string) $messages[0]->getTextBody()));
        $this->assertSame(['ana@example.com'], array_map(fn ($from) => $from->getAddress(), $messages[0]->getFrom()));
        $attachments = $messages[0]->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('dte.xml', $attachments[0]->getFilename());
        $this->assertSame('<dte/>', $attachments[0]->getBody());
    }

    #[Test]
    public function theReceivedMessageHasTheUidOfTheMailAsItsId(): void
    {
        // After the purge the UID of the next mail is not 1, so it can not be
        // confused with its position in the folder.
        $this->send('First');
        self::$server->purge();
        $this->send('Second');
        $uid = $this->mailbox()->searchMailbox('ALL')[0];

        $messages = $this->receive();

        $this->assertGreaterThan(1, $uid);
        $this->assertSame($uid, $messages[0]->getId());
    }

    #[Test]
    public function thePackageGivesAccessToTheWorkersThatSendAndReceive(): void
    {
        $component = new ExchangeComponent($this->receiver, $this->sender);
        $package = new MailPackage($component);

        $this->assertSame(['exchange' => $component], $package->getComponents());
        $this->assertSame($component, $package->getExchangeComponent());
        $this->assertSame(
            ['receiver' => $this->receiver, 'sender' => $this->sender],
            $component->getWorkers()
        );

        // As the applications use it: everything from the package.
        $message = new Message();
        $message->subject('From the package');
        $message->text('Body');
        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $envelope->addMessage($message);
        $postman = new Postman(['transport' => self::$server->smtpTransport()]);
        $postman->addEnvelope($envelope);
        $package->getExchangeComponent()->getSenderWorker()->send($postman);
        $this->assertNull($message->getError());

        $received = $package->getExchangeComponent()->getReceiverWorker()->receive(
            new Postman(['transport' => self::$server->imapTransport()])
        );

        $this->assertCount(1, $received);
        $first = $received[0]->getMessages()[0];
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame('From the package', $first->getSubject());
    }

    #[Test]
    public function theWiringThatThePackageShipsSendsAndReceives(): void
    {
        // The workers, handlers and strategies are the ones that the container
        // builds from `mail-services.yaml`, not the ones of the other tests.
        $component = MailContainer::package()->getExchangeComponent();

        $message = new Message();
        $message->subject('From the container');
        $message->text('Body');
        $envelope = new Envelope(new Address('ana@example.com'), [new Address('beto@example.com')]);
        $envelope->addMessage($message);
        $postman = new Postman(['transport' => self::$server->smtpTransport()]);
        $postman->addEnvelope($envelope);
        $component->getSenderWorker()->send($postman);
        $this->assertNull($message->getError());

        $received = $component->getReceiverWorker()->receive(
            new Postman(['transport' => self::$server->imapTransport()])
        );

        $this->assertCount(1, $received);
        $first = $received[0]->getMessages()[0];
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame('From the container', $first->getSubject());
    }

    #[Test]
    public function theEnvelopeKeepsTheSenderAndTheRecipients(): void
    {
        $this->send('Hello');

        $postman = new Postman(['transport' => self::$server->imapTransport()]);
        $envelopes = $this->receiver->receive($postman);

        $this->assertCount(1, $envelopes);
        $envelope = $envelopes[0];
        $this->assertInstanceOf(Envelope::class, $envelope);
        $this->assertSame('ana@example.com', $envelope->getSender()->getAddress());
        $this->assertSame(['beto@example.com'], array_map(
            fn ($recipient) => $recipient->getAddress(),
            $envelope->getRecipients()
        ));
    }

    #[Test]
    public function theMailsAreLeftUnreadUnlessTheyAreAskedToBeMarked(): void
    {
        $this->send('Hello');

        $this->assertCount(1, $this->receive());
        $this->assertCount(1, $this->receive());
        $this->assertSame(1, $this->mailbox()->countUnreadMails());
    }

    #[Test]
    public function theMarkedMailsAreNotReceivedAgain(): void
    {
        $this->send('Hello');

        $this->assertCount(1, $this->receive(['markAsSeen' => true]));
        $this->assertCount(0, $this->receive());
        $this->assertSame(0, $this->mailbox()->countUnreadMails());
    }

    #[Test]
    public function theMailsOfAnotherUserAreNotReceived(): void
    {
        $this->send('Hello');

        $this->assertCount(0, $this->receive(user: 'ana'));
    }

    #[Test]
    public function theAttachmentFiltersAreAppliedToTheReceivedMail(): void
    {
        $this->send('Documents', ['dte.xml' => '<dte/>', 'boleta.pdf' => 'pdf', 'notes.txt' => 'txt']);

        $messages = $this->receive(['attachmentFilters' => ['extension' => ['xml', 'pdf']]]);

        $this->assertSame(
            ['boleta.pdf', 'dte.xml'],
            $this->sorted(array_values(array_map(fn ($attachment) => $attachment->getFilename() ?? '', $messages[0]->getAttachments())))
        );
    }

    #[Test]
    public function theCriteriaCanUseDatesInIsoFormat(): void
    {
        $this->send('Hello');
        $past = (new DateTimeImmutable('2 days ago'))->format('Y-m-d');
        $future = (new DateTimeImmutable('2 days'))->format('Y-m-d');

        $this->assertCount(1, $this->receive(['criteria' => 'UNSEEN SINCE ' . $past]));
        $this->assertCount(0, $this->receive(['criteria' => 'UNSEEN SINCE ' . $future]));
    }

    #[Test]
    public function aDateThatDoesNotExistInTheCriteriaIsReported(): void
    {
        foreach (['2026-02-30', '2026-13-45'] as $date) {
            try {
                $this->receive(['criteria' => 'UNSEEN SINCE ' . $date]);
                $this->fail('A date that does not exist must be reported.');
            } catch (MailException $exception) {
                $this->assertSame(
                    'An error occurred while receiving the emails: The date ' . $date . ' of the search criteria is not valid.',
                    $exception->getMessage()
                );
            }
        }
    }

    #[Test]
    public function theMailboxReportsItsStatusAndFindsTheMails(): void
    {
        $this->send('First');
        $this->send('Second');
        $mailbox = $this->mailbox();

        $status = $mailbox->status();
        $this->assertSame(2, $status->messages);
        $this->assertSame(2, $mailbox->countUnreadMails());
        $this->assertTrue($mailbox->isConnected());
        $this->assertCount(2, $mailbox->searchMailbox('ALL'));
        $this->assertCount(0, $mailbox->searchMailbox('FROM nobody@example.com'));
    }

    #[Test]
    public function readingAMailWithoutMarkingItLeavesItUnread(): void
    {
        $this->send('Hello');
        $mailbox = $this->mailbox();
        $ids = $mailbox->searchMailbox('ALL');

        $message = $mailbox->getMail($ids[0], markAsSeen: false);

        $this->assertSame('Hello', $message->getSubject()->first());
        $this->assertSame(1, $mailbox->countUnreadMails());
    }

    #[Test]
    public function markingTheMailsAsReadLeavesNoneUnread(): void
    {
        $this->send('First');
        $this->send('Second');
        $mailbox = $this->mailbox();

        $mailbox->markMailsAsRead($mailbox->searchMailbox('ALL'));

        $this->assertSame(0, $mailbox->countUnreadMails());
        $this->assertCount(2, $mailbox->searchMailbox('SEEN'));
    }

    #[Test]
    public function aFolderThatDoesNotExistIsReported(): void
    {
        $postman = new Postman(['transport' => [
            'mailbox' => 'Nope',
        ] + self::$server->imapTransport()]);

        try {
            $this->receiver->receive($postman);
            $this->fail('A folder that does not exist must be reported.');
        } catch (MailException $exception) {
            $this->assertSame(
                'An error occurred while receiving the emails: The mailbox folder Nope does not exist.',
                $exception->getMessage()
            );
        }
    }

    #[Test]
    public function everyOperationOfTheMailboxReportsAFolderThatDoesNotExist(): void
    {
        $mailbox = $this->mailbox(folder: 'Nope');

        $operations = [
            'status' => fn () => $mailbox->status(),
            'search' => fn () => $mailbox->searchMailbox('ALL'),
            'get' => fn () => $mailbox->getMail(1),
            'mark' => fn () => $mailbox->markMailsAsRead([1]),
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail('The operation "' . $name . '" must report the folder.');
            } catch (MailException $exception) {
                $this->assertStringContainsString('Nope', $exception->getMessage(), $name);
            }
        }
    }

    #[Test]
    public function aWrongPasswordIsReported(): void
    {
        $postman = new Postman(['transport' => [
            'password' => 'wrong',
        ] + self::$server->imapTransport()]);

        try {
            $this->receiver->receive($postman);
            $this->fail('A wrong password must be reported.');
        } catch (MailException $exception) {
            $this->assertStringContainsString('Invalid login/password', $exception->getMessage());
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
