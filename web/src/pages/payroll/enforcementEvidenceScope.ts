import type {
  EnforcementCaseStatus,
  EnforcementCaseSummary,
  EnforcementDependant,
  EnforcementEvidenceScope,
  EnforcementEvidenceSourceValue,
  EnforcementMonthEvidence,
} from '@/api/payrollEnforcement'

/**
 * Kdy má která z měsíčních exekučních evidencí co dokládat.
 *
 * Zrcadlí `GarnishmentCalculator::evidenceScope()` — obrazovka o rozsahu nic
 * nerozhoduje, jen ukazuje totéž pravidlo, aby nepobízela k vyplnění potvrzení,
 * které stejně nic nedokládá. Nejsou to tři stejné případy:
 *
 *  • **rejstřík pohledávek** rozhoduje, komu a v jakém pořadí srážka připadne.
 *    Bez aktivní pohledávky a bez insolvence není co rozdělovat;
 *  • **vyživované osoby** a **manžel/ka** zvedají nezabavitelnou částku
 *    (§ 278 OSŘ). Neuplatněný nárok ji neposouvá a při souběhu plátců ji
 *    stejně určuje soud — v obou případech není co dokládat. Uplatněný
 *    a nedoložený nárok v měsíci BEZ srážky je ale `nothing_withheld`, ne
 *    „netřeba": nezabavitelná částka drží i strop dobrovolné dohody o srážkách
 *    (§ 148 odst. 2 zákoníku práce), takže dokud ho nikdo nedoloží, dohoda
 *    z něj nesmí čerpat. Ten checkbox tedy zůstává k vyplnění.
 *
 * Vstupy, které backend čte z databáze jedním dotazem, tady skládáme ze tří
 * zdrojů obrazovky (případy osoby, vyživované osoby, měsíční evidence). Kde se
 * přesnost rozchází, míří odchylka VŽDY k „je co dokládat":
 *
 *  • backend filtruje případy i vyživované osoby datem výplaty konkrétního
 *    běhu, obrazovka zná jen období — bere se proto celý měsíc, což je
 *    nadmnožina každého data výplaty v něm;
 *  • `outstanding_minor_units` v přehledu případu je součet aktivních
 *    pohledávek před odečtem už sražených částek, takže je ≥ zůstatku, se
 *    kterým počítá výpočet;
 *  • neúplný nebo nenačtený seznam případů (`casesComplete === false`) se bere
 *    jako „je co dokládat".
 *
 * Zešedne tak jen to, o čem obrazovka bezpečně ví, že nedokládá nic.
 */

/** Stavy, ve kterých případ vůbec vstupuje do výpočtu srážky. */
const WITHHOLDING_STATUSES: ReadonlySet<EnforcementCaseStatus> = new Set<EnforcementCaseStatus>([
  'withhold_and_hold',
  'remit',
  'deferred_hold',
])

export interface EvidenceScopeInput {
  /** Období měsíční evidence ve tvaru `YYYY-MM`. */
  period: string
  /** Případy TÉTO osoby — ne aktuální stránka seznamu, ta je filtrovaná. */
  cases: EnforcementCaseSummary[]
  /** `false` = seznam se nenačetl celý; rozsah se pak nezužuje. */
  casesComplete: boolean
  dependants: EnforcementDependant[]
  evidence: EnforcementMonthEvidence
}

/** Období jako uzavřený interval dnů; `null` u nesmyslného vstupu. */
function periodWindow(period: string): { start: string; end: string } | null {
  const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(period)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  // Nultý den následujícího měsíce = poslední den tohoto (UTC kvůli přestupným rokům).
  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate()
  return { start: `${period}-01`, end: `${period}-${String(lastDay).padStart(2, '0')}` }
}

/** Překrývá se platnost `[from, to]` (otevřená doprava při `null`) s obdobím? */
function overlapsPeriod(
  from: string,
  to: string | null,
  window: { start: string; end: string },
): boolean {
  return from <= window.end && (to === null || to >= window.start)
}

/**
 * Vzniká v tomto měsíci vůbec exekuční srážka? Zrcadlí `$withholdingArises`:
 * aspoň jedna aktivní pohledávka, nebo insolvenční režim jiný než `none`.
 */
export function withholdingArises(input: EvidenceScopeInput): boolean {
  if (input.evidence.insolvency_mode !== 'none') return true
  if (!input.casesComplete) return true
  const window = periodWindow(input.period)
  if (window === null) return true

  return input.cases.some(item =>
    WITHHOLDING_STATUSES.has(item.status)
    && item.outstanding_minor_units > 0
    && overlapsPeriod(item.effective_from, item.effective_to, window))
}

/**
 * Uplatněné nároky na zvýšení nezabavitelné částky. Zrcadlí sestavení
 * `eligibleDependants` / `eligibleSpouse` v `PayrollEnforcementRepository`:
 * počítá se jen ověřený nárok, který není vyloučený kvůli výživnému.
 */
export function eligibleAllowances(
  dependants: EnforcementDependant[],
  period: string,
): { dependants: number; spouse: boolean } {
  const window = periodWindow(period)
  let count = 0
  let spouse = false
  for (const dependant of dependants) {
    if (!dependant.eligibility_verified || dependant.excluded_for_maintenance) continue
    if (window !== null
      && !overlapsPeriod(dependant.valid_from, dependant.valid_to, window)) continue
    if (dependant.dependant_kind === 'spouse_partner') spouse = true
    else count += 1
  }

  return { dependants: count, spouse }
}

/**
 * Má některý uplatněný manžel/partner NEDOPLNĚNÉ doložení důchodu?
 *
 * Zrcadlí `PayrollEnforcementRepository::weakerSpousePension()`: rozhoduje
 * NEJSLABŠÍ stav napříč uplatněnými záznamy, takže jediné `unknown` stačí.
 * `not_documented` je naopak řádný a úplný stav evidence — povinný důkazní
 * břemeno neunesl, čtvrtina nenáleží a dokládat není co.
 */
export function spousePensionEvidenceUnknown(
  dependants: EnforcementDependant[],
  period: string,
): boolean {
  const window = periodWindow(period)

  return dependants.some(dependant =>
    dependant.dependant_kind === 'spouse_partner'
    && dependant.eligibility_verified
    && !dependant.excluded_for_maintenance
    && (window === null || overlapsPeriod(dependant.valid_from, dependant.valid_to, window))
    && dependant.quarter_pension_evidence === 'unknown')
}

export function evidenceScope(input: EvidenceScopeInput): EnforcementEvidenceScope {
  const arises = withholdingArises(input)
  const allowances = eligibleAllowances(input.dependants, input.period)
  const allowanceScope = (
    claimed: boolean,
    declared: boolean,
  ): EnforcementEvidenceSourceValue => {
    if (!claimed || input.evidence.has_multiple_payers) return 'not_applicable'
    if (declared) return 'declared'
    return arises ? 'missing' : 'nothing_withheld'
  }

  return {
    claim_register: input.evidence.claim_register_evidence_complete
      ? 'declared'
      : (arises ? 'missing' : 'not_applicable'),
    dependants: allowanceScope(
      allowances.dependants > 0,
      input.evidence.dependants_evidence_complete,
    ),
    // Zaškrtnutá měsíční evidence sama o sobě od 1. 1. 2025 nestačí: bez
    // doloženého (nebo výslovně nedoloženého) důchodu manžela počítá backend
    // rozsah jako `missing` — viz `GarnishmentCalculator::evidenceScope()`.
    // Kdyby obrazovka pořád ukazovala `declared`, tvrdila by, že je hotovo,
    // a účetní by se o chybějícím podkladu dozvěděla až z běhu mezd.
    spouse: allowanceScope(
      allowances.spouse,
      input.evidence.spouse_evidence_complete
        && !spousePensionEvidenceUnknown(input.dependants, input.period),
    ),
  }
}

/**
 * Nezabavitelná částka stojí na nároku, který nikdo nedoložil — dobrovolná
 * dohoda o srážkách z ní tedy nesmí čerpat. Zrcadlí
 * `EnforcementEvidenceScope::protectedAmountIsUnattested()`.
 */
export function protectedAmountIsUnattested(scope: EnforcementEvidenceScope): boolean {
  return scope.dependants === 'nothing_withheld' || scope.spouse === 'nothing_withheld'
}
