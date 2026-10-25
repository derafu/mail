<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy;

use Derafu\Backbone\Attribute\Strategy;
use Derafu\Config\Contract\OptionsInterface;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\Abstract\AbstractMailboxStrategy;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\Contract\ReceiverStrategyInterface;

/**
 * Class for receiving emails using IMAP.
 */
#[Strategy(name: 'imap', worker: 'receiver', component: 'exchange', package: 'mail')]
class ImapStrategy extends AbstractMailboxStrategy implements ReceiverStrategyInterface
{
    /**
     * Schema of the options.
     *
     * @var array<string,array<string,mixed>>
     */
    protected array $optionsSchema = [
        'strategy' => [
            'types' => 'string',
            'default' => 'imap',
        ],
        'transport' => [
            'types' => 'array',
            'schema' => [
                'host' => [
                    'types' => 'string',
                    'default' => 'imap.gmail.com',
                ],
                'port' => [
                    'types' => 'int',
                    'default' => 993,
                ],
                'encryption' => [
                    'types' => ['string', 'null'],
                    'default' => 'ssl',
                ],
                'username' => [
                    'types' => 'string',
                    'required' => true,
                ],
                'password' => [
                    'types' => 'string',
                    'required' => true,
                ],
                'mailbox' => [
                    'types' => 'string',
                    'default' => 'INBOX',
                ],
                'attachments_dir' => [
                    'types' => ['string', 'null'],
                    'default' => null,
                ],
                'verify_peer' => [
                    'types' => 'bool',
                    'default' => true,
                ],
                'dsn' => [
                    'types' => 'string',
                ],
                'endpoint' => [
                    'types' => 'string',
                ],
                'search' => [
                    'types' => 'array',
                    'schema' => [
                        'criteria' => [
                            'types' => 'string',
                            'default' => 'UNSEEN',
                        ],
                        'markAsSeen' => [
                            'types' => 'bool',
                            'default' => false,
                        ],
                        'attachmentFilters' => [
                            'types' => 'array',
                            'schema' => [
                                'subtype' => [
                                    'types' => 'string[]',
                                    'default' => [],
                                ],
                                'extension' => [
                                    'types' => 'string[]',
                                    'default' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function getOptionsSchema(): array
    {
        return $this->optionsSchema;
    }

    /**
     * {@inheritDoc}
     */
    protected function resolveDsn(OptionsInterface $options): string
    {
        $transport = $options->requireContainer('transport');

        $dsn = $transport->getReader()->getString('dsn');
        if (!empty($dsn)) {
            return $dsn;
        }

        $dsn = sprintf(
            '{%s:%d/imap%s%s}%s',
            $transport->getReader()->requireString('host'),
            $transport->getReader()->requireInt('port'),
            $transport->getReader()->getString('encryption') === 'ssl' ? '/ssl' : '',
            $transport->getReader()->requireBool('verify_peer') ? '' : '/novalidate-cert',
            $transport->getReader()->requireString('mailbox')
        );

        $options->set('transport.dsn', $dsn);

        return $dsn;
    }
}
