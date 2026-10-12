<?php

declare(strict_types=1);

namespace Piston\Sips\Paypage;

/**
 * `orderChannel`: where the order came from.
 *
 * Mandatory, and it is read by the acquirer's risk rules as much as by Sips -
 * a payment declared `INTERNET` goes through 3-D Secure, a `MOTO` one taken
 * over the telephone does not and leaves the shop carrying the fraud risk.
 * Declaring the wrong one is not a technical error; it is a liability
 * decision.
 */
enum OrderChannel: string
{
    case Internet = 'INTERNET';
    case MailOrTelephone = 'MOTO';
    case InApp = 'INAPP';
}
