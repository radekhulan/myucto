<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Daňové brány vystavení vydaného dokladu (§ 29, § 30, § 108 ZDPH).
 *
 * Jediné místo pro pravidla, která rozhodují, jestli koncept smí vzniknout jako daňový
 * doklad: vystavení z editoru ({@see \MyInvoice\Action\Invoice\IssueInvoiceAction})
 * i rychlá účtenka ({@see \MyInvoice\Service\Mobile\QuickReceiptService}) se ptají
 * tady. Dřív žily jen uvnitř akce, takže každá další cesta vystavení by je musela
 * opsat a první rozdíl by se ukázal až ve výkazu.
 *
 * Každá brána vrací `null` (doklad smí projít), nebo odmítnutí s kódem, hláškou
 * a HTTP stavem. Koncept smí být neúplný; blokuje se až vystavení.
 */
final class InvoiceIssueTaxGate
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * Rozhodné datum dokladu pro plátcovství DPH: DUZP (tax_date), jinak issue_date.
     * U dobropisu se tax_date při vystavení přepisuje datem doručení (§ 42 odst. 3),
     * rozhodné datum ho proto zohledňuje už předem.
     *
     * @param array<string,mixed> $invoice
     */
    public static function vatStatusDate(array $invoice): string
    {
        return ($invoice['invoice_type'] ?? '') === 'credit_note' && !empty($invoice['corrective_delivered_on'])
            ? (string) $invoice['corrective_delivered_on']
            : (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']);
    }

    /**
     * § 29 odst. 2 ZDPH: doklad v režimu přenesené daňové povinnosti musí nést DIČ
     * odběratele, jinak plnění z kontrolního hlášení tiše vypadne.
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function reverseChargeRejection(array $invoice): ?array
    {
        if (empty($invoice['reverse_charge'])) {
            return null;
        }
        $dicStmt = $this->db->pdo()->prepare('SELECT dic FROM clients WHERE id = ?');
        $dicStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
        if (KontrolniHlaseniBuilder::cleanDic((string) ($dicStmt->fetchColumn() ?: '')) !== '') {
            return null;
        }
        return self::rejection(
            'reverse_charge_dic_missing',
            'Doklad v režimu přenesené daňové povinnosti musí nést DIČ odběratele '
                . '(§ 29 odst. 2 ZDPH). Bez něj plnění nelze uvést v kontrolním hlášení. '
                . 'Doplňte DIČ u odběratele, nebo režim přenesené povinnosti vypněte.',
        );
    }

    /**
     * Koncový zákazník bez údajů smí být odběratelem jen zjednodušeného dokladu (§ 30).
     * Běžný daňový doklad musí nést jméno a adresu odběratele (§ 29 odst. 1 písm. c).
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function walkInRejection(array $invoice): ?array
    {
        if (!empty($invoice['is_simplified'])) {
            return null;
        }
        $walkInStmt = $this->db->pdo()->prepare('SELECT is_walk_in FROM clients WHERE id = ?');
        $walkInStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
        if ((int) $walkInStmt->fetchColumn() !== 1) {
            return null;
        }
        return self::rejection(
            'walk_in_customer_requires_simplified',
            'Doklad bez odběratele lze vystavit jen jako zjednodušený daňový doklad (§ 30 ZDPH). '
                . 'Vyberte odběratele, nebo zaškrtněte zjednodušený daňový doklad.',
        );
    }

    /**
     * Drobný zákazník (#310, `clients.simplified_only`): karta jen se jménem a kontaktem,
     * bez adresy. Plátce mu smí vystavit jen zjednodušený daňový doklad (§ 30 ZDPH), protože
     * běžný daňový doklad musí nést jméno a adresu odběratele (§ 29 odst. 1 písm. c).
     *
     * - Povolený je typ `invoice` s příznakem `is_simplified`. Zálohová faktura vede
     *   k daňovému dokladu k platbě s plnými údaji odběratele, takže na takové kartě nejde.
     * - Dobropis (opravný daňový doklad) jen podle § 45 odst. 2 ZDPH: byl-li původní daňový
     *   doklad vystaven jako zjednodušený a odběratel není plátci dostatečně znám (karta bez
     *   sídla, tedy bez „označení" podle § 29 odst. 4), nemusí opravný doklad obsahovat
     *   označení a DIČ odběratele ani rozdíl základu a daně (§ 45 odst. 1 písm. c, d, h, i).
     *   Viz {@see simplifiedOnlyCreditNoteRejection()}.
     * - Nad limitem § 30 odst. 1 (stejný limit jako {@see simplifiedRejection()}) brána
     *   nabídne obě cesty: doplnit kartu, nebo prodej snížit. Částka se posuzuje stejně
     *   jako u {@see SimplifiedDocumentPolicy::rejectionReason()}.
     * - Neplátce (k rozhodnému datu dokladu) daňové doklady nevystavuje vůbec, zjednodušený
     *   doklad pro něj není. Jeho faktura je účetní doklad (§ 11 ZÚ), kterému jako označení
     *   odběratele stačí jméno, proto se brána neuplatní.
     *
     * Volají ji všechny cesty vystavení: editor a API ({@see \MyInvoice\Action\Invoice\IssueInvoiceAction}),
     * rychlá účtenka, automatické vystavení ({@see AutoIssueAndSendService}) i pravidelná
     * fakturace ({@see RecurringInvoiceGenerator}).
     *
     * @param array<string,mixed> $invoice hlavička s `client_id`, `supplier_id`, `invoice_type`,
     *                                     `is_simplified`, `total_with_vat`, `issue_date`/`tax_date`
     * @return array{code:string, message:string, status:int}|null
     */
    public function simplifiedOnlyClientRejection(array $invoice): ?array
    {
        $clientStmt = $this->db->pdo()->prepare('SELECT company_name, simplified_only FROM clients WHERE id = ?');
        $clientStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
        $client = $clientStmt->fetch(\PDO::FETCH_ASSOC);
        if ($client === false || (int) $client['simplified_only'] !== 1) {
            return null;
        }
        if (!$this->vatStatus->isVatPayerAt((int) $invoice['supplier_id'], self::vatStatusDate($invoice))) {
            return null;
        }
        $name = (string) $client['company_name'];
        if (($invoice['invoice_type'] ?? 'invoice') === 'credit_note') {
            return $this->simplifiedOnlyCreditNoteRejection($invoice, $name);
        }
        if (($invoice['invoice_type'] ?? 'invoice') !== 'invoice' || empty($invoice['is_simplified'])) {
            return self::rejection(
                'simplified_only_client_requires_simplified',
                sprintf(
                    'Odběratel „%s" je veden jako drobný zákazník bez adresy. Lze mu vystavit jen '
                        . 'zjednodušený daňový doklad (§ 30 ZDPH). Zaškrtněte zjednodušený daňový '
                        . 'doklad, nebo doplňte na kartě odběratele adresu a volbu drobný zákazník vypněte.',
                    $name,
                ),
            );
        }
        $limit = $this->simplifiedLimit($invoice);
        $total = abs((float) ($invoice['total_with_vat'] ?? 0));
        if ($total > $limit) {
            return self::rejection(
                'simplified_only_client_over_limit',
                sprintf(
                    'Odběratel „%s" je veden jako drobný zákazník bez adresy a zjednodušený daňový '
                        . 'doklad lze vystavit jen do %s Kč včetně daně (§ 30 odst. 1 ZDPH); doklad je '
                        . 'na %s Kč. Doplňte na kartě odběratele adresu a další údaje a vystavte běžnou '
                        . 'fakturu, nebo prodej snižte pod limit.',
                    $name,
                    number_format($limit, 0, ',', ' '),
                    number_format($total, 2, ',', ' '),
                ),
            );
        }
        return null;
    }

    /**
     * Vzniká dobropis k tomuto dokladu rovnou jako zjednodušený opravný daňový doklad?
     * Ano jen u zjednodušené faktury drobnému zákazníkovi (§ 45 odst. 2 ZDPH, viz
     * {@see simplifiedOnlyCreditNoteRejection()}). Volají ji cesty, které dobropis zakládají
     * (dobropis z faktury, nový koncept s vazbou na původní doklad).
     *
     * @param array<string,mixed> $original původní doklad s `invoice_type`, `is_simplified`
     *                                      a `client_simplified_only`
     */
    public static function creditNoteInheritsSimplified(array $original): bool
    {
        return ($original['invoice_type'] ?? '') === 'invoice'
            && !empty($original['is_simplified'])
            && !empty($original['client_simplified_only']);
    }

    /**
     * Dobropis drobnému zákazníkovi (#310) jako zjednodušený opravný daňový doklad.
     *
     * § 45 odst. 2 ZDPH: „Pokud byl původní daňový doklad vystaven jako zjednodušený daňový
     * doklad a osoba, pro kterou se plnění uskutečnilo, není plátci dostatečně známa, nemusí
     * opravný daňový doklad obsahovat náležitosti podle odstavce 1 písm. c), d), h) a i)."
     *
     * - Opravovaný doklad musí být vystavená (ne stornovaná) faktura téže firmy a téhož
     *   odběratele s příznakem zjednodušeného dokladu. Bez vazby na původní doklad nejde
     *   doložit evidenční číslo opravovaného dokladu (§ 45 odst. 1 písm. e) a výjimka
     *   odstavce 2 se nedá posoudit.
     * - Dobropis sám nese příznak `is_simplified`; jinak by šlo o běžný opravný doklad,
     *   který potřebuje označení odběratele (§ 45 odst. 1 písm. c).
     * - Vlastní částkový limit § 45 nemá. Opravný doklad se ale váže k původnímu plnění,
     *   které bylo zjednodušené jen do limitu § 30 odst. 1, takže dobropisy (i dřív
     *   vystavené k témuž dokladu) nesmí v součtu přesáhnout částku původního dokladu.
     *   Obecný limit § 30 odst. 1 pro každý zjednodušený doklad hlídá dál
     *   {@see simplifiedRejection()}.
     *
     * Odstavec 2 neuvolňuje důvod opravy, rozdíl celkové částky ani den podle § 42 odst. 3
     * (§ 45 odst. 1 písm. g, j, k); ty dobropis nese vždy.
     *
     * @param array<string,mixed> $invoice dobropis s `id`, `parent_invoice_id`, `client_id`,
     *                                     `supplier_id`, `is_simplified`, `total_with_vat`
     * @return array{code:string, message:string, status:int}|null
     */
    private function simplifiedOnlyCreditNoteRejection(array $invoice, string $name): ?array
    {
        $original = false;
        $parentId = (int) ($invoice['parent_invoice_id'] ?? 0);
        if ($parentId > 0) {
            $originalStmt = $this->db->pdo()->prepare(
                'SELECT id, varsymbol, client_id, invoice_type, is_simplified, status, total_with_vat
                   FROM invoices WHERE id = ? AND supplier_id = ?'
            );
            $originalStmt->execute([$parentId, (int) $invoice['supplier_id']]);
            $original = $originalStmt->fetch(\PDO::FETCH_ASSOC);
        }
        if ($original === false
            || $original['invoice_type'] !== 'invoice'
            || (int) $original['is_simplified'] !== 1
            || in_array($original['status'], ['draft', 'cancelled'], true)
            || (int) $original['client_id'] !== (int) ($invoice['client_id'] ?? 0)
        ) {
            return self::rejection(
                'simplified_only_client_credit_note_requires_simplified_original',
                sprintf(
                    'Odběratel „%s" je veden jako drobný zákazník bez adresy. Dobropis mu lze vystavit '
                        . 'jen jako zjednodušený opravný daňový doklad k vystavenému zjednodušenému daňovému '
                        . 'dokladu téhož odběratele (§ 45 odst. 2 ZDPH). Založte dobropis z původního dokladu, '
                        . 'nebo doplňte na kartě odběratele adresu a volbu drobný zákazník vypněte.',
                    $name,
                ),
            );
        }
        if (empty($invoice['is_simplified'])) {
            return self::rejection(
                'simplified_only_client_requires_simplified',
                sprintf(
                    'Odběratel „%s" je veden jako drobný zákazník bez adresy. Dobropis k zjednodušenému '
                        . 'dokladu %s lze vystavit jen jako zjednodušený opravný daňový doklad (§ 45 odst. 2 '
                        . 'ZDPH). Zaškrtněte zjednodušený daňový doklad, nebo doplňte na kartě odběratele '
                        . 'adresu a volbu drobný zákazník vypněte.',
                    $name,
                    (string) $original['varsymbol'],
                ),
            );
        }
        $creditedStmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(ABS(total_with_vat)), 0) FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'credit_note'
                AND status NOT IN ('draft', 'cancelled') AND id <> ?"
        );
        $creditedStmt->execute([(int) $original['id'], (int) ($invoice['id'] ?? 0)]);
        $remaining = round(abs((float) $original['total_with_vat']) - (float) $creditedStmt->fetchColumn(), 2);
        $total = abs((float) ($invoice['total_with_vat'] ?? 0));
        if ($total > $remaining + 0.005) {
            return self::rejection(
                'simplified_only_client_credit_note_over_original',
                sprintf(
                    'Dobropis na %s Kč přesahuje částku, kterou lze ještě opravit u zjednodušeného dokladu %s '
                        . '(zbývá %s Kč). Zjednodušený opravný daňový doklad (§ 45 odst. 2 ZDPH) jen snižuje '
                        . 'původní plnění. Snižte částku dobropisu, nebo doplňte na kartě odběratele „%s" adresu, '
                        . 'volbu drobný zákazník vypněte a opravu vystavte jako běžný doklad.',
                    number_format($total, 2, ',', ' '),
                    (string) $original['varsymbol'],
                    number_format(max(0.0, $remaining), 2, ',', ' '),
                    $name,
                ),
            );
        }
        return null;
    }

    /**
     * § 30 odst. 1 a 2 ZDPH: limit zjednodušeného dokladu a jeho výjimky
     * ({@see SimplifiedDocumentPolicy}).
     *
     * @param array<string,mixed> $invoice hlavička se `total_with_vat`, `reverse_charge`, `vat_classification_code`
     * @return array{code:string, message:string, status:int}|null
     */
    public function simplifiedRejection(array $invoice): ?array
    {
        if (empty($invoice['is_simplified'])) {
            return null;
        }
        $reason = SimplifiedDocumentPolicy::rejectionReason(
            $invoice,
            $this->dphdp3Line($invoice),
            $this->simplifiedLimit($invoice),
        );
        return $reason === null ? null : self::rejection('simplified_document_not_allowed', $reason);
    }

    /**
     * Smí doklad vzniknout jako zjednodušený? Totéž pravidlo jako
     * {@see simplifiedRejection()}, jen bez příznaku na dokladu: rychlá účtenka se
     * podle něj rozhoduje, který doklad vystaví.
     *
     * @param array<string,mixed> $invoice
     */
    public function allowsSimplified(array $invoice): bool
    {
        return $this->simplifiedRejection(['is_simplified' => 1] + $invoice) === null;
    }

    /**
     * Neplátce nesmí vystavit doklad s DPH (§ 108 odst. 4 ZDPH). Rozhoduje stav
     * k rozhodnému datu dokladu, ne dnešní cache. Reverse charge se nekontroluje:
     * RC řádky daň nenesou.
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function vatPayerRejection(array $invoice): ?array
    {
        $date = self::vatStatusDate($invoice);
        if ((float) ($invoice['total_vat'] ?? 0) <= 0
            || !empty($invoice['reverse_charge'])
            || $this->vatStatus->isVatPayerAt((int) $invoice['supplier_id'], $date)
        ) {
            return null;
        }
        return self::rejection(
            'not_vat_payer_at_date',
            sprintf(
                'Firma není k rozhodnému datu dokladu (%s) plátcem DPH — doklad s DPH nelze vystavit. '
                    . 'Přepněte položky na sazbu 0 %%, nebo opravte datum zdanitelného plnění.',
                date('j. n. Y', (int) strtotime($date)),
            ),
        );
    }

    /** @param array<string,mixed> $invoice */
    private function dphdp3Line(array $invoice): ?string
    {
        $lineStmt = $this->db->pdo()->prepare(
            'SELECT dphdp3_line FROM vat_classifications
              WHERE code = ? AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY supplier_id IS NULL LIMIT 1'
        );
        $lineStmt->execute([
            (string) ($invoice['vat_classification_code'] ?? ''),
            (int) $invoice['supplier_id'],
        ]);
        $line = $lineStmt->fetchColumn();
        return $line === false || $line === null ? null : (string) $line;
    }

    /** @param array<string,mixed> $invoice */
    private function simplifiedLimit(array $invoice): float
    {
        $docYear = (int) substr((string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']), 0, 4);
        return (float) ($this->taxConstants->forYear($docYear)['simplified_document_limit_with_vat']
            ?? SimplifiedDocumentPolicy::LIMIT_WITH_VAT);
    }

    /** @return array{code:string, message:string, status:int} */
    private static function rejection(string $code, string $message, int $status = 422): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }
}
