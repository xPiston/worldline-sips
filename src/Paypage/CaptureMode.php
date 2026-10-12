<?php

declare(strict_types=1);

namespace Piston\Sips\Paypage;

/**
 * When the money actually leaves the customer's account.
 *
 * The distinction is not cosmetic - it decides what a shop is allowed to do
 * afterwards, and in several countries when it is allowed to charge at all.
 *
 * - `AuthorAndCapture` authorises now and captures `captureDay` days later,
 *   automatically. The usual choice for goods that ship.
 * - `Validation` authorises now and captures only when the shop says so,
 *   through the Office `validate` operation. The choice when stock might not
 *   be there.
 * - `Immediate` does both at once. Right for anything delivered instantly.
 */
enum CaptureMode: string
{
    case AuthorAndCapture = 'AUTHOR_CAPTURE';
    case Validation = 'VALIDATION';
    case Immediate = 'IMMEDIATE';
}
