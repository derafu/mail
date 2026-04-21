<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail\Model;

use DateTime;
use Derafu\Mail\Contract\MailboxInterface;
use stdClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Email mailbox that will be used in the strategy that receives emails using
 * IMAP.
 */
class Mailbox implements MailboxInterface
{
    private Client $mailbox;

    private string $currentFolder;

    /**
     * Constructor.
     *
     * @param string $imapPath The IMAP path in the format {host:port/flags}folder.
     * @param string $login The login.
     * @param string $password The password.
     */
    public function __construct(
        string $imapPath,
        string $login,
        string $password,
    ) {
        [$host, $port, $encryption, $validateCert, $folder] = $this->parseDsn($imapPath);

        $config = Config::make([
            'default' => 'default',
            'accounts' => [
                'default' => [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'validate_cert' => $validateCert,
                    'username' => $login,
                    'password' => $password,
                ],
            ],
        ]);

        $this->mailbox = new Client($config);
        $this->currentFolder = $folder;
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected(): bool
    {
        return $this->mailbox->isConnected();
    }

    /**
     * {@inheritDoc}
     */
    public function status(?string $folder = null): stdClass
    {
        $this->ensureConnected();
        $folderPath = $folder ?? $this->currentFolder;
        $statusArray = $this->mailbox->getFolderByPath($folderPath)->status();

        return (object) $statusArray;
    }

    /**
     * {@inheritDoc}
     */
    public function countUnreadMails(?string $folder = null): int
    {
        $status = $this->status($folder);

        return $status->unseen ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getMailbox(): Client
    {
        return $this->mailbox;
    }

    /**
     * {@inheritDoc}
     */
    public function searchMailbox(
        string $criteria = 'ALL',
        bool $disableServerEncoding = false
    ): array {
        $this->ensureConnected();
        $folder = $this->mailbox->getFolderByPath($this->currentFolder);

        return array_map(
            'intval',
            $folder->query()
                ->setSequence(IMAP::ST_UID)
                ->where("CUSTOM " . $this->normalizeImapDates($criteria))
                ->search()
                ->toArray()
        );
    }

    /**
     * Converts ISO dates (Y-m-d) in an IMAP criteria string to the format
     * required by the protocol (d-M-Y, e.g. 19-Apr-2026).
     */
    private function normalizeImapDates(string $criteria): string
    {
        return preg_replace_callback(
            '/\b(\d{4})-(\d{2})-(\d{2})\b/',
            fn (array $m) => (new DateTime("{$m[1]}-{$m[2]}-{$m[3]}"))->format('d-M-Y'),
            $criteria
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getMail(int $mailId, bool $markAsSeen = true): Message
    {
        $this->ensureConnected();
        $query = $this->mailbox
            ->getFolderByPath($this->currentFolder)
            ->query()
            ->setSequence(IMAP::ST_UID);

        if (!$markAsSeen) {
            $query->leaveUnread();
        }

        return $query->getMessageByUid($mailId);
    }

    /**
     * {@inheritDoc}
     */
    public function markMailsAsRead(array $mailIds): void
    {
        $this->ensureConnected();
        $folder = $this->mailbox->getFolderByPath($this->currentFolder);

        foreach ($mailIds as $mailId) {
            $folder->query()
                ->leaveUnread()
                ->setSequence(IMAP::ST_UID)
                ->setFetchBody(false)
                ->getMessageByUid($mailId)
                ->setFlag('Seen');
        }
    }

    /**
     * Parses an IMAP DSN string like {host:port/flags}folder into its components.
     *
     * @param string $dsn
     * @return array{0: string, 1: int, 2: string, 3: bool, 4: string}
     */
    private function parseDsn(string $dsn): array
    {
        preg_match('/^\{([^:]+):(\d+)(?:\/([^}]*))?\}(.*)$/', $dsn, $matches);

        $host = $matches[1] ?? 'localhost';
        $port = (int) ($matches[2] ?? 993);
        $flags = $matches[3] ?? '';
        $folder = $matches[4] !== '' ? $matches[4] : 'INBOX';

        if (str_contains($flags, 'ssl')) {
            $encryption = 'ssl';
        } elseif (str_contains($flags, 'starttls')) {
            $encryption = 'starttls';
        } elseif (str_contains($flags, 'tls')) {
            $encryption = 'tls';
        } else {
            $encryption = 'none';
        }

        $validateCert = !str_contains($flags, 'novalidate-cert');

        return [$host, $port, $encryption, $validateCert, $folder];
    }

    private function ensureConnected(): void
    {
        if (!$this->mailbox->isConnected()) {
            $this->mailbox->connect();
        }
    }
}
