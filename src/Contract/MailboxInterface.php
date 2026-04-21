<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail\Contract;

use stdClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Interface for the email mailbox.
 */
interface MailboxInterface
{
    /**
     * Checks if the IMAP server is connected.
     *
     * @return bool `true` if connected, `false` otherwise.
     */
    public function isConnected(): bool;

    /**
     * Gets the status of the email mailbox.
     *
     * @param string|null $folder Folder to consult, `null` for the current one.
     * @return stdClass Object with the status of the email mailbox.
     */
    public function status(?string $folder = null): stdClass;

    /**
     * Counts the number of unread messages in the email mailbox.
     *
     * @param string|null $folder Folder to consult, `null` for the current one.
     * @return int Number of unread messages.
     */
    public function countUnreadMails(?string $folder = null): int;

    // -------------------------------------------------------------------------
    // From here on, the methods are from the PhpImap library.
    // -------------------------------------------------------------------------

    /**
     * Gets the underlying IMAP client.
     *
     * @return Client
     */
    public function getMailbox(): Client;

    /**
     * Searches the mails in the email mailbox.
     *
     * @param string $criteria Criteria to search the mails.
     * @param bool $disableServerEncoding Whether to disable the server encoding.
     * @return array Mails.
     */
    public function searchMailbox(
        string $criteria = 'ALL',
        bool $disableServerEncoding = false
    ): array;

    /**
     * Gets a mail from the email mailbox.
     *
     * @param int $mailId ID of the mail to get.
     * @param bool $markAsSeen Whether to mark the mail as seen.
     * @return Message Mail.
     */
    public function getMail(int $mailId, bool $markAsSeen = true): Message;

    /**
     * Marks the mails as read.
     *
     * @param array $mailIds IDs of the mails to mark as read.
     */
    public function markMailsAsRead(array $mailIds): void;
}
