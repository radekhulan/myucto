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
 * Překlep v SAZBĚ otevřené verze se proto opravuje na místě (`update`).
 * Datum účinnosti je hranice proti PŘEDCHOZÍ verzi, jejíž `valid_to` je z něj
 * odvozené, a tak ho oprava obsahu nemění. Posunout ho jde jen vlastní
 * operací ({@see PayrollSurchargeRepository::movePolicyStart()}), která posune
 * i navazující hranici a odmítne zásah do měsíce, který už zmrazila revize
 * běhu. Použitá verze se mění novou verzí.
 */
final class PayrollSurchargePolicyHistoryLockedException extends \DomainException
{
    public function __construct(
        string $message = 'Uzavřenou ani překrytou verzi zásady příplatků nelze měnit — mzdy spočítané podle ní na ni dál ukazují. Založte místo toho novou verzi.',
    ) {
        parent::__construct($message);
    }
}
