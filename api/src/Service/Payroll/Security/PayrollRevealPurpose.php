<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

/**
 * Proč se citlivý mzdový údaj dešifruje (W1/P-03).
 *
 * `PayrollSensitiveData::reveal()` sám o sobě nekontroluje práva ani nezapisuje
 * do auditu — je to kryptografická primitiva, ne autorizační brána. Dokud byl
 * důvod volání nezapsaný, nešlo z kódu poznat, které z desítek volání odhaluje
 * rodné číslo do zákonného dokumentu a které do náhledu v UI. Účel je proto
 * POVINNÝ parametr: nové volání nemůže vzniknout bez toho, aby autor prohlásil,
 * do čeho odhalený údaj poteče, a stopa je dohledatelná grepem přes enum.
 *
 * Rozdělení podle toho, čím je odhalení ospravedlněné:
 *
 *  - ZÁKONNÁ NÁLEŽITOST DOKUMENTU NEBO PODÁNÍ — rodné číslo v dokumentu být
 *    MUSÍ (mzdový list dle § 38j ZDP, potvrzení o zdanitelných příjmech, roční
 *    zúčtování, registrační podání na ČSSZ). Maskovat tady nelze, dokument by
 *    přestal být použitelný;
 *  - PLATEBNÍ STYK — dešifruje se číslo účtu instituce nebo příjemce, aby šel
 *    sestavit platební příkaz. Rodné číslo tudy neteče;
 *  - VÝSLOVNÉ ODHALENÍ NA ŽÁDOST UŽIVATELE — jediná cesta, která si vynucuje
 *    právo `payroll.person.read_sensitive`, textový důvod a zápis do auditu;
 *    viz {@see PayrollPersonSensitiveRevealService}.
 */
enum PayrollRevealPurpose: string
{
    /** Roční mzdový list § 38j ZDP — rodné číslo je náležitostí dokumentu. */
    case DOCUMENT_PAYROLL_SHEET = 'document:payroll_sheet';

    /** Potvrzení o zdanitelných příjmech ze závislé činnosti (a vyživované děti). */
    case DOCUMENT_ANNUAL_TAX_CERTIFICATE = 'document:annual_tax_certificate';

    /** Výpočet ročního zúčtování záloh a daňového zvýhodnění. */
    case DOCUMENT_ANNUAL_SETTLEMENT = 'document:annual_settlement';

    /** Registrační a evidenční podání na ČSSZ (PREZEC/REGZEC, evidence ECP/VCP, A1). */
    case SUBMISSION_CSSZ_REGISTRATION = 'submission:cssz_registration';

    /** Bankovní účet instituce (zdravotní pojišťovna, FÚ, OSSZ) pro platební příkaz. */
    case PAYMENT_INSTITUTION_ACCOUNT = 'payment:institution_account';

    /** Bankovní účet příjemce zákonného odvodu nebo srážky při materializaci závazku. */
    case PAYMENT_LIABILITY_ACCOUNT = 'payment:liability_account';

    /** Bankovní účty v hromadném platebním příkazu (výplaty a odvody). */
    case PAYMENT_BATCH = 'payment:batch';

    /** Odhalení na výslovnou žádost uživatele — s právem, důvodem a auditní stopou. */
    case PERSON_SENSITIVE_REVEAL = 'security:person_sensitive_reveal';

    /**
     * Je odhalení zákonnou náležitostí dokumentu nebo podání?
     *
     * Rozlišení je tu proto, aby budoucí auditní zápis uměl oddělit systémový
     * průchod (bez interaktivního důvodu od uživatele) od odhalení na žádost.
     */
    public function isStatutoryOutput(): bool
    {
        return match ($this) {
            self::DOCUMENT_PAYROLL_SHEET,
            self::DOCUMENT_ANNUAL_TAX_CERTIFICATE,
            self::DOCUMENT_ANNUAL_SETTLEMENT,
            self::SUBMISSION_CSSZ_REGISTRATION => true,
            default => false,
        };
    }
}
