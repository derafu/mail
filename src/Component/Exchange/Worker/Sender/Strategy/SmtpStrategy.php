<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail\Component\Exchange\Worker\Sender\Strategy;

use Derafu\Backbone\Attribute\Strategy;
use Derafu\Config\Contract\OptionsInterface;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\Abstract\AbstractMailerStrategy;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\Contract\SenderStrategyInterface;

/**
 * Strategy for sending emails using SMTP.
 */
#[Strategy(name: 'smtp', worker: 'sender', component: 'exchange', package: 'mail')]
class SmtpStrategy extends AbstractMailerStrategy implements SenderStrategyInterface
{
    /**
     * Schema of the options.
     *
     * @var array<string,array<string,mixed>>
     */
    protected array $optionsSchema = [
        'strategy' => [
            'types' => 'string',
            'default' => 'smtp',
        ],
        'transport' => [
            'types' => 'array',
            'schema' => [
                'host' => [
                    'types' => 'string',
                    'default' => 'smtp.gmail.com',
                ],
                'port' => [
                    'types' => 'int',
                    'default' => 465,
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
            'smtp://%s:%s@%s:%d?encryption=%s&verify_peer=%d',
            $transport->getReader()->requireString('username'),
            $transport->getReader()->requireString('password'),
            $transport->getReader()->requireString('host'),
            $transport->getReader()->requireInt('port'),
            $transport->getReader()->getString('encryption') ?? '',
            $transport->getReader()->requireBool('verify_peer') ? 1 : 0,
        );

        $options->set('transport.dsn', $dsn);

        return $dsn;
    }

    /**
     * {@inheritDoc}
     */
    protected function resolveEndpoint(OptionsInterface $options): string
    {
        $transport = $options->requireContainer('transport');

        $endpoint = $transport->getReader()->getString('endpoint');
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $endpoint = '';

        $encryption = $transport->getReader()->getString('encryption');
        if ($encryption !== null) {
            $endpoint .= $encryption . '://';
        }

        $endpoint .= $transport->getReader()->requireString('host');

        $port = $transport->getReader()->getInt('port');
        if ($port !== null) {
            $endpoint .= ':' . $port;
        }

        if ($transport->getReader()->getBool('verify_peer') === false) {
            $endpoint .= '/novalidate-cert';
        }

        $options->set('transport.endpoint', $endpoint);

        return $endpoint;
    }
}
