<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Hranice mezi OPRAVOU a PŘEPISEM HISTORIE u sjednané zásady příplatků.
 *
 * Opravit jde JEDINÁ verze — ta otevřená a zároveň poslední, tedy
 * `valid_to IS NULL` a bez novější verze nad sebou. Důvod není formální:
 *
 *   * Verze s vyplněným `valid_to` už NĚJAKÝ měsíc odměnila. Mzdový list
 *     spočítaný podle ní na ni dál ukazuje a tvrdí, že vychází z jejích sazeb.
 *     Změnit je zpětně by znamenalo, že doklad tvrdí něco jiného, než z čeho
 *     opravdu vznikl — a to se pozná až při kontrole.
 *   * Verze, nad kterou už stojí novější, je uzavřená z definice: její konec
 *     platnosti dopočetl `savePolicy()` z účinnosti nástupkyně.
 *
 * Překlep v SAZBĚ otevřené verze se proto opravuje na místě (`update`), ale
 * překlep v datu účinnosti nikoli: `valid_from` je hranice proti PŘEDCHOZÍ,
 * uzavřené verzi, jejíž `valid_to` je z něj odvozené. Posunout ji znamená
 * sáhnout na historii a vyrobit v ní díru nebo překryv. Jiná účinnost = nová
 * verze, ne oprava.
 */
final class PayrollSurchargePolicyHistoryLockedException extends \DomainException
{
    public function __construct(
        string $message = 'Uzavřenou ani překrytou verzi zásady příplatků nelze měnit — mzdy spočítané podle ní na ni dál ukazují. Založte místo toho novou verzi.',
    ) {
        parent::__construct($message);
    }
}
