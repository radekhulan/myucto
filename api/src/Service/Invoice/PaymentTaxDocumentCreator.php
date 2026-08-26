<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Oss\OssItemCarryOver;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Daňový doklad k přijaté platbě (§ 28 odst. 2 písm. d ZDPH) k platbě zálohové faktury.
 *
 * Plátce DPH musí ke každé úplatě přijaté před uskutečněním plnění vystavit daňový
 * doklad s DUZP = den přijetí úplaty (§ 21 odst. 1). Tady vzniká jako DRAFT
 * `invoice_type = 'tax_document'`:
 *
 *   - parent_invoice_id = proforma, tax_date (DUZP) = paid_on platby
 *   - položky: jedna per sazba DPH proformy; částka platby (brutto) se rozdělí mezi
 *     sazby poměrně podle brutto vah položek proformy (largest-remainder na nejsilnější
 *     sazbě). DPH se počítá SHORA koeficientem (§ 37) — prices_include_vat = 1.
 *   - advance_paid_amount = brutto platby → amount_to_pay = 0 → při vystavení se
 *     doklad auto-označí jako zaplacený (paid_at = tax_date), viz InvoiceAmountPolicy.
 *   - čísluje se v řadě faktur (VarsymbolGenerator alias tax_document → invoice).
 *
 * Nevztahuje se na: neplátce DPH (doklad nedává smysl) a reverse-charge plnění
 * (u RC vzniká povinnost přiznat daň až k DUZP plnění, záloha se nedaní — § 24/§ 92a).
 *
 * Idempotence: pokud k platbě už existuje nestornovaný doklad, vrátí jeho id.
 *
 * ── OSS se PŘENÁŠÍ z proformy ───────────────────────────────────────────────────────
 * Úplata na OSS plnění zakládá povinnost přiznat daň ve STÁTĚ SPOTŘEBY, ne v tuzemsku.
 * Do sjednocení se doklad zakládal bez OSS sloupců, takže záloha na polské plnění
 * skončila na ř. 1 českého přiznání. Místo plnění se přijetím platby změnit nemůže —
 * přebírá se proto z položky proformy ({@see OssItemCarryOver}), která už derivací
 * i případnou ruční opravou prošla. Doklad vzniká z bankovního párování, tedy BEZ
 * DOZORU: druhá derivace by tu uměla ruční opravu tiše zahodit a při nedostupném
 * číselníku razítkovat příznak „k ručnímu posouzení" i na běžné české zálohy.
 */
final class PaymentTaxDocumentCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly VatStatusService $vatStatus,
        private readonly OssItemCarryOver $ossCarry,
    ) {}

    /**
     * Poměrné rozdělení brutto platby mezi sazby DPH dle brutto vah (largest remainder).
     *
     * `carry` je NEPRŮHLEDNÝ průvodní údaj kbelíku, který alokátor jen propouští na
     * výstup — nese zdrojovou položku proformy, ze které se pak přenese OSS profil.
     * Nést ho musí právě tudy: alokátor kbelíky s nulovou vahou vyhazuje a přeindexuje,
     * takže párování výstupu se vstupem podle pozice by se tiše rozešlo.
     *
     * @param list<array{rate: float, vat_rate_id: int, gross: float, carry?: array<string,mixed>}> $buckets
     *        váhy (gross > 0 celkem)
     * @return list<array{rate: float, vat_rate_id: int, amount: float, carry: array<string,mixed>}>
     *         součet amount = $payment
     */
    public static function allocateAcrossRates(array $buckets, float $payment): array
    {
        $buckets = array_values(array_filter($buckets, static fn (array $b) => abs((float) $b['gross']) > 0.0));
        if ($buckets === []) {
            throw new \RuntimeException('Zálohová faktura nemá položky s nenulovou částkou.');
        }
        $total = 0.0;
        foreach ($buckets as $b) {
            $total += (float) $b['gross'];
        }
        if ($total <= 0.0) {
            throw new \RuntimeException('Zálohová faktura má nekladný součet — platbu nelze rozdělit.');
        }

        $out = [];
        $allocated = 0.0;
        $maxIdx = 0;
        $maxGross = -1.0;
        foreach ($buckets as $i => $b) {
            $share = round($payment * (float) $b['gross'] / $total, 2);
            $out[] = [
                'rate' => (float) $b['rate'],
                'vat_rate_id' => (int) $b['vat_rate_id'],
                'amount' => $share,
                'carry' => (array) ($b['carry'] ?? []),
            ];
            $allocated += $share;
            if ((float) $b['gross'] > $maxGross) {
                $maxGross = (float) $b['gross'];
                $maxIdx   = $i;
            }
        }
        // Zaokrouhlovací reziduum na nejsilnější sazbu, aby součet sedl přesně na platbu.
        $residual = round($payment - $allocated, 2);
        if ($residual !== 0.0) {
            $out[$maxIdx]['amount'] = round($out[$maxIdx]['amount'] + $residual, 2);
        }
        return $out;
    }

    /**
     * Vytvoří (nebo vrátí existující) draft daňového dokladu k platbě.
     *
     * @param int $paymentId  invoice_payments.id — platba zálohové faktury
     * @param int $userId     created_by; 0 = systémová akce (bankovní párování)
     * @return int id daňového dokladu
     * @throws \RuntimeException při porušení podmínek (zpráva pro UI)
     */
    public function createForPayment(int $paymentId, int $userId = 0): int
    {
        $pdo = $this->db->pdo();

        $pStmt = $pdo->prepare('SELECT * FROM invoice_payments WHERE id = ?');
        $pStmt->execute([$paymentId]);
        $payment = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) {
            throw new \RuntimeException('Platba nenalezena.');
        }

        // Idempotence — nestornovaný doklad k platbě už existuje.
        if (!empty($payment['tax_document_invoice_id'])) {
            $td = $pdo->prepare('SELECT id, status, parent_invoice_id FROM invoices WHERE id = ?');
            $td->execute([(int) $payment['tax_document_invoice_id']]);
            $existing = $td->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false && $existing['status'] !== 'cancelled') {
                // Self-heal: dokladu rozpojenému dřívějším „Zrušit propojení" (před guardem
                // v unlinkAdvance) obnov strukturální vazbu na proformu — bez ní by finál
                // nenašel § 37a odpočty.
                if ($existing['parent_invoice_id'] === null) {
                    $pdo->prepare(
                        "UPDATE invoices SET parent_invoice_id = ? WHERE id = ? AND invoice_type = 'tax_document'"
                    )->execute([(int) $payment['invoice_id'], (int) $existing['id']]);
                }
                return (int) $existing['id'];
            }
        }

        $proforma = $this->repo->find((int) $payment['invoice_id']);
        if ($proforma === null) {
            throw new \RuntimeException('Zálohová faktura nenalezena.');
        }
        if (($proforma['invoice_type'] ?? '') !== 'proforma') {
            throw new \RuntimeException('Daňový doklad k přijaté platbě lze vystavit jen k zálohové faktuře.');
        }
        // Guard proti dvojímu zdanění: jakmile k proformě existuje VYSTAVENÝ
        // (nestornovaný) finální doklad, jeho odpočtové řádky (§ 37a) jsou zafixované
        // a dodatečný daňový doklad k platbě by stejnou úplatu zdanil podruhé.
        //
        // Koncept se sem ZÁMĚRNĚ nepočítá (issue #39): automatika zakládá finál jako
        // draft, takže dřív si sama znepřístupnila tlačítko „Vystavit daňový doklad"
        // a uživatel musel nejdřív uhodnout, že má koncept smazat. Nevystavený draft
        // nic nezdanil — žádné dvojí zdanění z něj vzniknout nemůže.
        $finalExists = $pdo->prepare(
            "SELECT 1 FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'invoice'
                AND status NOT IN ('draft', 'cancelled')
              LIMIT 1"
        );
        $finalExists->execute([(int) $proforma['id']]);
        if ($finalExists->fetchColumn() !== false) {
            throw new \RuntimeException(
                'K zálohové faktuře už existuje finální doklad — daňový doklad k platbě by úplatu zdanil podruhé.'
            );
        }
        if (!empty($proforma['reverse_charge'])) {
            throw new \RuntimeException(
                'U přenesené daňové povinnosti se záloha nedaní — daňový doklad k platbě se nevystavuje.'
            );
        }

        // Povinnost dle § 28 vzniká PŘIJETÍM úplaty — rozhoduje plátcovství k datu
        // platby (paid_on), ne dnešní cache: platba přijatá v období neplátcovství
        // DDKP nezakládá, i když firma mezitím plátcem (znovu) je.
        if (!$this->vatStatus->isVatPayerAt((int) $proforma['supplier_id'], (string) $payment['paid_on'])) {
            throw new \RuntimeException(
                'Daňový doklad k přijaté platbě vystavuje jen plátce DPH — firma jím k datu přijetí platby nebyla.'
            );
        }

        // Brutto váhy per sazba ze stored řádkových totálů proformy (vč. slevových řádků).
        //
        // Kbelík je per sazba A ZÁROVEŇ per MÍSTO PLNĚNÍ. Samotná sazba na rozlišení
        // nestačí: u zákazníkovy konfigurace je polská 23% sazba ve `vat_rates` vedená
        // se zemí CZ, takže OSS řádek do PL a tuzemský řádek můžou mít totéž procento
        // i totéž `vat_rate_id`. Sloučené do jednoho kbelíku by dostaly JEDEN OSS profil
        // a polovina úplaty by se přiznala ve špatné zemi.
        $buckets = [];
        foreach ($proforma['items'] as $item) {
            $key = number_format((float) $item['vat_rate_snapshot'], 2, '.', '')
                . '#' . $this->ossCarry->fingerprint($item);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'rate'        => (float) $item['vat_rate_snapshot'],
                    'vat_rate_id' => (int) $item['vat_rate_id'],
                    'gross'       => 0.0,
                    // Zdrojová položka putuje alokátorem až k zápisu — viz jeho docblock.
                    'carry'       => $item,
                ];
            }
            $buckets[$key]['gross'] += (float) $item['total_with_vat'];
        }
        $allocation = self::allocateAcrossRates(array_values($buckets), (float) $payment['amount']);

        $isEn = ($proforma['language'] ?? 'cs') === 'en';
        $noteAbove = $isEn
            ? "Tax document for payment received on advance invoice {$proforma['varsymbol']}"
            : "Daňový doklad k přijaté platbě — zálohová faktura {$proforma['varsymbol']}";
        $paidOnCz = date('j. n. Y', strtotime((string) $payment['paid_on']));
        $lineDesc = $isEn
            ? "Payment received {$payment['paid_on']} (advance invoice {$proforma['varsymbol']})"
            : "Přijatá platba {$paidOnCz} (zálohová faktura {$proforma['varsymbol']})";

        $currency = strtoupper((string) ($proforma['currency'] ?? 'CZK'));
        $resolvedRate = $this->rateApplier->resolveFor(
            (int) $proforma['supplier_id'],
            $currency,
            (string) $payment['paid_on'],
        );

        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payment_tax_document_create';
            $pdo->exec("SAVEPOINT {$savepoint}");
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
                    reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, advance_paid_amount, discount_percent, payment_method,
                    revenue_category_id, status, created_by)
                 VALUES ("tax_document", ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 0, 1, ?, ?, NULL, ?, 0, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                (int) $proforma['id'],
                $proforma['client_id'],
                $proforma['project_id'],
                (int) $proforma['supplier_id'],
                $proforma['branding_profile_id'] ?? null,
                (string) $payment['paid_on'],   // tax_date = DUZP = den přijetí úplaty
                (string) $payment['paid_on'],   // due_date — uhrazeno, jen formální údaj
                (int) $proforma['currency_id'],
                // Kurz dědíme z proformy — cizoměnový doklad nesmí do VAT ledgeru
                // spadnout s COALESCE(exchange_rate, 1) = 1. (Lazy ExchangeRateApplier
                // běží až při zobrazení, bankovní párování ho nevolá.)
                $proforma['exchange_rate'] ?? null,
                $proforma['exchange_rate_date'] ?? null,
                $proforma['language'],
                $noteAbove,
                // Přijatá platba kryje doklad celý → amount_to_pay = 0 (auto-paid při vystavení).
                (float) $payment['amount'],
                (string) ($proforma['payment_method'] ?? 'bank_transfer'),
                $proforma['revenue_category_id'] ?? null,
                $userId ?: null,
            ]);
            $taxDocId = (int) $pdo->lastInsertId();

            // Položky per sazba — režim SHORA (prices_include_vat=1): unit_price = brutto podíl.
            // OSS sloupce se skládají místo ručně psaných variant SQL (shodně
            // s `InvoiceRepository::replaceItems()`); na instanci bez migrace 0137 je
            // seznam prázdný a dotaz vypadá přesně jako dřív.
            $ossColumns = $this->ossCarry->columns();
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, item_kind'
                . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
                . ') VALUES (?, ?, 1, "", ?, ?, ?, 0, 0, 0, ?, "standard"'
                . $this->ossCarry->placeholders()
                . ')'
            );
            $multiRate = count($allocation) > 1;
            foreach ($allocation as $i => $line) {
                // Rozlišovací přívlastek nese i STÁT SPOTŘEBY: po rozdělení kbelíků podle
                // místa plnění můžou dva řádky sdílet procento, a dva identické popisy na
                // jednom dokladu nejde přiřadit k plnění ani při kontrole podání.
                $country = OssItemCarryOver::consumerCountryOf($line['carry']);
                $desc = $multiRate
                    ? $lineDesc
                        . ($isEn ? " — VAT rate {$line['rate']} %" : " — sazba DPH {$line['rate']} %")
                        . ($country !== null ? " ({$country})" : '')
                    : $lineDesc;
                $itemStmt->execute([
                    $taxDocId,
                    $desc,
                    $line['amount'],
                    $line['vat_rate_id'],
                    $line['rate'],
                    $i,
                    // Místo plnění z proformy — přijetím úplaty se nemění (viz docblock třídy).
                    ...$this->ossCarry->values($line['carry']),
                ]);
            }

            $claim = $pdo->prepare(
                'UPDATE invoice_payments
                    SET tax_document_invoice_id = ?
                  WHERE id = ?
                    AND (tax_document_invoice_id IS NULL OR tax_document_invoice_id = ?)'
            );
            $claim->execute([
                $taxDocId,
                $paymentId,
                !empty($payment['tax_document_invoice_id']) ? (int) $payment['tax_document_invoice_id'] : null,
            ]);
            if ($claim->rowCount() !== 1) {
                throw new PaymentTaxDocumentRaceException($paymentId);
            }

            $this->calc->recompute($taxDocId);
            if ($resolvedRate !== null) {
                $this->repo->setExchangeRate(
                    $taxDocId,
                    $resolvedRate['rate'],
                    $resolvedRate['rate_date'],
                );
            } elseif ($currency !== 'CZK' && isset($proforma['exchange_rate'])) {
                $this->repo->setExchangeRate(
                    $taxDocId,
                    (float) $proforma['exchange_rate'],
                    isset($proforma['exchange_rate_date']) ? (string) $proforma['exchange_rate_date'] : null,
                );
            } else {
                $this->repo->setExchangeRate($taxDocId, null, null);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
        } catch (PaymentTaxDocumentRaceException $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($savepoint !== null && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            $winner = $pdo->prepare(
                'SELECT tax_document_invoice_id FROM invoice_payments WHERE id = ? FOR UPDATE'
            );
            $winner->execute([$e->paymentId]);
            $winnerId = $winner->fetchColumn();
            if ($winnerId !== false && $winnerId !== null) {
                return (int) $winnerId;
            }
            throw new \RuntimeException('Daňový doklad k platbě se nepodařilo atomicky vytvořit.');
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($savepoint !== null && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $e;
        }

        return $taxDocId;
    }
}

final class PaymentTaxDocumentRaceException extends \RuntimeException
{
    public function __construct(public readonly int $paymentId)
    {
        parent::__construct('Souběžné vytvoření daňového dokladu k platbě.');
    }
}
