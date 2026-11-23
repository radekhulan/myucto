<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Šablony ručních zápisů (Fáze F, audit 2026-07 nález „Ruční zápis nemá šablony
 * ani opakování"). Hlavička journal_entry_templates + řádky journal_entry_template_lines
 * (účet, strana, volitelná výchozí částka — NULL = „doplň při vložení", typicky
 * variabilní položky jako mzdy). Šablony jsou čistě datové (žádné zaúčtování) —
 * vytvoření zápisu ze šablony jde vždy přes běžný POST /accounting/journal
 * (ManualEntry.vue jen předvyplní řádky), veškerá validace podvojnosti/idempotence
 * zůstává výhradně v {@see \MyInvoice\Service\Accounting\PostingService}.
 *
 * is_seeded + seed_key (1100) = doporučené (systémové) šablony lazy naseedované per
 * firma — „Mzdy" ({@see self::ensurePayrollSeed}) a obecné předuzávěrkové šablony
 * (Task 34, {@see self::ensureClosingTemplatesSeed}: dohadné položky, časové
 * rozlišení, kurzové rozdíly, rezervy, opravné položky/odpis pohledávky). seed_key
 * je stabilní klíč konkrétní seedované šablony, aby existence check jedné šablony
 * neblokoval seed dalších.
 */
final class JournalEntryTemplateRepository
{
    /**
     * Řádky doporučené šablony „Mzdy" — typický měsíční předpis z výstupu externí
     * mzdovky (mzdový můstek, ne plná mzdová agenda — viz PLAN.md Fáze F). Částky
     * se NEDOPOČÍTÁVAJÍ (mimo scope), účetní je doplní ručně nebo importem CSV.
     */
    private const PAYROLL_LINES = [
        ['label' => 'Hrubé mzdy',                                       'account_code' => '521', 'side' => 'debit'],
        ['label' => 'Sociální a zdravotní pojištění za zaměstnavatele', 'account_code' => '524', 'side' => 'debit'],
        ['label' => 'Závazek vůči zaměstnancům (čistá mzda k výplatě)', 'account_code' => '331', 'side' => 'credit'],
        ['label' => 'Zúčtování se OSSZ a zdravotními pojišťovnami',     'account_code' => '336', 'side' => 'credit'],
        ['label' => 'Záloha na daň ze závislé činnosti',                'account_code' => '342', 'side' => 'credit'],
    ];

    /**
     * Obecné předuzávěrkové šablony ručních zápisů (Task 34) — účty ověřeny proti
     * {@see \MyInvoice\Service\Accounting\ChartOfAccountsTemplate} a shodné s globálními
     * posting_rules (1006: estimate.*, accrual.*, fx.*, reserve.other.*,
     * allowance.receivable.*). Částka se NEDOPOČÍTÁVÁ, uživatel ji doplní při vložení.
     * U řádků, kde protiúčet záleží na konkrétním případu (kurzové rozdíly), je zvolen
     * typický zástupný účet — popis šablony na to upozorňuje.
     *
     * @var list<array{seed_key:string, name:string, description:string,
     *                  lines:list<array{label:string, account_code:string, side:'debit'|'credit'}>}>
     */
    private const CLOSING_TEMPLATES = [
        [
            'seed_key' => 'closing.accrued_liability',
            'name' => 'Dohadná položka pasivní',
            'description' => 'Nevyfakturované dodávky/služby k rozvahovému dni (protiúčet dle druhu nákladu, zde 518 — uprav dle skutečnosti).',
            'lines' => [
                ['label' => 'Nevyfakturovaná dodávka/služba', 'account_code' => '518', 'side' => 'debit'],
                ['label' => 'Dohadný účet pasivní',           'account_code' => '389', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.accrued_asset',
            'name' => 'Dohadná položka aktivní',
            'description' => 'Nevyfakturované výnosy k rozvahovému dni (protiúčet dle druhu výnosu, zde 602 — uprav dle skutečnosti).',
            'lines' => [
                ['label' => 'Dohadný účet aktivní',    'account_code' => '388', 'side' => 'debit'],
                ['label' => 'Nevyfakturovaný výnos',   'account_code' => '602', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.prepaid_expense_accrue',
            'name' => 'Náklady příštích období — zaúčtování',
            'description' => 'Časové rozlišení nákladu zaplaceného v běžném období, který věcně patří dalšímu období (protiúčet dle druhu nákladu, zde 518).',
            'lines' => [
                ['label' => 'Náklady příštích období',            'account_code' => '381', 'side' => 'debit'],
                ['label' => 'Snížení nákladu běžného období',     'account_code' => '518', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.prepaid_expense_release',
            'name' => 'Náklady příštích období — rozpuštění',
            'description' => 'Rozpuštění časového rozlišení nákladu v období, kterého se věcně týká.',
            'lines' => [
                ['label' => 'Náklad běžného období',      'account_code' => '518', 'side' => 'debit'],
                ['label' => 'Náklady příštích období',    'account_code' => '381', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.deferred_revenue_accrue',
            'name' => 'Výnosy příštích období — zaúčtování',
            'description' => 'Časové rozlišení výnosu přijatého v běžném období, který věcně patří dalšímu období (protiúčet dle druhu výnosu, zde 602).',
            'lines' => [
                ['label' => 'Snížení výnosu běžného období', 'account_code' => '602', 'side' => 'debit'],
                ['label' => 'Výnosy příštích období',        'account_code' => '384', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.deferred_revenue_release',
            'name' => 'Výnosy příštích období — rozpuštění',
            'description' => 'Rozpuštění časového rozlišení výnosu v období, kterého se věcně týká.',
            'lines' => [
                ['label' => 'Výnosy příštích období', 'account_code' => '384', 'side' => 'debit'],
                ['label' => 'Výnos běžného období',   'account_code' => '602', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.accrued_expense',
            'name' => 'Výdaje příštích období',
            'description' => 'Náklad běžného období, jehož úhrada/vyfakturování proběhne až v příštím období — částka je známá (protiúčet dle druhu nákladu, zde 518).',
            'lines' => [
                ['label' => 'Náklad běžného období',   'account_code' => '518', 'side' => 'debit'],
                ['label' => 'Výdaje příštích období',  'account_code' => '383', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.accrued_income',
            'name' => 'Příjmy příštích období',
            'description' => 'Výnos běžného období, jehož úhrada proběhne až v příštím období — částka je známá (protiúčet dle druhu výnosu, zde 602).',
            'lines' => [
                ['label' => 'Příjmy příštích období', 'account_code' => '385', 'side' => 'debit'],
                ['label' => 'Výnos běžného období',   'account_code' => '602', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.fx_loss',
            'name' => 'Kurzový rozdíl k rozvahovému dni — ztráta',
            'description' => 'Přecenění pohledávky/závazku v cizí měně k rozvahovému dni — kurzová ztráta (protiúčet 311/321/221 dle konkrétní položky — uprav dle skutečnosti).',
            'lines' => [
                ['label' => 'Kurzová ztráta',                                  'account_code' => '563', 'side' => 'debit'],
                ['label' => 'Přecenění závazku/pohledávky v cizí měně',       'account_code' => '321', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.fx_gain',
            'name' => 'Kurzový rozdíl k rozvahovému dni — zisk',
            'description' => 'Přecenění pohledávky/závazku v cizí měně k rozvahovému dni — kurzový zisk (protiúčet 311/321/221 dle konkrétní položky — uprav dle skutečnosti).',
            'lines' => [
                ['label' => 'Přecenění pohledávky/závazku v cizí měně', 'account_code' => '311', 'side' => 'debit'],
                ['label' => 'Kurzový zisk',                             'account_code' => '663', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.reserve_create',
            'name' => 'Tvorba ostatní rezervy',
            'description' => 'Tvorba ostatní (nezákonné) rezervy k rozvahovému dni.',
            'lines' => [
                ['label' => 'Tvorba rezervy',    'account_code' => '554', 'side' => 'debit'],
                ['label' => 'Ostatní rezervy',   'account_code' => '459', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.reserve_release',
            'name' => 'Čerpání / zrušení ostatní rezervy',
            'description' => 'Čerpání nebo zrušení dříve vytvořené ostatní rezervy.',
            'lines' => [
                ['label' => 'Ostatní rezervy',           'account_code' => '459', 'side' => 'debit'],
                ['label' => 'Čerpání/zrušení rezervy',   'account_code' => '554', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.receivable_writeoff',
            'name' => 'Odpis pohledávky',
            'description' => 'Přímý odpis nedobytné pohledávky.',
            'lines' => [
                ['label' => 'Odpis pohledávky',                      'account_code' => '546', 'side' => 'debit'],
                ['label' => 'Pohledávky z obchodních vztahů',       'account_code' => '311', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.allowance_create',
            'name' => 'Tvorba opravné položky k pohledávkám',
            'description' => 'Tvorba účetní opravné položky k rizikové pohledávce (daňově neúčinná — pro zákonnou OP dle zákona o rezervách uprav účet na 558).',
            'lines' => [
                ['label' => 'Tvorba opravné položky',          'account_code' => '559', 'side' => 'debit'],
                ['label' => 'Opravná položka k pohledávkám',   'account_code' => '391', 'side' => 'credit'],
            ],
        ],
        [
            'seed_key' => 'closing.allowance_release',
            'name' => 'Rozpuštění opravné položky k pohledávkám',
            'description' => 'Rozpuštění dříve vytvořené účetní opravné položky k pohledávkám.',
            'lines' => [
                ['label' => 'Opravná položka k pohledávkám',   'account_code' => '391', 'side' => 'debit'],
                ['label' => 'Rozpuštění opravné položky',      'account_code' => '559', 'side' => 'credit'],
            ],
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Lazy idempotentní seed doporučené šablony „Mzdy" — volá se z GET seznamu
     * (JournalTemplateAction::list), aby dostaly šablonu jak nově vytvořené firmy,
     * tak firmy existující před touto featurou (bez nutnosti datové migrace).
     */
    public function ensurePayrollSeed(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entry_templates WHERE supplier_id = ? AND seed_key = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, 'payroll']);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO journal_entry_templates (supplier_id, name, description, is_seeded, seed_key)
                 VALUES (?, ?, ?, 1, ?)'
            );
            $ins->execute([
                $supplierId,
                'Mzdy',
                'Doporučená šablona měsíční mzdové rekapitulace z externí mzdovky — částky doplň ručně nebo importem CSV.',
                'payroll',
            ]);
            $templateId = (int) $pdo->lastInsertId();

            $insLine = $pdo->prepare(
                'INSERT INTO journal_entry_template_lines (template_id, line_no, label, account_code, side, default_amount)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            foreach (self::PAYROLL_LINES as $i => $l) {
                $insLine->execute([$templateId, $i + 1, $l['label'], $l['account_code'], $l['side']]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Lazy idempotentní seed obecných předuzávěrkových šablon (Task 34) — volá se
     * z GET seznamu (JournalTemplateAction::list) vedle {@see self::ensurePayrollSeed}.
     * Každá šablona z {@see self::CLOSING_TEMPLATES} se seedne samostatně (per
     * seed_key), takže smazání jedné uživatelem nezablokuje seed ostatních a nově
     * přidané šablony do CLOSING_TEMPLATES doseednou i firmám, které je ještě nemají.
     */
    public function ensureClosingTemplatesSeed(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare(
            'SELECT 1 FROM journal_entry_templates WHERE supplier_id = ? AND seed_key = ? LIMIT 1'
        );

        foreach (self::CLOSING_TEMPLATES as $tpl) {
            $exists->execute([$supplierId, $tpl['seed_key']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }

            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO journal_entry_templates (supplier_id, name, description, is_seeded, seed_key)
                     VALUES (?, ?, ?, 1, ?)'
                );
                $ins->execute([$supplierId, $tpl['name'], $tpl['description'], $tpl['seed_key']]);
                $templateId = (int) $pdo->lastInsertId();

                $insLine = $pdo->prepare(
                    'INSERT INTO journal_entry_template_lines (template_id, line_no, label, account_code, side, default_amount)
                     VALUES (?, ?, ?, ?, ?, NULL)'
                );
                foreach ($tpl['lines'] as $i => $l) {
                    $insLine->execute([$templateId, $i + 1, $l['label'], $l['account_code'], $l['side']]);
                }
                if ($ownTx) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    /** @return list<array{id:int,name:string,description:?string,is_seeded:bool,line_count:int,created_at:string}> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.name, t.description, t.is_seeded, t.created_at,
                    COUNT(l.id) AS line_count
               FROM journal_entry_templates t
               LEFT JOIN journal_entry_template_lines l ON l.template_id = t.id
              WHERE t.supplier_id = ?
              GROUP BY t.id, t.name, t.description, t.is_seeded, t.created_at
              ORDER BY t.is_seeded DESC, t.name ASC'
        );
        $stmt->execute([$supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'name'        => (string) $r['name'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'is_seeded'   => (bool) $r['is_seeded'],
                'line_count'  => (int) $r['line_count'],
                'created_at'  => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * @return array{id:int,supplier_id:int,name:string,description:?string,is_seeded:bool,
     *               created_at:string,
     *               lines:list<array{line_no:int,label:?string,account_code:string,side:string,default_amount:?float,cost_center:?string}>}|null
     */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, name, description, is_seeded, created_at
               FROM journal_entry_templates WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $lstmt = $this->db->pdo()->prepare(
            'SELECT line_no, label, account_code, side, default_amount, cost_center, pair_no, line_text
               FROM journal_entry_template_lines
              WHERE template_id = ?
              ORDER BY line_no ASC'
        );
        $lstmt->execute([$id]);

        $lines = [];
        foreach ($lstmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $lines[] = [
                'line_no'        => (int) $l['line_no'],
                'label'          => $l['label'] !== null ? (string) $l['label'] : null,
                'account_code'   => (string) $l['account_code'],
                'side'           => (string) $l['side'],
                'default_amount' => $l['default_amount'] !== null ? (float) $l['default_amount'] : null,
                'cost_center'    => $l['cost_center'] !== null ? (string) $l['cost_center'] : null,
            ];
            // Dvojice jednoduchého zápisu jen u šablony, která je má; ostatní šablony beze změny.
            if ($l['pair_no'] !== null) {
                $lines[count($lines) - 1]['pair_no'] = (int) $l['pair_no'];
            }
            if ($l['line_text'] !== null) {
                $lines[count($lines) - 1]['line_text'] = (string) $l['line_text'];
            }
        }

        return [
            'id'          => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'name'        => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'is_seeded'   => (bool) $row['is_seeded'],
            'created_at'  => (string) $row['created_at'],
            'lines'       => $lines,
        ];
    }

    /**
     * @param list<array{account_code:string, side:'debit'|'credit', amount:?float, label:?string, cost_center:?string}> $lines
     */
    public function create(int $supplierId, string $name, ?string $description, ?int $userId, array $lines): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO journal_entry_templates (supplier_id, name, description, is_seeded, created_by)
                 VALUES (?, ?, ?, 0, ?)'
            );
            $ins->execute([$supplierId, $name, $description, $userId]);
            $templateId = (int) $pdo->lastInsertId();

            $insLine = $pdo->prepare(
                'INSERT INTO journal_entry_template_lines
                    (template_id, line_no, label, account_code, side, default_amount, cost_center, pair_no, line_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($lines) as $i => $l) {
                $insLine->execute([
                    $templateId,
                    $i + 1,
                    ($l['label'] ?? null) !== null && $l['label'] !== '' ? $l['label'] : null,
                    $l['account_code'],
                    $l['side'],
                    $l['amount'],
                    ($l['cost_center'] ?? null) !== null && $l['cost_center'] !== '' ? $l['cost_center'] : null,
                    isset($l['pair_no']) ? (int) $l['pair_no'] : null,
                    ($l['line_text'] ?? null) !== null && $l['line_text'] !== '' ? $l['line_text'] : null,
                ]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return $templateId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array{account_code:string, side:'debit'|'credit', amount:?float, label:?string, cost_center:?string}>|null $lines
     *        null = řádky beze změny (mění se jen hlavička)
     */
    public function update(int $supplierId, int $id, string $name, ?string $description, ?array $lines): bool
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM journal_entry_templates WHERE id = ? AND supplier_id = ?');
        $exists->execute([$id, $supplierId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $header = $pdo->prepare(
                'UPDATE journal_entry_templates SET name = ?, description = ? WHERE id = ? AND supplier_id = ?'
            );
            $header->execute([$name, $description, $id, $supplierId]);

            if ($lines !== null) {
                $pdo->prepare('DELETE FROM journal_entry_template_lines WHERE template_id = ?')->execute([$id]);
            }
            $insLine = $pdo->prepare(
                'INSERT INTO journal_entry_template_lines
                    (template_id, line_no, label, account_code, side, default_amount, cost_center, pair_no, line_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($lines ?? []) as $i => $l) {
                $insLine->execute([
                    $id,
                    $i + 1,
                    ($l['label'] ?? null) !== null && $l['label'] !== '' ? $l['label'] : null,
                    $l['account_code'],
                    $l['side'],
                    $l['amount'],
                    ($l['cost_center'] ?? null) !== null && $l['cost_center'] !== '' ? $l['cost_center'] : null,
                    isset($l['pair_no']) ? (int) $l['pair_no'] : null,
                    ($l['line_text'] ?? null) !== null && $l['line_text'] !== '' ? $l['line_text'] : null,
                ]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM journal_entry_templates WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }
}
