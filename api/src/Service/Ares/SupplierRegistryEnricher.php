<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * „Na pozadí" doplní u nově vytvořeného dodavatele pole, která jdou spolehlivě
 * odvodit z veřejných registrů (ARES + registr plátců DPH), bez nutnosti je
 * mít ve formuláři:
 *
 *  - z ARES (podle IČ): číslo popisné/orientační, jednoznačná CZ-NACE,
 *    spisová značka (OR), právní forma, typ poplatníka,
 *  - z registru plátců DPH (podle DIČ): kód finančního úřadu (`cisloFu`
 *    = EPO c_ufo, systém 451–465; NE kód územního pracoviště z ARES `financniUrad`!).
 *
 * Nepřepisuje už vyplněné hodnoty (doplní jen NULL/prázdné sloupce) a je
 * best-effort — výpadek registru NESMÍ zablokovat vytvoření dodavatele.
 *
 * Voláno až PO commitu (dělá síťové volání → nepatří do DB transakce).
 */
final class SupplierRegistryEnricher
{
    /** Sloupce, které smíme doplnit (whitelist — názvy jdou přímo do SQL). */
    private const COLUMNS = [
        'street_number_pop', 'street_number_orient', 'cz_nace_code',
        'commercial_register', 'legal_form', 'taxpayer_type', 'financial_office_code',
    ];

    public function __construct(
        private readonly AresClient $ares,
        private readonly CrpDphClient $crpdph,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function enrich(int $supplierId, ?string $ic, ?string $dic): void
    {
        if ($supplierId <= 0) {
            return;
        }
        try {
            $fields = [];

            $icDigits = preg_replace('/\D/', '', (string) $ic) ?? '';
            if (strlen($icDigits) === 8) {
                $r = $this->ares->lookup($icDigits);
                $d = ($r['found'] ?? false) ? (array) ($r['data'] ?? []) : [];
                $this->put($fields, 'street_number_pop', $d['street_number_pop'] ?? '');
                $this->put($fields, 'street_number_orient', $d['street_number_orient'] ?? '');
                $this->put($fields, 'cz_nace_code', $d['cz_nace_code'] ?? '');
                $this->put($fields, 'commercial_register', $d['commercial_register'] ?? '');
                $legalForm = trim((string) ($d['legal_form'] ?? ''));
                if (preg_match('/^\d{3}$/D', $legalForm) === 1) {
                    $this->put($fields, 'legal_form', $legalForm);
                }
                if (in_array($d['taxpayer_type'] ?? '', ['fo', 'po'], true)) {
                    $this->put($fields, 'taxpayer_type', (string) $d['taxpayer_type']);
                }
            }

            $dicDigits = preg_replace('/\D/', '', (string) $dic) ?? '';
            if (preg_match('/^\d{8,10}$/', $dicDigits)) {
                $c = $this->crpdph->lookup($dicDigits);
                $this->put($fields, 'financial_office_code', (string) ($c['fu_code'] ?? ''));
            }

            if ($fields === []) {
                return;
            }

            // Doplnit jen tam, kde je sloupec NULL/prázdný — neničit ruční vstup.
            $sets = [];
            $params = [];
            foreach ($fields as $col => $val) {
                if (!in_array($col, self::COLUMNS, true)) {
                    continue; // paranoidní guard proti injection přes klíč
                }
                $sets[] = "`{$col}` = IF(`{$col}` IS NULL OR `{$col}` = '', ?, `{$col}`)";
                $params[] = $val;
            }
            if ($sets === []) {
                return;
            }
            $params[] = $supplierId;
            $this->db->pdo()
                ->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($params);
        } catch (\Throwable $e) {
            $this->logger->warning('Obohacení dodavatele z registrů selhalo: ' . $e->getMessage(), [
                'supplier' => $supplierId,
            ]);
        }
    }

    /** @param array<string,string> $fields */
    private function put(array &$fields, string $col, mixed $val): void
    {
        $val = trim((string) $val);
        if ($val !== '') {
            $fields[$col] = $val;
        }
    }
}
