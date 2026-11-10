<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Země odběratele převzatého dokladu tak, jak ji má karta klienta v MyÚčtu. Převod partnera
 * zemi už rozhodl (předpona DIČ, stát z adresy, výchozí země firmy), takže zařazení dokladu
 * se ptá karty a neodvozuje zemi znovu ze zdroje.
 */
final class MigratedClientCountry
{
    /** @var array<int,?string> */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    /** ISO 3166-1 alpha-2 země klienta, null = klient nebo země neexistuje. */
    public function iso2(int $supplierId, int $clientId): ?string
    {
        if (!array_key_exists($clientId, $this->cache)) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT co.iso2 FROM clients c JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ? AND c.supplier_id = ?'
            );
            $stmt->execute([$clientId, $supplierId]);
            $iso = $stmt->fetchColumn();
            $this->cache[$clientId] = $iso === false ? null : strtoupper((string) $iso);
        }
        return $this->cache[$clientId];
    }

    /**
     * Kód zařazení vydaného dokladu podle země odběratele ({@see VatReturnLineClassifier::saleCodeForCustomer()}).
     * Kartu klienta čte jen u kódu, kterého se pravidlo týká.
     */
    public function saleCode(?string $code, int $supplierId, int $clientId): ?string
    {
        if ($code !== VatReturnLineClassifier::OTHER_FOREIGN_SALE_CODE) {
            return $code;
        }
        return VatReturnLineClassifier::saleCodeForCustomer($code, $this->iso2($supplierId, $clientId));
    }
}
