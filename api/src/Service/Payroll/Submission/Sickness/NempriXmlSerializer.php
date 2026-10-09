<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;

/**
 * Serializace datové věty NEMPRI25.
 *
 * Pořadí prvků není volba stylu. `CtDatovaVeta`, `CtDokument`, `CtZamestnani`
 * i `CtPotvrzeniZamestnavateleNem` jsou `xs:sequence`, takže prohozený
 * `druhDavky` a `kodOSSZ` neprojde XSD. U potvrzení zaměstnavatele je navíc
 * past: typ je `xs:extension` nad `CtPotvrzeniZamestnavateleBaseType`, což
 * znamená, že prvky základu (`pracoval`, `pocetOdpracovanychHodin`,
 * `pracovniDoba`, `prijemMalyRozsah`) jdou PŘED prvky rozšíření, ne za ně.
 *
 * `version` je na kořeni `use="required"`; hodnota se bere z připnutého
 * manifestu {@see CsszSchemaCatalog}, ne z konstanty tady — jinak by šlo
 * vyměnit XSD a nechat v podání starou verzi payloadu.
 *
 * `partialAccept` se vědomě NEnastavuje. Podávací a dotazovací protokol v1.47
 * u NEMPRI uvádí částečné přijetí jako „Ano (vždy)", tedy ne jako volbu;
 * posílat atribut, který nic nemění, je jen další místo, kde se dá lhát.
 *
 * ## Akce vznik u ošetřovného
 *
 * ČSSZ odmítá u ošetřovného a dlouhodobého ošetřovného bez akce vznik
 * rozhodné období, potvrzení zaměstnavatele, pracovní volno i den, od kterého
 * se o dávku žádá. Serializér je proto u trvání a ukončení nevypisuje vůbec —
 * i kdyby je případ nesl z dřívější akce.
 */
final class NempriXmlSerializer
{
    public function serialize(NempriXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/nem/NEMPRI25';
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS($namespace, 'NEMPRI');
        $root->setAttribute('version', $payload->payloadVersion);
        $document->appendChild($root);

        $vendor = $document->createElementNS($namespace, 'VENDOR');
        $vendor->setAttribute('productName', $payload->productName);
        $vendor->setAttribute('productVersion', $payload->productVersion);
        $root->appendChild($vendor);

        $sender = $document->createElementNS($namespace, 'SENDER');
        if ($payload->notificationEmail !== null) {
            $sender->setAttribute('EmailNotifikace', $payload->notificationEmail);
        }
        // `ISDSreport="3"` = XML i HTML příloha odpovědi. Pro VREP se atribut
        // ignoruje; pro datovou schránku je to jediná varianta, ze které se dá
        // protokol strojově přečíst i ručně zkontrolovat.
        $sender->setAttribute('ISDSreport', '3');
        $root->appendChild($sender);

        $record = $document->createElementNS($namespace, 'datovaVeta');
        // Datová věta unese 1 až 1500 formulářů. Aplikace posílá právě jeden:
        // lhůta podle § 97 odst. 2 běží každému případu zvlášť a dávkové
        // podání by ji svázalo s cizím případem.
        $record->setAttribute('poradoveCislo', '1');
        $record->appendChild($this->dokument($document, $namespace, $payload));
        $record->appendChild($this->pojistenec($document, $namespace, $payload));
        $record->appendChild($this->zamestnani($document, $namespace, $payload));
        if (self::carriesStartSection($payload) && $payload->decisivePeriod !== null) {
            $record->appendChild($this->rozhodneObdobi(
                $document,
                $namespace,
                $payload->decisivePeriod,
            ));
        }
        $record->appendChild($this->davka($document, $namespace, $payload));
        if ($payload->additionalNote !== null) {
            $this->text(
                $document,
                $namespace,
                $record,
                'dalsiSdeleni',
                $payload->additionalNote,
            );
        }
        $worker = $this->kontaktPracovnik($document, $namespace, $payload);
        if ($worker !== null) {
            $record->appendChild($worker);
        }
        if ($payload->paymentConnection !== null && self::carriesStartSection($payload)) {
            $record->appendChild($this->platebniSpojeni(
                $document,
                $namespace,
                $payload->paymentConnection,
            ));
        }
        $root->appendChild($record);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SicknessException(
                'nempri_xml_serialization_failed',
                'XML oznámení NEMPRI nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    /**
     * Nese věta části, které ČSSZ připouští jen u vzniku nároku?
     *
     * U ošetřovného a dlouhodobého ošetřovného jen s akcí vznik; u ostatních
     * dávek vždy — akci nemají.
     */
    public static function carriesStartSection(NempriXmlPayload $payload): bool
    {
        if (!$payload->benefitKind->hasActions()) {
            return true;
        }

        return $payload->application === null || $payload->application->actionStart;
    }

    /**
     * Příjem z malého rozsahu je v XSD `xs:nonNegativeInteger` v celých Kč
     * (`StPrijemMalyRozsah`). Případ ho drží v haléřích, jako všechny částky
     * mezd; zaokrouhluje se matematicky na celé koruny.
     */
    public static function smallScopeIncomeCzk(int $minor): int
    {
        return intdiv($minor + 50, 100);
    }

    /** Částka v Kč pro `xs:double` — celé koruny bez desetinné části. */
    public static function czkAmount(int $minor): string
    {
        if ($minor % 100 === 0) {
            return (string) intdiv($minor, 100);
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    private function dokument(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'dokument');
        $this->text($document, $namespace, $node, 'kodOSSZ', (string) $payload->osszCode);
        $this->text(
            $document,
            $namespace,
            $node,
            'druhDavky',
            $payload->benefitKind->value,
        );
        if ($payload->correction) {
            $this->bool($document, $namespace, $node, 'opravnePodani', true);
        }
        if ($payload->decisionNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'cisloRozhodnuti',
                $payload->decisionNumber,
            );
        }
        $this->bool($document, $namespace, $node, 'zahranicni', $payload->foreignCase);

        return $node;
    }

    private function pojistenec(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'pojistenec');
        $this->text($document, $namespace, $node, 'jmeno', $payload->insuredFirstName);
        $this->text($document, $namespace, $node, 'prijmeni', $payload->insuredLastName);
        $this->text(
            $document,
            $namespace,
            $node,
            'rodneCislo',
            $payload->insuredBirthNumber,
        );
        if ($payload->insuredPhone !== null || $payload->insuredEmail !== null) {
            $contact = $document->createElementNS($namespace, 'kontakt');
            if ($payload->insuredPhone !== null) {
                $this->text($document, $namespace, $contact, 'telefon', $payload->insuredPhone);
            }
            if ($payload->insuredEmail !== null) {
                $this->text($document, $namespace, $contact, 'email', $payload->insuredEmail);
            }
            $node->appendChild($contact);
        }

        return $node;
    }

    private function zamestnani(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zamestnani');
        $this->text(
            $document,
            $namespace,
            $node,
            'VSZamestnavatel',
            $payload->employerVariableSymbol,
        );
        if ($payload->employerIdentificationNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'ICZamestnavatel',
                $payload->employerIdentificationNumber,
            );
        }
        $this->text(
            $document,
            $namespace,
            $node,
            'nazevZamestnavatel',
            $payload->employerName,
        );
        $this->text($document, $namespace, $node, 'zamestnanOd', $payload->employmentFrom);
        if ($payload->employmentTo !== null) {
            $this->text($document, $namespace, $node, 'zamestnanDo', $payload->employmentTo);
        }
        $this->text($document, $namespace, $node, 'druhCinnosti', $payload->activityCode);

        return $node;
    }

    private function rozhodneObdobi(
        DOMDocument $document,
        string $namespace,
        NempriDecisivePeriod $period,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'rozhodneObdobi');
        $this->text($document, $namespace, $node, 'rozhodneObdobiOd', $period->from);
        $this->text($document, $namespace, $node, 'rozhodneObdobiDo', $period->to);
        if ($period->months !== []) {
            $list = $document->createElementNS($namespace, 'seznamObdobi');
            foreach ($period->months as $month) {
                $item = $document->createElementNS($namespace, 'obdobi');
                $this->text($document, $namespace, $item, 'kalendarniMesic', (string) $month->month);
                $this->text($document, $namespace, $item, 'kalendarniRok', (string) $month->year);
                $this->text(
                    $document,
                    $namespace,
                    $item,
                    'zapocitatelnyPrijem',
                    self::czkAmount($month->countableIncomeMinor),
                );
                $this->text(
                    $document,
                    $namespace,
                    $item,
                    'vylouceneDny',
                    (string) $month->excludedDays,
                );
                $list->appendChild($item);
            }
            $node->appendChild($list);
            if ($period->complete) {
                $this->text(
                    $document,
                    $namespace,
                    $node,
                    'zapocitatelnyPrijemCelkem',
                    self::czkAmount(array_sum(array_map(
                        static fn (NempriDecisiveMonth $month): int => $month->countableIncomeMinor,
                        $period->months,
                    ))),
                );
                $this->text(
                    $document,
                    $namespace,
                    $node,
                    'vylouceneDnyCelkem',
                    (string) array_sum(array_map(
                        static fn (NempriDecisiveMonth $month): int => $month->excludedDays,
                        $period->months,
                    )),
                );
            }
        }
        if ($period->probableIncomeCzk !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pravdepodobnaVysePrijmu',
                (string) $period->probableIncomeCzk,
            );
        }

        return $node;
    }

    private function davka(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'davka');
        $kind = $document->createElementNS(
            $namespace,
            $payload->benefitKind->elementName(),
        );
        $application = $payload->application ?? new NempriBenefitApplication();
        switch ($payload->benefitKind) {
            case SicknessBenefitKind::Nem:
            case SicknessBenefitKind::Vpm:
                $kind->appendChild($this->potvrzeni($document, $namespace, $payload));
                break;
            case SicknessBenefitKind::Opp:
                $kind->appendChild($this->potvrzeniZaklad($document, $namespace, $payload));
                $kind->appendChild($this->zadostOpp($document, $namespace, $application));
                $kind->appendChild($this->podkladyOpp($document, $namespace, $application));
                break;
            case SicknessBenefitKind::Ppm:
                $kind->appendChild($this->potvrzeniPpm($document, $namespace, $payload));
                $kind->appendChild($this->zadostPpm($document, $namespace, $application));
                break;
            case SicknessBenefitKind::Ose:
            case SicknessBenefitKind::Dlo:
                $prefix = $payload->benefitKind === SicknessBenefitKind::Ose ? 'ose' : 'dlo';
                $this->bool($document, $namespace, $kind, $prefix . 'Vznik', $application->actionStart);
                $this->bool($document, $namespace, $kind, $prefix . 'Trvani', $application->actionContinuation);
                $this->bool($document, $namespace, $kind, $prefix . 'Ukonceni', $application->actionEnd);
                if ($application->actionStart) {
                    $kind->appendChild($this->potvrzeniPece($document, $namespace, $payload));
                }
                $kind->appendChild($payload->benefitKind === SicknessBenefitKind::Ose
                    ? $this->zadostOse($document, $namespace, $application)
                    : $this->zadostDlo($document, $namespace, $application));
                $podklady = $payload->benefitKind === SicknessBenefitKind::Ose
                    ? $this->podkladyOse($document, $namespace, $application)
                    : $this->podkladyDlo($document, $namespace, $application);
                if ($podklady !== null) {
                    $kind->appendChild($podklady);
                }
                break;
        }
        $node->appendChild($kind);

        return $node;
    }

    /**
     * Společný základ potvrzení (`CtPotvrzeniZamestnavateleBaseType`).
     *
     * Rozšiřující typy ho mají vždy první; u otcovské je to celé potvrzení.
     */
    private function potvrzeniZaklad(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'potvrzeniZamestnavatele');
        $this->bool($document, $namespace, $node, 'pracoval', $payload->workedOnDecisiveDay);
        // Hodiny a pracovní doba patří k potvrzení právě tehdy, když zaměstnanec
        // v den sociální události pracoval (DV NEMPRI25).
        if ($payload->workedOnDecisiveDay && $payload->hoursWorked !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pocetOdpracovanychHodin',
                $payload->hoursWorked,
            );
        }
        if ($payload->workedOnDecisiveDay && $payload->dailyWorkingHours !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pracovniDoba',
                $payload->dailyWorkingHours,
            );
        }
        if ($payload->smallScopeIncomeMinor !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'prijemMalyRozsah',
                (string) self::smallScopeIncomeCzk($payload->smallScopeIncomeMinor),
            );
        }

        return $node;
    }

    /**
     * `potvrzeniZamestnavatele` u NEM i VPM.
     *
     * Základní část (`pracoval` … `prijemMalyRozsah`) je z rozšiřovaného typu,
     * takže musí jít první. Vyrovnávací příspěvek nemá prvky
     * `volnoBezNahrady*` — u něj by je XSD odmítlo.
     */
    private function potvrzeni(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $this->potvrzeniZaklad($document, $namespace, $payload);
        $this->pension($document, $namespace, $node, $payload);
        $this->student($document, $namespace, $node, $payload);
        $this->bool(
            $document,
            $namespace,
            $node,
            'dobaVolnaPrvniZamestnani',
            $payload->firstEmploymentFreeTime,
        );
        if ($payload->benefitKind->hasUnpaidLeaveSection()) {
            $this->unpaidLeave($document, $namespace, $node, $payload);
        }
        $this->maternity($document, $namespace, $node, $payload);
        $this->transfer($document, $namespace, $node, $payload);
        $this->bool($document, $namespace, $node, 'exekuce', $payload->enforcement);
        $this->bool($document, $namespace, $node, 'insolvence', $payload->insolvency);

        return $node;
    }

    /** `CtPotvrzeniZamestnavatelePpm` — bez studia a bez doby volna. */
    private function potvrzeniPpm(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $this->potvrzeniZaklad($document, $namespace, $payload);
        $this->pension($document, $namespace, $node, $payload);
        $this->maternity($document, $namespace, $node, $payload);
        $this->transfer($document, $namespace, $node, $payload);
        $this->bool($document, $namespace, $node, 'exekuce', $payload->enforcement);
        $this->bool($document, $namespace, $node, 'insolvence', $payload->insolvency);

        return $node;
    }

    /**
     * `CtPotvrzeniZamestnavateleOse` a `CtPotvrzeniZamestnavateleDlo` mají
     * tentýž obsah: studium, převedení na jinou práci a pracovní volno — v tomto
     * pořadí, převedení PŘED volnem (u NEM je to naopak).
     */
    private function potvrzeniPece(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $this->potvrzeniZaklad($document, $namespace, $payload);
        $this->student($document, $namespace, $node, $payload);
        $this->transfer($document, $namespace, $node, $payload);
        $this->unpaidLeave($document, $namespace, $node, $payload);

        return $node;
    }

    private function pension(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriXmlPayload $payload,
    ): void {
        $this->bool($document, $namespace, $node, 'pobiraDuchod', $payload->receivesPension);
        if ($payload->receivesPension && $payload->pensionKind !== null) {
            $this->text($document, $namespace, $node, 'druhDuchodu', $payload->pensionKind);
        }
    }

    private function student(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriXmlPayload $payload,
    ): void {
        $this->bool($document, $namespace, $node, 'jeStudentem', $payload->isStudent);
        if ($payload->isStudent && $payload->withinSchoolHolidays !== null) {
            $this->bool(
                $document,
                $namespace,
                $node,
                'spadaDoPrazdnin',
                $payload->withinSchoolHolidays,
            );
        }
    }

    private function unpaidLeave(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriXmlPayload $payload,
    ): void {
        $this->bool($document, $namespace, $node, 'volnoBezNahrady', $payload->unpaidLeave);
        if ($payload->unpaidLeave && $payload->unpaidLeaveFrom !== null) {
            $this->text($document, $namespace, $node, 'volnoBezNahradyOd', $payload->unpaidLeaveFrom);
        }
        if ($payload->unpaidLeave && $payload->unpaidLeaveTo !== null) {
            $this->text($document, $namespace, $node, 'volnoBezNahradyDo', $payload->unpaidLeaveTo);
        }
    }

    private function maternity(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriXmlPayload $payload,
    ): void {
        if ($payload->startsMaternity !== null) {
            $this->bool($document, $namespace, $node, 'nastupujePPM', $payload->startsMaternity);
        }
        if ($payload->startsMaternity === true && $payload->childBirthDate !== null) {
            $this->text($document, $namespace, $node, 'narozeniDitete', $payload->childBirthDate);
        }
    }

    private function transfer(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriXmlPayload $payload,
    ): void {
        $this->bool(
            $document,
            $namespace,
            $node,
            'prevedenaNaJinouPraci',
            $payload->transferredOtherWork,
        );
        if ($payload->transferredOtherWork && $payload->transferredOn !== null) {
            $this->text($document, $namespace, $node, 'datumNaJinouPraci', $payload->transferredOn);
        }
    }

    private function zadostOpp(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zadostODavku');
        $this->text($document, $namespace, $node, 'odeDne', (string) $application->fromDate);
        $node->appendChild($this->osoba(
            $document,
            $namespace,
            'dite',
            $application->person ?? new NempriPerson('', ''),
        ));
        $this->text($document, $namespace, $node, 'duvodOtcovske', (string) $application->paternityReason);

        return $node;
    }

    private function podkladyOpp(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'podkladyProVyplatDavky');
        $this->lastDayHours($document, $namespace, $node, $application);
        $this->bool($document, $namespace, $node, 'planovaneSmeny', $application->plannedShifts ?? false);
        if ($application->plannedShifts === true) {
            $this->optionalBool($document, $namespace, $node, 'planovaneSmenyOdpracoval', $application->plannedShiftsWorked);
        }
        if ($application->returnedOn !== null) {
            $this->text($document, $namespace, $node, 'datumNavratDoPrace', $application->returnedOn);
        }
        $this->periods($document, $namespace, $node, 'seznamPraceVeDnech', $application->workDays);

        return $node;
    }

    private function zadostPpm(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zadostODavku');
        $this->text($document, $namespace, $node, 'odeDne', (string) $application->fromDate);
        if ($application->maternityCareReason !== null) {
            $this->text($document, $namespace, $node, 'duvodPece', $application->maternityCareReason);
        }
        if ($application->person !== null) {
            $children = $document->createElementNS($namespace, 'deti');
            $child = $this->osoba($document, $namespace, 'dite', $application->person);
            $this->text(
                $document,
                $namespace,
                $child,
                'poradoveCisloDitete',
                (string) ($application->childOrder ?? 1),
            );
            $children->appendChild($child);
            $node->appendChild($children);
        }

        return $node;
    }

    /**
     * Žádost o ošetřovné podle akce (DV NEMPRI25): prvky vzniku jen při akci
     * vznik, prvky trvání a ukončení jen při trvání nebo ukončení. Pořadí
     * prvků určuje XSD, ne logické členění.
     */
    private function zadostOse(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zadostODavku');
        $start = $application->carriesStart();
        $duration = $application->carriesDuration();
        if ($start && $application->fromDate !== null) {
            $this->text($document, $namespace, $node, 'odeDne', $application->fromDate);
        }
        if ($duration && $application->toDate !== null) {
            $this->text($document, $namespace, $node, 'doDne', $application->toDate);
        }
        if ($start) {
            if ($application->person !== null) {
                $node->appendChild($this->osoba($document, $namespace, 'osetrovanaOsoba', $application->person));
            }
            switch ($application->careReason) {
                case NempriBenefitApplication::CARE_REASON_ILL:
                    $this->bool($document, $namespace, $node, 'onemocnela', true);
                    break;
                case NempriBenefitApplication::CARE_REASON_QUARANTINE:
                    $this->bool($document, $namespace, $node, 'narizenaKarantena', true);
                    break;
                case NempriBenefitApplication::CARE_REASON_CANNOT_CARE:
                    $this->bool($document, $namespace, $node, 'nemuzePecovatODite', true);
                    break;
                case NempriBenefitApplication::CARE_REASON_SCHOOL_CLOSED:
                    $school = $document->createElementNS($namespace, 'uzavrenaSkola');
                    $this->text($document, $namespace, $school, 'nazevZarizeniSkoly', (string) $application->schoolName);
                    if ($application->schoolBusinessId !== null) {
                        $this->text($document, $namespace, $school, 'ICZarizeniSkoly', $application->schoolBusinessId);
                    }
                    $node->appendChild($school);
                    break;
            }
            $this->optionalBool($document, $namespace, $node, 'spolecnaDomacnost', $application->sharedHousehold);
            $this->optionalBool($document, $namespace, $node, 'jeOsamely', $application->loneCaregiver);
            $this->optionalBool($document, $namespace, $node, 'vPeciDiteDo16Let', $application->childUnder16);
            $this->optionalBool($document, $namespace, $node, 'narokNaPPMjinouOsobou', $application->otherMaternityClaim);
            if ($application->otherMaternityClaim === true) {
                $this->optionalBool(
                    $document,
                    $namespace,
                    $node,
                    'narokNaRPjinaOsobaNecerpaVolnoNeboOSVC',
                    $application->otherParentalClaim,
                );
                $this->optionalBool($document, $namespace, $node, 'jinaFOParagraf57', $application->otherPersonS57);
            }
        }
        if ($duration) {
            $this->optionalBool($document, $namespace, $node, 'pecovalOsobne', $application->caredPersonally);
            $this->periods($document, $namespace, $node, 'pecovalVeDnech', $application->careDays);
        }
        if ($start && $application->relationshipCode !== null) {
            $this->text($document, $namespace, $node, 'kodRodVztah', $application->relationshipCode);
        }

        return $node;
    }

    private function zadostDlo(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zadostODavku');
        $start = $application->carriesStart();
        $duration = $application->carriesDuration();
        if ($start && $application->fromDate !== null) {
            $this->text($document, $namespace, $node, 'odeDne', $application->fromDate);
        }
        if ($duration && $application->toDate !== null) {
            $this->text($document, $namespace, $node, 'doDne', $application->toDate);
        }
        if ($start) {
            if ($application->person !== null) {
                $node->appendChild($this->osoba($document, $namespace, 'osetrovanaOsoba', $application->person));
            }
            if ($application->relationshipCode !== null) {
                $this->text($document, $namespace, $node, 'kodVztah', $application->relationshipCode);
            }
            $this->optionalBool($document, $namespace, $node, 'jeStridani', $application->alternation);
            $this->optionalBool($document, $namespace, $node, 'narokNaPPMjinouOsobou', $application->otherMaternityClaim);
            if ($application->otherMaternityClaim === true) {
                $this->optionalBool($document, $namespace, $node, 'jinaFOParagraf57', $application->otherPersonS57);
            }
            $this->optionalBool($document, $namespace, $node, 'spolecnaDomacnost', $application->sharedHousehold);
        }
        if ($duration) {
            $this->optionalBool($document, $namespace, $node, 'pecovalOsobne', $application->caredPersonally);
            $this->periods($document, $namespace, $node, 'pecovalVeDnech', $application->careDays);
        }

        return $node;
    }

    /**
     * Podklady pro výplatu ošetřovného: jen při trvání nebo ukončení.
     * `pracovalPoslDenPD` a hodiny posledního dne patří jen k ukončení.
     */
    private function podkladyOse(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): ?DOMElement {
        if (!$application->carriesDuration()) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'podkladyProVyplatDavky');
        if ($application->actionEnd) {
            $this->optionalBool($document, $namespace, $node, 'pracovalPoslDenPD', $application->workedLastDay);
            // Hodiny posledního dne jsou bez `pracovalPoslDenPD = true` zakázané.
            if ($application->workedLastDay === true) {
                $this->lastDayHours($document, $namespace, $node, $application);
            }
        }
        $this->optionalBool($document, $namespace, $node, 'planovaneSmeny', $application->plannedShifts);
        if ($application->plannedShifts === true) {
            $this->optionalBool($document, $namespace, $node, 'planovaneSmenyOdpracoval', $application->plannedShiftsWorked);
        }
        $this->periods($document, $namespace, $node, 'seznamPraceVeDnech', $application->workDays);

        return $node->hasChildNodes() ? $node : null;
    }

    /**
     * Podklady pro výplatu DLO (pořadí podle XSD): jen při trvání nebo
     * ukončení; hodiny posledního dne a návrat do práce jen při ukončení.
     */
    private function podkladyDlo(
        DOMDocument $document,
        string $namespace,
        NempriBenefitApplication $application,
    ): ?DOMElement {
        if (!$application->carriesDuration()) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'podkladyProVyplatDavky');
        if ($application->actionEnd) {
            $this->lastDayHours($document, $namespace, $node, $application);
            if ($application->returnedOn !== null) {
                $this->text($document, $namespace, $node, 'datumNavratDoPrace', $application->returnedOn);
            }
        }
        $this->optionalBool($document, $namespace, $node, 'planovaneSmeny', $application->plannedShifts);
        if ($application->plannedShifts === true) {
            $this->periods($document, $namespace, $node, 'seznamRozvrhuSmen', $application->shiftSchedule);
        }
        $this->periods($document, $namespace, $node, 'seznamPraceVeDnech', $application->workDays);
        $this->optionalBool($document, $namespace, $node, 'maVolno', $application->hasLeave);
        if ($application->hasLeave === true) {
            $this->periods($document, $namespace, $node, 'pracovniVolno', $application->leavePeriods);
        }

        return $node->hasChildNodes() ? $node : null;
    }

    private function lastDayHours(
        DOMDocument $document,
        string $namespace,
        DOMElement $node,
        NempriBenefitApplication $application,
    ): void {
        if ($application->shiftHoursLastDay !== null) {
            $this->text($document, $namespace, $node, 'pracovniDobaPoslDenPD', $application->shiftHoursLastDay);
        }
        if ($application->hoursWorkedLastDay !== null) {
            $this->text($document, $namespace, $node, 'pocetOdpracHodinPoslDenPD', $application->hoursWorkedLastDay);
        }
    }

    private function osoba(
        DOMDocument $document,
        string $namespace,
        string $name,
        NempriPerson $person,
    ): DOMElement {
        $node = $document->createElementNS($namespace, $name);
        $this->text($document, $namespace, $node, 'jmeno', $person->firstName);
        $this->text($document, $namespace, $node, 'prijmeni', $person->lastName);
        if ($person->birthNumber !== null) {
            $this->text($document, $namespace, $node, 'rodneCislo', $person->birthNumber);
        }
        if ($person->birthDate !== null) {
            $this->text($document, $namespace, $node, 'datumNarozeni', $person->birthDate);
        }

        return $node;
    }

    /** @param list<array{from:string,to:string}> $periods */
    private function periods(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        array $periods,
    ): void {
        if ($periods === []) {
            return;
        }
        $node = $document->createElementNS($namespace, $name);
        foreach ($periods as $period) {
            $item = $document->createElementNS($namespace, 'obdobi');
            $this->text($document, $namespace, $item, 'od', $period['from']);
            $this->text($document, $namespace, $item, 'do', $period['to']);
            $node->appendChild($item);
        }
        $parent->appendChild($node);
    }

    private function platebniSpojeni(
        DOMDocument $document,
        string $namespace,
        NempriPaymentConnection $connection,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'platebniSpojeni');
        // Referenční podání nesou všechny čtyři volby, ostatní jako „false“.
        $this->bool($document, $namespace, $node, 'vyplatitUcetCR', $connection->kind === NempriPaymentConnection::KIND_ACCOUNT_CZ);
        $this->bool($document, $namespace, $node, 'vyplatitUcetCizina', $connection->kind === NempriPaymentConnection::KIND_ACCOUNT_FOREIGN);
        $this->bool($document, $namespace, $node, 'vyplatitAdresa', $connection->kind === NempriPaymentConnection::KIND_ADDRESS);
        $this->bool($document, $namespace, $node, 'vyplatitHotovost', $connection->kind === NempriPaymentConnection::KIND_CASH);
        switch ($connection->kind) {
            case NempriPaymentConnection::KIND_ACCOUNT_CZ:
                $account = $document->createElementNS($namespace, 'ucetCZ');
                if ($connection->accountPrefix !== null) {
                    $this->text($document, $namespace, $account, 'predcisli', $connection->accountPrefix);
                }
                $this->text($document, $namespace, $account, 'ucetCislo', (string) $connection->accountNumber);
                $this->text($document, $namespace, $account, 'bankaKod', (string) $connection->bankCode);
                $node->appendChild($account);
                break;
            case NempriPaymentConnection::KIND_ACCOUNT_FOREIGN:
                $account = $document->createElementNS($namespace, 'ucetZahranicni');
                $this->text($document, $namespace, $account, 'stat', (string) $connection->countryCode);
                $this->text($document, $namespace, $account, 'IBAN', (string) $connection->iban);
                $node->appendChild($account);
                break;
            case NempriPaymentConnection::KIND_ADDRESS:
                $address = $document->createElementNS($namespace, 'adresa');
                $this->text($document, $namespace, $address, 'obec', (string) $connection->city);
                if ($connection->street !== null) {
                    $this->text($document, $namespace, $address, 'ulice', $connection->street);
                }
                $this->text($document, $namespace, $address, 'cisloPopis', (string) $connection->houseNumber);
                if ($connection->orientationNumber !== null) {
                    $this->text($document, $namespace, $address, 'cisloOrient', $connection->orientationNumber);
                }
                $this->text($document, $namespace, $address, 'psc', (string) $connection->postalCode);
                $node->appendChild($address);
                break;
        }

        return $node;
    }

    /**
     * `CtKontaktniPracovnik` rozšiřuje `CtKontakt`, takže `telefon` a `email`
     * jdou před jménem pracovníka, ne za ním.
     */
    private function kontaktPracovnik(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): ?DOMElement {
        if ($payload->contactWorkerName === null
            && $payload->contactWorkerPhone === null
            && $payload->contactWorkerEmail === null
        ) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'kontaktPracovnik');
        if ($payload->contactWorkerPhone !== null) {
            $this->text($document, $namespace, $node, 'telefon', $payload->contactWorkerPhone);
        }
        if ($payload->contactWorkerEmail !== null) {
            $this->text($document, $namespace, $node, 'email', $payload->contactWorkerEmail);
        }
        if ($payload->contactWorkerName !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'kontaktniPracovnik',
                $payload->contactWorkerName,
            );
        }

        return $node;
    }

    private function optionalBool(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        ?bool $value,
    ): void {
        if ($value !== null) {
            $this->bool($document, $namespace, $parent, $name, $value);
        }
    }

    private function bool(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        bool $value,
    ): void {
        $this->text($document, $namespace, $parent, $name, $value ? 'true' : 'false');
    }

    private function text(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        string $value,
    ): void {
        $element = $document->createElementNS($namespace, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
