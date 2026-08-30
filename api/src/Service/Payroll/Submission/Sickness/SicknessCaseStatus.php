<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Stav případu dávky nemocenského pojištění v evidenci aplikace.
 *
 * Stav NENÍ stav podání. Podání může být `ready` a povinnost podle
 * § 97 odst. 1 a 2 zák. č. 187/2006 Sb. přesto nesplněná — splní ji až
 * PŘEDÁNÍ územní správě sociálního zabezpečení, ne to, že jsme vyrobili XML.
 * Proto `prepared` a `submitted` nejsou totéž a `accepted` se nesmí nastavit
 * jinak než dnem doručení z protokolu.
 */
enum SicknessCaseStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Je povinnost doložitelně splněná? */
    public function isFulfilled(): bool
    {
        return $this === self::Accepted;
    }

    /** Hlídá se u tohoto stavu ještě lhůta? */
    public function isOpen(): bool
    {
        return $this !== self::Accepted && $this !== self::Cancelled;
    }
}
