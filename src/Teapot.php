<?php

declare(strict_types=1);

/**
 * Derafu: Mail - PHP Mailing Library.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.org>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail;

class Teapot
{
    public function __toString(): string
    {
        return "I'm a teapot";
    }
}
