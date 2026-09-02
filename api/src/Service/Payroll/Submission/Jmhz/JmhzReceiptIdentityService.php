<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use Closure;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Uzavření smyčky identifikátorů ČSSZ nad protokolem k hlášení JMHZ.
 *
 * ── Co tu chybělo ───────────────────────────────────────────────────────────
 * Podepsaný protokol ČSSZ vrací u každého formuláře atribut `identifier`
 * ve tvaru „IKMPSV;IDPPV", tedy OIČ osoby (10051) a ID PPV pracovního vztahu
 * (10228). {@see Transport\JmhzProtocolParser} je přečte,
 * {@see Transport\JmhzReceiptVerifier} je vynese nahoru a
 * {@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionService} je
 * uloží do `payroll_jmhz_protocol_form_outcomes`. Tam ale skončily: do
 * evidence, ze které se staví další hlášení, se nikdy nedostaly. Účetní je
 * musela z protokolu opisovat ručně u každého vztahu zvlášť.
 *
 * ── Jak se formulář páruje na pracovní vztah ────────────────────────────────
 * Dvojici „GUID formuláře → pracovní vztah" žádná tabulka nedrží a držet
 * nemá: podle rozhodnutí {@see JmhzSubmissionBridgeService} je zmrazený
 * artefakt JEDINÁ pravda o tom, co jsme odeslali. Páruje se proto stejně,
 * jako to dělá opravné podání ({@see JmhzCorrectiveSubmissionService}) —
 * přes ID PPV zapsané ve zmrazeném XML u téhož GUIDu. To číslo je v rámci
 * firmy a prostředí jednoznačné, takže z něj vede jediná cesta k vztahu.
 *
 * ── Co se smí zapsat a co je nález ──────────────────────────────────────────
 * 1. Evidence hodnotu nemá → zapíše se jako `trusted_receipt` s odkazem na
 *    protokol. Tohle je ta uzavřená smyčka.
 * 2. Evidence má TUTÉŽ hodnotu → nedělá se nic. Protokol se může načíst
 *    znovu (jiný idempotency klíč, jiný kanál) a nesmí z toho vzniknout
 *    druhý záznam ani výjimka.
 * 3. Evidence má JINOU hodnotu → NIKDY se tiše nepřepisuje. Dvě různá čísla
 *    u jednoho vztahu znamenají, že se buď spletl opis, nebo ČSSZ osobu
 *    přečíslovala; obojí musí vidět člověk. Zakládá se úkol ke ztotožnění
 *    přes {@see PayrollRegistrationIdentityService::openResolutionTask()} —
 *    tedy tentýž mechanismus, kterým se hlásí neshody identity ve zbytku
 *    řetězce, a který je sám idempotentní (otevřený úkol se nezakládá
 *    podruhé).
 *
 * ── Proč se tady nikdy nevyhazuje výjimka za nedohledaný vztah ──────────────
 * Volá se to uvnitř transakce importu protokolu. Výjimka by vrátila zpět
 * CELÝ import, tedy i uložení podepsaného protokolu a přechod stavu podání —
 * a přijetí u ČSSZ vzít zpět nejde. Nedohledatelný vztah proto jen znamená,
 * že se identita nepřevezme; řádek v `payroll_jmhz_protocol_form_outcomes`
 * zůstává i tak a dohledat se z něj dá všechno.
 */
final readonly class JmhzReceiptIdentityService
{
    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollRegistrationIdentityRepository $identities,
        private PayrollRegistrationIdentityService $identityService,
        private PayrollSensitiveData $sensitiveData,
        /**
         * Zmrazená datová věta se čte líně.
         *
         * {@see JmhzFrozenPayloadReader} si bere `PayrollSubmissionService`,
         * který si zase bere tuhle službu — přímá závislost by byla kruhová
         * a kontejner by ji nesestavil. Uzavření přes closure kruh přetíná
         * a zároveň drží čtení artefaktu tam, kam patří: proběhne jen tehdy,
         * když protokol opravdu nese identitu k převzetí.
         */
        private Closure $frozenPayloadReader,
    ) {}

    /**
     * @return array{
     *   assigned:int,
     *   findings:list<array{
     *     form_guid:string,employment_id:int,field:string,reason_code:string,
     *     task_id:int
     *   }>
     * }
     */
    public function applyAcceptedFormIdentities(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        ?int $actorId,
    ): array {
        $outcomes = $this->submissions->acceptedJmhzFormIdentityOutcomes(
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
        );
        if ($outcomes === []) {
            return ['assigned' => 0, 'findings' => []];
        }

        $frozen = $this->frozenEmploymentReferences(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($frozen === []) {
            return ['assigned' => 0, 'findings' => []];
        }

        $assigned = 0;
        $findings = [];
        foreach ($outcomes as $outcome) {
            $guid = strtoupper($outcome['form_guid']);
            $sentEmploymentReference = $frozen[$guid] ?? null;
            if ($sentEmploymentReference === null) {
                continue;
            }
            $link = $this->linkedEmployment(
                $supplierId,
                $environment,
                $sentEmploymentReference,
            );
            if ($link === null) {
                continue;
            }
            [$employeeId, $employmentId] = $link;
            $sourceReference = self::sourceReference(
                $submissionId,
                $receiptId,
                $outcome['receipt_reference'],
                $outcome['effective_on'],
                $guid,
            );

            foreach ([
                [
                    'field' => 'person_external_identifier',
                    'value' => $outcome['external_person_reference'],
                    'task_kind' => 'person_identity',
                    'reason_code' => 'jmhz_protocol_person_identifier_mismatch',
                ],
                [
                    'field' => 'employment_external_identifier',
                    'value' => $outcome['external_employment_reference'],
                    'task_kind' => 'employment_external_id',
                    'reason_code' =>
                        'jmhz_protocol_employment_identifier_mismatch',
                ],
            ] as $subject) {
                $matches = $this->matches(
                    $subject['field'],
                    $supplierId,
                    $employeeId,
                    $employmentId,
                    $environment,
                    $subject['value'],
                );
                if ($matches === true) {
                    continue;
                }
                if ($matches === false) {
                    $task = $this->identityService->openResolutionTask(
                        $supplierId,
                        $employmentId,
                        $environment,
                        $subject['task_kind'],
                        $subject['reason_code'],
                        null,
                        $receiptId,
                        null,
                        $actorId,
                    );
                    $findings[] = [
                        'form_guid' => $guid,
                        'employment_id' => $employmentId,
                        'field' => $subject['field'],
                        'reason_code' => $subject['reason_code'],
                        'task_id' => $task['id'],
                    ];
                    continue;
                }
                $this->assign(
                    $subject['field'],
                    $supplierId,
                    $employeeId,
                    $employmentId,
                    $environment,
                    $subject['value'],
                    $outcome['effective_on'],
                    $sourceReference,
                    $receiptId,
                    $actorId,
                );
                $assigned++;
            }
        }

        return ['assigned' => $assigned, 'findings' => $findings];
    }

    /**
     * GUID formuláře → ID PPV tak, jak jsme ho ODESLALI.
     *
     * ── Známé omezení: jmenná větev ─────────────────────────────────────────
     * Formulář ohlášený jmennou větví `identifikaceType` (nově registrovaný
     * zaměstnanec, kterému ČSSZ ještě žádná čísla nepřidělila) ve zmrazeném
     * XML ID PPV nemá, takže se odsud nevrátí a jeho identita se nepřevezme.
     * Není to tichá ztráta: výsledek formuláře i s oběma čísly zůstává
     * v `payroll_jmhz_protocol_form_outcomes` a účetní ho z karty vztahu
     * doplní ručně. Doplnit sem párování jmenné větve jde jedině přes údaje,
     * kterými se ohlásila (příjmení, jméno, datum narození, datum nástupu,
     * druh činnosti) — a to je samostatná úloha, protože jednoznačnost tam
     * musí hlídat vlastní pravidlo.
     *
     * @return array<string,string>
     */
    private function frozenEmploymentReferences(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $reader = ($this->frozenPayloadReader)();
        if (!$reader instanceof JmhzFrozenPayloadReader) {
            throw new \LogicException(
                'Čtečka zmrazené datové věty JMHZ není k dispozici.',
            );
        }
        try {
            $description = $reader->describe(
                $supplierId,
                $environment,
                $submissionId,
            );
        } catch (JmhzXmlException) {
            // Podání bez čitelné zmrazené věty se nedá spárovat na vztahy.
            // Import protokolu na tom ale padat nesmí — viz docblock třídy.
            return [];
        }

        $references = [];
        foreach ($description['forms'] as $form) {
            $reference = $form['employment_external_identifier'];
            if ($reference === null) {
                continue;
            }
            $references[strtoupper($form['form_guid'])] = $reference;
        }

        return $references;
    }

    /** @return array{0:int,1:int}|null dvojice employee_id, employment_id */
    private function linkedEmployment(
        int $supplierId,
        string $environment,
        string $sentEmploymentReference,
    ): ?array {
        try {
            $hash = $this->sensitiveData->lookupHash(
                $sentEmploymentReference,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
        $link = $this->identities->employmentByExternalIdValueHash(
            $supplierId,
            $environment,
            'id_ppv',
            $hash,
        );
        if ($link === null) {
            return null;
        }

        return [$link['employee_id'], $link['employment_id']];
    }

    private function matches(
        string $field,
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $value,
    ): ?bool {
        try {
            return $field === 'person_external_identifier'
                ? $this->identityService->activePersonExternalIdMatches(
                    $supplierId,
                    $employeeId,
                    $environment,
                    $value,
                )
                : $this->identityService->activeEmploymentExternalIdMatches(
                    $supplierId,
                    $employmentId,
                    $environment,
                    $value,
                );
        } catch (\InvalidArgumentException) {
            // Protokol nese číslo, které neprojde tvarovou kontrolou. Brát ho
            // jako shodu ani jako neshodu nejde — přeskočí se, řádek výsledku
            // formuláře zůstává pro dohledání.
            return true;
        }
    }

    private function assign(
        string $field,
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $value,
        string $validFrom,
        string $sourceReference,
        int $receiptId,
        ?int $actorId,
    ): void {
        if ($field === 'person_external_identifier') {
            $this->identityService->assignPersonExternalId(
                $supplierId,
                $employeeId,
                $environment,
                $value,
                $validFrom,
                'trusted_receipt',
                $sourceReference,
                $receiptId,
                $actorId,
            );

            return;
        }
        $this->identityService->assignEmploymentExternalId(
            $supplierId,
            $employmentId,
            $environment,
            $value,
            $validFrom,
            'trusted_receipt',
            $sourceReference,
            $receiptId,
            $actorId,
        );
    }

    /**
     * Podklad se popisuje tak, aby šlo dohledat KONKRÉTNÍ protokol: jeho
     * číslo (když ho ČSSZ uvedla), den přijetí, podání a formulář. Samotný
     * odkaz na řádek protokolu jde do `source_receipt_id`, tohle je text pro
     * člověka, který se ptá „odkud to číslo je".
     */
    private static function sourceReference(
        int $submissionId,
        int $receiptId,
        ?string $receiptReference,
        string $receivedOn,
        string $formGuid,
    ): string {
        return sprintf(
            'jmhz25-protokol:%s:%s:podani:%d:formular:%s',
            $receiptReference ?? ('protokol-' . $receiptId),
            $receivedOn,
            $submissionId,
            $formGuid,
        );
    }
}
