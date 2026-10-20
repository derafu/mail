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
use Derafu\Mail\Exception\MailException;
use stdClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
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
        $statusArray = $this->folder($folder)->status();

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
        $tokens = $this->folder()->query()
            ->setSequence(IMAP::ST_UID)
            ->where("CUSTOM " . $this->normalizeImapDates($criteria))
            ->search()
            ->toArray();

        // The answer of the server has the UIDs and, with some extensions of
        // the protocol (like the `MODSEQ` of CONDSTORE), other values that are
        // not UIDs and do not identify any mail.
        $uids = [];
        foreach ($tokens as $token) {
            if (is_int($token) || (is_string($token) && ctype_digit($token))) {
                $uids[] = (int) $token;
            }
        }

        return $uids;
    }

    /**
     * Converts ISO dates (Y-m-d) in an IMAP criteria string to the format
     * required by the protocol (d-M-Y, e.g. 19-Apr-2026).
     *
     * @throws MailException If a date does not exist (like 2026-02-30).
     */
    private function normalizeImapDates(string $criteria): string
    {
        $normalized = preg_replace_callback(
            '/\b(\d{4})-(\d{2})-(\d{2})\b/',
            function (array $m): string {
                if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    throw new MailException([
                        'The date {date} of the search criteria is not valid.',
                        'date' => $m[0],
                    ]);
                }

                return (new DateTime($m[0]))->format('d-M-Y');
            },
            $criteria
        );

        return $normalized ?? throw new MailException([
            'The search criteria could not be processed: {error}',
            'error' => preg_last_error_msg(),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getMail(int $mailId, bool $markAsSeen = true): Message
    {
        $query = $this->folder()->query()->setSequence(IMAP::ST_UID);

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
        $folder = $this->folder();

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
     * @throws MailException If the DSN does not have that format.
     */
    private function parseDsn(string $dsn): array
    {
        if (!preg_match('/^\{([^:]+):(\d+)(?:\/([^}]*))?\}(.*)$/', $dsn, $matches)) {
            throw new MailException([
                'The IMAP DSN {dsn} is not valid; the format is {format}.',
                'dsn' => $dsn,
                'format' => '{host:port/flags}folder',
            ]);
        }

        $host = $matches[1];
        $port = (int) $matches[2];
        $flags = $matches[3];
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

    /**
     * Returns a folder of the mailbox, connecting if it is needed.
     *
     * @param string|null $path Path of the folder, or the current one.
     * @throws MailException If the folder does not exist in the server.
     */
    private function folder(?string $path = null): Folder
    {
        $this->ensureConnected();

        $path ??= $this->currentFolder;

        return $this->mailbox->getFolderByPath($path) ?? throw new MailException([
            'The mailbox folder {folder} does not exist.',
            'folder' => $path,
        ]);
    }

    private function ensureConnected(): void
    {
        if (!$this->mailbox->isConnected()) {
            $this->mailbox->connect();
        }
    }
}
