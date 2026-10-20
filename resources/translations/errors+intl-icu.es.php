<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    // The message of the error that made sending or receiving fail: it is shown
    // as it is.
    '{message}' =>
        '{message}',
    'DSN is not defined for the Mailer.' =>
        'El DSN no está definido para el Mailer.',
    'DSN is not defined for the Mailbox.' =>
        'El DSN no está definido para el Mailbox.',
    'An error occurred while receiving the emails: {error}' =>
        'Ocurrió un error al recibir los correos: {error}',
    'The mailbox folder {folder} does not exist.' =>
        'La carpeta {folder} del buzón no existe.',
    'The date {date} of the search criteria is not valid.' =>
        'La fecha {date} del criterio de búsqueda no es válida.',
    'The search criteria could not be processed: {error}' =>
        'No se pudo procesar el criterio de búsqueda: {error}',
    'The IMAP DSN {dsn} is not valid; the format is {format}.' =>
        'El DSN IMAP {dsn} no es válido; el formato es {format}.',
];
