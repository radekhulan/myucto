<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DateTimeImmutable;
use DomainException;

final readonly class XmlzamCooperationResponse
{
    /**
     * @param array{phone?:list<string>,email?:list<string>,address?:list<string>,wage_account?:list<string>}|null $debtorContact
     * @param array{phone?:list<string>,email?:list<string>}|null $employerContact
     * @param list<array{period:string,gross_minor:int,withheld_minor:int,dependants:int}> $wages
     * @param list<array{priority:int,subject:string,chamber:string,case_reference:string,claim_kind:string,delivered_on:string,priority_on:string,outstanding_minor:int}> $enforcements
     * @param list<array{kind:string,name:string}> $attachments
     */
    public function __construct(
        public string $identifier,
        public string $reactionTo,
        public string $issuedOn,
        public ?string $note,
        public ?array $debtorContact,
        public ?array $employerContact,
        public int $priority,
        public bool $sharedPriority,
        public bool $employmentActive,
        public string $employedFrom,
        public ?string $employedTo,
        public array $wages,
        public array $enforcements,
        public array $attachments,
    ) {
        self::identifier($identifier);
        self::identifier($reactionTo);
        self::date($issuedOn, false);
        self::date($employedFrom, true);
        self::date($employedTo ?? '', true);
        if ($note !== null && mb_strlen($note) > 250) {
            throw new DomainException('Poznámka XMLZAM smí mít nejvýše 250 znaků.');
        }
        if ($priority < -32768 || $priority > 32767) {
            throw new DomainException('Pořadí XMLZAM je mimo podporovaný rozsah.');
        }
        self::contacts($debtorContact, true);
        self::contacts($employerContact, false);
        foreach ($wages as $wage) {
            if (preg_match('/^[12]\d{3}-(0[1-9]|1[0-2])$/D', $wage['period']) !== 1) {
                throw new DomainException('Období mzdy XMLZAM není platné.');
            }
            self::money($wage['gross_minor']);
            self::money($wage['withheld_minor']);
            if ($wage['dependants'] < -32768 || $wage['dependants'] > 32767) {
                throw new DomainException('Počet vyživovaných osob XMLZAM je mimo rozsah.');
            }
        }
        foreach ($enforcements as $enforcement) {
            if ($enforcement['priority'] < -32768 || $enforcement['priority'] > 32767) {
                throw new DomainException('Pořadí exekuce XMLZAM je mimo rozsah.');
            }
            if (preg_match('/^\d{3}$/D', $enforcement['chamber']) !== 1) {
                throw new DomainException('Senát exekutora XMLZAM není platný.');
            }
            if (!in_array($enforcement['claim_kind'], ['prednostni', 'neprednostni', 'vyzivne'], true)) {
                throw new DomainException('Druh pohledávky XMLZAM není podporovaný.');
            }
            self::date($enforcement['delivered_on'], false);
            self::date($enforcement['priority_on'], false);
            self::money($enforcement['outstanding_minor']);
            if ($enforcement['subject'] === '' || mb_strlen($enforcement['subject']) > 250) {
                throw new DomainException('Subjekt exekuce XMLZAM není platný.');
            }
            if ($enforcement['case_reference'] === '' || mb_strlen($enforcement['case_reference']) > 50) {
                throw new DomainException('Spisová značka exekuce XMLZAM není platná.');
            }
        }
        foreach ($attachments as $attachment) {
            if (!in_array($attachment['kind'], ['exekucni_prikaz', 'soucinnost', 'ostatni'], true)
                || $attachment['name'] === ''
                || mb_strlen($attachment['name']) > 250
            ) {
                throw new DomainException('Příloha XMLZAM není platná.');
            }
        }
    }

    private static function identifier(string $value): void
    {
        if (preg_match('/^\d{3}-\d{8}-[a-zA-Z0-9]{1,5}$/D', $value) !== 1) {
            throw new DomainException('Identifikátor XMLZAM není platný.');
        }
    }

    private static function date(string $value, bool $emptyAllowed): void
    {
        if ($value === '' && $emptyAllowed) {
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Datum XMLZAM není platné.');
        }
    }

    /** @param array<string,list<string>>|null $contacts */
    private static function contacts(?array $contacts, bool $debtor): void
    {
        if ($contacts === null) {
            return;
        }
        $allowed = $debtor
            ? ['phone', 'email', 'address', 'wage_account']
            : ['phone', 'email'];
        foreach ($contacts as $kind => $values) {
            if (!in_array($kind, $allowed, true)) {
                throw new DomainException('Kontakt XMLZAM obsahuje nepovolený údaj.');
            }
            foreach ($values as $value) {
                if ($value === '') {
                    throw new DomainException('Kontakt XMLZAM obsahuje prázdnou hodnotu.');
                }
            }
        }
    }

    private static function money(int $minor): void
    {
        if ($minor < 0 || $minor > 99_999_999_999_999) {
            throw new DomainException('Částka XMLZAM je mimo podporovaný rozsah.');
        }
    }
}
