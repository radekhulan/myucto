<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Forma úhrady MyÚčta ↔ `typ:paymentType` Pohody — jediné mapování pro export
 * (`PohodaXmlExporter`) i převod z Pohody (`Migration\Pohoda\InvoiceImporter`).
 *
 * Výčet Pohody (type.xsd, `paymentType`): draft, cash, postal, delivery, creditcard,
 * advance, encashment, cheque, compensation. Pro `other` Pohoda žádný token nemá,
 * proto se element u takového dokladu vynechá a Pohoda dosadí svou výchozí formu.
 * Tipovat `draft` by bylo horší: inkaso ani zápočet se nesmí v Pohodě objevit jako
 * příkaz k úhradě.
 */
final class PohodaPaymentType
{
    /** @var array<string,string> */
    private const TO_POHODA = [
        'bank_transfer'    => 'draft',
        'cash'             => 'cash',
        'card'             => 'creditcard',
        'cash_on_delivery' => 'delivery',
        'direct_debit'     => 'encashment',
        'offset'           => 'compensation',
    ];

    /**
     * Tokeny Pohody, které v MyÚčtu nemají vlastní formu (postal, advance, cheque),
     * padnou na `other`. Prázdná hodnota je ve starších exportech Pohody převod.
     *
     * @var array<string,string>
     */
    private const FROM_POHODA = [
        ''             => 'bank_transfer',
        'draft'        => 'bank_transfer',
        'cash'         => 'cash',
        'creditcard'   => 'card',
        'delivery'     => 'cash_on_delivery',
        'encashment'   => 'direct_debit',
        'compensation' => 'offset',
    ];

    /** Token `typ:paymentType`, nebo null, když forma v Pohodě nemá ekvivalent. */
    public static function toPohoda(mixed $paymentMethod): ?string
    {
        return self::TO_POHODA[PaymentMethods::normalize($paymentMethod)] ?? null;
    }

    public static function fromPohoda(string $paymentType): string
    {
        return self::FROM_POHODA[trim($paymentType)] ?? 'other';
    }
}
