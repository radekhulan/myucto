import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollStatutoryEvidence, PayrollStatutoryEvidenceRow } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  statutoryEvidence: vi.fn(),
  saveStatutoryEvidence: vi.fn(),
  employerSettings: vi.fn(),
  commandRun: vi.fn(),
  canWrite: vi.fn(() => true),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    statutoryEvidence: mocks.statutoryEvidence,
    saveStatutoryEvidence: mocks.saveStatutoryEvidence,
    employerSettings: mocks.employerSettings,
    commandRun: mocks.commandRun,
  },
}))

// Sdělení pojišťovny zaměstnancem (§ 12 písm. b) zákona č. 48/1997 Sb.) má vlastní
// API a vlastní spec; tady se jen zabrání skutečnému síťovému volání.
vi.mock('@/api/payrollHealthInsurerNotices', () => ({
  payrollHealthInsurerNoticesApi: {
    list: () => Promise.resolve([]),
    record: vi.fn(),
    confirmationUrl: () => '/payroll/people/0/health-insurer-notices/0/confirmation',
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: mocks.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

vi.mock('@/composables/useCountries', () => ({
  loadCountries: () => Promise.resolve([
    { iso2: 'CZ', iso3: 'CZE', name_cs: 'Česko', name_en: 'Czechia', is_eu: true },
    { iso2: 'SK', iso3: 'SVK', name_cs: 'Slovensko', name_en: 'Slovakia', is_eu: true },
  ]),
}))

// `useFormat` (sdílené formátování dat) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params === undefined ? key : `${key}:${JSON.stringify(params)}`,
    locale: { value: 'cs' },
  }),
}))

import { defineComponent, h } from 'vue'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import { providePersonCardSave, type PersonCardSaveRegistry } from '@/pages/payroll/personCardSave'
import { resetDefaultHealthInsurerCode } from '@/composables/usePayrollDefaultInsurer'
import PayrollPersonStatutoryEvidencePanel from '@/pages/payroll/PayrollPersonStatutoryEvidencePanel.vue'

/**
 * Zrcadlo pravidel `PayrollPersonStatutoryEvidenceValidator`u pro řádky, které
 * formulář vyrábí sám. Nejde o „ještě jednu validaci" — jde o kontrolu, že
 * předvyplněný český případ projde serverem NAPOPRVÉ. Kdyby se pravidla
 * rozešla, tenhle test padne dřív, než uživatel dostane hlášku od serveru.
 */
function validatorRejection(
  section: string,
  row: PayrollStatutoryEvidenceRow,
  effectiveOn = '2026-08-31',
): string | null {
  const value = (key: string) => {
    const raw = row[key]
    return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null
  }
  const canonical = /^[A-Za-z0-9][A-Za-z0-9_.:/-]*$/
  for (const [key, raw] of Object.entries(row)) {
    if (key.includes('reference') && typeof raw === 'string' && raw !== ''
      && !canonical.test(raw)) {
      return `Pole ${key} není kanonická reference.`
    }
  }

  if (section === 'tax_declarations') {
    const verified = value('status') !== 'unverified'
    if (!verified && value('evidence_reference') !== null) return 'Neověřená evidence nesmí nést důkaz.'
  }
  if (section === 'tax_residences') {
    const residence = value('residence')
    const country = value('country_code')
    const evidence = value('evidence_reference')
    if (residence === 'czech-resident' && country !== 'CZ') {
      return 'Česká daňová rezidence vyžaduje CZ.'
    }
    if (residence === 'non-resident' && (country === null || country === 'CZ')) {
      return 'Daňový nerezident vyžaduje zahraniční zemi.'
    }
    if (residence === 'unverified' && (country !== null || evidence !== null)) {
      return 'Neověřená daňová rezidence nesmí nést ověřené údaje.'
    }
  }
  if (section === 'social_jurisdictions') {
    const jurisdiction = value('jurisdiction')
    const country = value('foreign_country_code')
    const jurisdictionEvidence = value('jurisdiction_evidence_reference')
    if (jurisdiction === 'foreign_regime_verified') {
      if (country === null) {
        return 'Ověřená zahraniční sociální jurisdikce vyžaduje zemi.'
      }
    } else if (country !== null || jurisdictionEvidence !== null) {
      return 'Česká nebo neověřená sociální jurisdikce nesmí nést zahraniční důkaz.'
    }
    const a1 = value('a1_status')
    if (a1 === null) return 'Pole a1_status musí být neprázdný text.'
    const reference = value('a1_certificate_reference')
    const until = value('a1_valid_until')
    if (a1 === 'verified' && (until === null || until < effectiveOn)) {
      return 'Ověřený A1 musí platit k datu snímku.'
    }
    if (a1 !== 'verified' && (reference !== null || until !== null)) {
      return 'Neověřený nebo nepoužitelný A1 nesmí nést ověřené údaje.'
    }
    if (jurisdiction === 'czech_regime_verified' && a1 !== 'not_applicable') {
      return 'Česká sociální jurisdikce musí mít A1 označený jako nepoužitelný.'
    }
  }
  if (section === 'tax_credit_claims') {
    const kind = value('credit_kind')
    if (kind === null
      || !['taxpayer', 'disability-basic', 'disability-extended', 'ztp-p', 'student', 'student-doctoral'].includes(kind)) {
      return 'Druh slevy musí být z číselníku.'
    }
    const verified = value('evidence_status') === 'verified'
    if (!verified && value('evidence_reference') !== null) return 'Neověřená evidence nesmí nést důkaz.'
  }
  if (section === 'social_discount_claims') {
    const verified = value('status') === 'verified'
    if (!verified && value('evidence_reference') !== null) return 'Neověřená evidence nesmí nést důkaz.'
  }
  if (section === 'health_coverages') {
    const jurisdiction = value('jurisdiction')
    const country = value('foreign_country_code')
    const jurisdictionEvidence = value('jurisdiction_evidence_reference')
    if (jurisdiction === 'foreign_regime_verified') {
      if (country === null) {
        return 'Ověřená zahraniční zdravotní jurisdikce vyžaduje zemi.'
      }
    } else if (country !== null || jurisdictionEvidence !== null) {
      return 'Česká nebo neověřená zdravotní jurisdikce nesmí nést zahraniční důkaz.'
    }
    const status = value('insurer_status')
    const code = value('insurer_code')
    const evidence = value('insurer_evidence_reference')
    if (code !== null && !['111', '201', '205', '207', '209', '211', '213'].includes(code)) {
      return `Kód zdravotní pojišťovny ${code} neexistuje.`
    }
    if (status === 'verified' && code === null) {
      return 'Ověřená zdravotní pojišťovna vyžaduje kód.'
    }
    if (status === 'not_applicable' && (code !== null || evidence !== null)) {
      return 'Nepoužitelná česká zdravotní pojišťovna nesmí nést kód ani důkaz.'
    }
    if (status === 'unverified' && evidence !== null) {
      return 'Neověřená zdravotní pojišťovna nesmí nést ověřený důkaz.'
    }
    if (jurisdiction === 'czech_regime_verified' && status === 'not_applicable') {
      return 'Ověřená česká zdravotní jurisdikce nemůže mít pojišťovnu označenou jako nepoužitelnou.'
    }
  }
  if (section === 'health_month_evidence') {
    const responsibility = value('top_up_responsibility')
    const evidence = value('top_up_responsibility_evidence_reference')
    if (responsibility !== 'employer_obstacle_verified' && evidence !== null) {
      return 'Neověřená evidence nesmí nést důkaz.'
    }
    const selected = value('selected_top_up_employer_reference')
    const selectedEvidence = value('selected_top_up_employer_evidence_reference')
    if (selected === null && selectedEvidence !== null) {
      return 'Doklad k volbě zaměstnavatele vyžaduje zvoleného zaměstnavatele.'
    }
  }
  if (section === 'health_minimum_reductions') {
    const reason = value('reason')
    if (reason === null || ![
      'state_insured', 'ztp_or_ztp_p', 'pension_age_without_pension',
      'sickness_care_or_quarantine', 'osvc_minimum_advance', 'foster_reward_only',
      'child_under_7_care', 'unverified',
    ].includes(reason)) {
      return 'Pole reason musí být z číselníku.'
    }
    if (reason === 'unverified' && value('evidence_reference') !== null) {
      return 'Neověřená evidence redukce minima nesmí nést důkaz.'
    }
  }
  if (section === 'health_other_employer_bases') {
    const reference = value('employer_reference')
    if (reference === null || !canonical.test(reference)) {
      return 'Pole employer_reference musí být kanonická reference.'
    }
    const base = value('assessment_base_minor_units')
    if (base === null || !/^\d+$/.test(base)) {
      return 'Pole assessment_base_minor_units musí být celé číslo.'
    }
    const from = value('employment_from')
    const to = value('employment_to')
    if (from === null) return 'Pole employment_from musí být datum.'
    if (to !== null && to < from) {
      return 'Konec vztahu u jiného zaměstnavatele předchází jeho začátku.'
    }
  }

  return null
}

function emptyEvidence(overrides: Partial<PayrollStatutoryEvidence> = {}): PayrollStatutoryEvidence {
  return {
    employee_id: 17,
    effective_on: '2026-08-31',
    frozen_through: null,
    frozen_runs: [],
    sections: {
      tax_declarations: [],
      tax_residences: [],
      tax_credit_claims: [],
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
      health_minimum_reductions: [],
      health_other_employer_bases: [],
      social_pension_age: [],
      social_pensions: [],
    },
    other_employer_bases: [],
    blockers: [
      'tax_declaration_evidence_missing',
      'tax_residence_evidence_missing',
      'social_jurisdiction_evidence_missing',
      'health_coverage_evidence_missing',
    ],
    ...overrides,
  }
}

function filledEvidence(): PayrollStatutoryEvidence {
  return emptyEvidence({
    frozen_through: '2026-04-30',
    blockers: [],
    sections: {
      tax_declarations: [{
        id: 5,
        row_version: 2,
        status: 'signed',
        evidence_reference: 'declaration:38k-signed',
        evidence_note: 'Papír ve složce',
        effective_from: '2026-01-01',
        effective_to: null,
      }],
      tax_residences: [],
      tax_credit_claims: [],
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
      health_minimum_reductions: [],
      health_other_employer_bases: [],
      social_pension_age: [],
      social_pensions: [],
    },
  })
}

async function mounted(canWrite = true, attach = false) {
  const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
    props: { personId: 17, canWrite },
    ...(attach ? { attachTo: document.body } : {}),
  })
  await flushPromises()
  return wrapper
}

async function startEditing(canWrite = true) {
  const wrapper = await mounted(canWrite)
  await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
  return wrapper
}

/** Panel se otevírá k aktuálnímu měsíci; měsíční řádky mu musí odpovídat. */
function currentMonthStart(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

/** Poslední tělo poslané na server; test si z něj vezme jeden řádek sekce. */
function savedRow(section: string, index = 0): PayrollStatutoryEvidenceRow {
  const [, payload] = mocks.saveStatutoryEvidence.mock.calls[0]!
  return payload.sections[section][index]
}

afterEach(() => {
  vi.useRealTimers()
})

describe('PayrollPersonStatutoryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    resetDefaultHealthInsurerCode()
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    mocks.saveStatutoryEvidence.mockResolvedValue(filledEvidence())
    mocks.employerSettings.mockResolvedValue({ default_health_insurer_code: '205' })
  })

  it('u prázdné evidence pojmenuje chybějící údaje a odliší neuplatněné slevy a výjimky', async () => {
    const wrapper = await mounted()

    const blockers = wrapper.get('[data-test="statutory-evidence-blockers"]')
    expect(blockers.findAll('li')).toHaveLength(4)
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blocker.tax_declaration_evidence_missing',
    )
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blockers_consequence',
    )
    expect(wrapper.find('[data-test="statutory-evidence-complete"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="current-tax_credit_claims"]').text())
      .toContain('payroll.people.statutory_evidence.current_none_claimed')
    expect(wrapper.get('[data-test="history-tax_credit_claims"]').attributes('open'))
      .toBeUndefined()
    expect(wrapper.get('[data-test="current-health_minimum_reductions"]').text())
      .toContain('payroll.people.statutory_evidence.current_no_exemption')
    expect(wrapper.get('[data-test="history-health_minimum_reductions"]').attributes('open'))
      .toBeUndefined()
    // Nápověda vysvětluje, které výjimky existují a co výpočet odvodí sám.
    expect(wrapper.get('[data-test="section-health_minimum_reductions"]').text())
      .toContain('payroll.people.statutory_evidence.section_hint.health_minimum_reductions')
    expect(wrapper.get('[data-test="current-social_discount_claims"]').text())
      .toContain('payroll.people.statutory_evidence.current_discount_not_claimed')
    expect(wrapper.get('[data-test="current-social_discount_claims"]').text())
      .not.toContain('payroll.people.statutory_evidence.current_missing')
  })

  it('má jediné společné Uložit, žádné tlačítko na jednotlivý záznam', async () => {
    const wrapper = await startEditing()

    expect(wrapper.findAll('[data-test="statutory-evidence-save"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test^="save-"]')).toHaveLength(0)
  })

  it('bez práva zápisu nenabídne ani úpravu, ani uložení', async () => {
    const wrapper = await mounted(false)

    expect(wrapper.find('[data-test="start-statutory-evidence"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="statutory-evidence-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="edit-tax_declarations"]').exists()).toBe(false)
  })

  /**
   * Regrese: „Upravit evidenci" jen přepínalo `editing`, jenže pole leží uvnitř
   * sbalené historie — u vyplněné sekce se po kliknutí nestalo nic viditelného.
   */
  it('Upravit evidenci rozbalí sekce, ne jen vymění tlačítka dole', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    // Vyplněná a neblokující sekce je při čtení sbalená…
    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open'))
      .toBeUndefined()

    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')

    // …a po vstupu do editace je vidět, do čeho se píše.
    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open')).toBeDefined()
    expect(wrapper.get('[data-test="history-health_coverages"]').attributes('open')).toBeDefined()
    expect(wrapper.get('[data-test="tax_declarations-0-effective_to"]').attributes('disabled'))
      .toBeUndefined()
    expect(wrapper.find('[data-test="add-tax_declarations"]').exists()).toBe(true)
  })

  it('Upravit u sekce otevře právě ji a postaví kurzor do pole', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted(true, true)

    await wrapper.get('[data-test="edit-tax_declarations"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open')).toBeDefined()
    expect(wrapper.find('[data-test="statutory-evidence-save"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="section-tax_declarations"]').element
      .contains(document.activeElement)).toBe(true)
    // V editaci už tlačítko u sekce nepřekáží…
    expect(wrapper.find('[data-test="edit-tax_declarations"]').exists()).toBe(false)
    // …a ostatní sekce zůstávají zamčené: „Upravit" otevírá jen tu jednu.
    expect(wrapper.find('[data-test="add-first-tax_residences"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="inline-bar-tax_residences"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('změna otevřená ze sekce se uloží jedním společným tlačítkem dole', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    await wrapper.get('[data-test="add-first-tax_residences"]').trigger('click')

    expect(wrapper.findAll('[data-test="statutory-evidence-save"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test^="save-"]')).toHaveLength(0)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    expect(savedRow('tax_residences')).toMatchObject({ residence: 'czech-resident' })
    expect(mocks.success).toHaveBeenCalled()
    // UI-24: karta osoby po uložení přepočítá bannery chybějících údajů.
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('otevřená sekce s uzavřenou historií dál drží pravidlo o nové verzi', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    await wrapper.get('[data-test="edit-tax_declarations"]').trigger('click')

    expect(wrapper.get('[data-test="tax_declarations-0-effective_from"]').attributes('disabled'))
      .toBeDefined()
    expect(wrapper.find('[data-test="remove-tax_declarations-0"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="change-from-tax_declarations"]').exists()).toBe(true)
  })

  it('Zrušit vrátí sekce zpět do sbaleného stavu', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open')).toBeDefined()

    const buttons = wrapper.findAll('button')
    await buttons.find(button => button.text().includes('common.cancel'))!.trigger('click')

    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open'))
      .toBeUndefined()
    expect(wrapper.find('[data-test="start-statutory-evidence"]').exists()).toBe(true)
  })

  it('nový záznam běžného českého případu je rovnou platný podle pravidel serveru', async () => {
    const wrapper = await startEditing()

    for (const section of [
      'tax_declarations',
      'tax_residences',
      'tax_credit_claims',
      'social_jurisdictions',
      'social_discount_claims',
      'health_coverages',
      'health_month_evidence',
      'health_minimum_reductions',
    ]) {
      await wrapper.get(`[data-test="add-${section}"]`).trigger('click')
    }
    // Žádný záznam nesmí hlásit chybu — jinak by uživatel musel dovyplňovat.
    expect(wrapper.findAll('[data-test^="issues-"]')).toHaveLength(0)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    const [, payload] = mocks.saveStatutoryEvidence.mock.calls[0]!
    for (const [section, rows] of Object.entries(payload.sections)) {
      for (const row of rows as PayrollStatutoryEvidenceRow[]) {
        expect(validatorRejection(section, row)).toBeNull()
      }
    }
    expect(mocks.success).toHaveBeenCalled()
  })

  it('předvyplní českého rezidenta, český režim, A1 „netýká se“ a pojišťovnu zaměstnavatele', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_residences"]').trigger('click')
    await wrapper.get('[data-test="add-social_jurisdictions"]').trigger('click')
    await wrapper.get('[data-test="add-health_coverages"]').trigger('click')

    // Stát u českého rezidenta se neptá — plyne z volby rezidence.
    expect(wrapper.find('[data-test="tax_residences-0-country_code"]').exists()).toBe(false)
    // U českého sociálního režimu nemá A1 co dělat na obrazovce.
    expect(wrapper.find('[data-test="social_jurisdictions-0-a1_status"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="social_jurisdictions-0-foreign_country_code"]').exists())
      .toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(savedRow('tax_residences')).toMatchObject({
      residence: 'czech-resident',
      country_code: 'CZ',
      evidence_reference: null,
    })
    expect(savedRow('social_jurisdictions')).toMatchObject({
      jurisdiction: 'czech_regime_verified',
      foreign_country_code: null,
      jurisdiction_evidence_reference: null,
      a1_status: 'not_applicable',
      a1_certificate_reference: null,
      a1_valid_until: null,
    })
    expect(savedRow('health_coverages')).toMatchObject({
      jurisdiction: 'czech_regime_verified',
      insurer_status: 'verified',
      insurer_code: '205',
      insurer_evidence_reference: null,
    })
  })

  /**
   * Peněžní regrese: bez zaevidované slevy na poplatníka platí zaměstnanec
   * s podepsaným prohlášením o 2 570 Kč měsíčně vyšší zálohu. „Přidat záznam"
   * proto musí nabídnout rovnou slevu na poplatníka jako doloženou.
   */
  it('nová sleva je rovnou sleva na poplatníka a odejde na server doložená', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_credit_claims"]').trigger('click')

    expect(wrapper.findAll('[data-test^="issues-tax_credit_claims"]')).toHaveLength(0)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const row = savedRow('tax_credit_claims')
    expect(row).toMatchObject({
      credit_kind: 'taxpayer',
      evidence_status: 'verified',
      evidence_reference: null,
    })
    expect(validatorRejection('tax_credit_claims', row)).toBeNull()
  })

  /**
   * Slevy jsou souběžné řady po druzích: sleva na poplatníka i na ZTP/P běží
   * současně a otevřené jsou obě právem. Pravidlo „jen jeden otevřený záznam"
   * proto nesmí platit přes celou sekci, jinak by souběh nešlo uložit.
   */
  it('dvě slevy různého druhu můžou platit současně', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      sections: {
        ...emptyEvidence().sections,
        tax_credit_claims: [{
          id: 8, row_version: 1, credit_kind: 'taxpayer', evidence_status: 'verified',
          evidence_reference: null, evidence_note: null,
          effective_from: currentMonthStart(), effective_to: null,
        }],
      },
    }))
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_credit_claims"]').trigger('click')

    // Táž dvojice ve stejném druhu je chyba — tu pravidlo dál hlídá.
    expect(wrapper.get('[data-test="issues-tax_credit_claims"]').text())
      .toContain('payroll.people.statutory_evidence.issue.multiple_open_rows')

    await wrapper.get('[data-test="tax_credit_claims-1-credit_kind"]').setValue('ztp-p')
    expect(wrapper.find('[data-test="issues-tax_credit_claims"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    expect(savedRow('tax_credit_claims', 0)).toMatchObject({ credit_kind: 'taxpayer' })
    expect(savedRow('tax_credit_claims', 1)).toMatchObject({ credit_kind: 'ztp-p' })
  })

  it('podepsané prohlášení ukáže slevu na poplatníka i bez řádku nároku (UI-14)', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      derived: { taxpayer_credit: true },
    }))
    const wrapper = await mounted()

    const current = wrapper.get('[data-test="current-tax_credit_claims"]').text()
    expect(current).toContain('payroll.people.statutory_evidence.derived_taxpayer_credit')
    expect(current).not.toContain('payroll.people.statutory_evidence.current_none_claimed')
  })

  it('pojišťovnu vezme přednostně z historie osoby, ne z nastavení zaměstnavatele', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      sections: {
        ...emptyEvidence().sections,
        health_coverages: [{
          id: 9,
          row_version: 1,
          jurisdiction: 'czech_regime_verified',
          foreign_country_code: null,
          jurisdiction_evidence_reference: null,
          insurer_status: 'verified',
          insurer_code: '211',
          insurer_evidence_reference: 'health:insurer-registration',
          evidence_note: null,
          effective_from: '2025-01-01',
          effective_to: '2025-12-31',
        }],
      },
    }))
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_coverages"]').trigger('click')

    expect(
      (wrapper.get('[data-test="health_coverages-1-insurer_code"]').element as HTMLSelectElement).value,
    ).toBe('211')
  })

  it('přepnutí na cizí sociální režim vyžádá stát, ale odkaz nechá volitelný', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-social_jurisdictions"]').trigger('click')
    await wrapper.get('[data-test="social_jurisdictions-0-jurisdiction"]')
      .setValue('foreign_regime_verified')

    expect(wrapper.find('[data-test="social_jurisdictions-0-foreign_country_code"]').exists())
      .toBe(true)
    expect(wrapper.find('[data-test="social_jurisdictions-0-a1_status"]').exists()).toBe(true)
    // Odkaz k režimu se nesmí domýšlet; povinný je jen skutečný stát.
    expect(
      (wrapper.get('[data-test="social_jurisdictions-0-jurisdiction_evidence_reference-reason"]')
        .element as HTMLSelectElement).value,
    ).toBe('')
    expect(wrapper.get('[data-test="issues-social_jurisdictions-0"]').text())
      .toContain('payroll.people.statutory_evidence.issue.country_required')

    await wrapper.findAllComponents(CountrySelect)[0]!.vm.$emit('update:modelValue', 'SK')
    await flushPromises()
    expect(wrapper.find('[data-test="issues-social_jurisdictions-0"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const row = savedRow('social_jurisdictions')
    expect(row).toMatchObject({
      jurisdiction: 'foreign_regime_verified',
      foreign_country_code: 'SK',
      jurisdiction_evidence_reference: null,
      a1_status: 'not_applicable',
    })
    expect(validatorRejection('social_jurisdictions', row)).toBeNull()
  })

  it('přepnutí zpět na český režim závislá pole zase uklidí', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-social_jurisdictions"]').trigger('click')
    await wrapper.get('[data-test="social_jurisdictions-0-jurisdiction"]')
      .setValue('foreign_regime_verified')
    await wrapper.findAllComponents(CountrySelect)[0]!.vm.$emit('update:modelValue', 'SK')
    await wrapper.get('[data-test="social_jurisdictions-0-a1_status"]').setValue('verified')
    await wrapper.get('[data-test="social_jurisdictions-0-a1_valid_until"]').setValue('2027-12-31')
    await wrapper.get('[data-test="social_jurisdictions-0-jurisdiction"]')
      .setValue('czech_regime_verified')

    expect(wrapper.find('[data-test="social_jurisdictions-0-foreign_country_code"]').exists())
      .toBe(false)
    expect(wrapper.find('[data-test="social_jurisdictions-0-a1_status"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const row = savedRow('social_jurisdictions')
    expect(row).toMatchObject({
      jurisdiction: 'czech_regime_verified',
      foreign_country_code: null,
      jurisdiction_evidence_reference: null,
      a1_status: 'not_applicable',
      a1_certificate_reference: null,
      a1_valid_until: null,
    })
    expect(validatorRejection('social_jurisdictions', row)).toBeNull()
  })

  it('doložený A1 řekne, do kdy musí platit, místo obecné hlášky serveru', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-social_jurisdictions"]').trigger('click')
    await wrapper.get('[data-test="social_jurisdictions-0-jurisdiction"]')
      .setValue('foreign_regime_verified')
    await wrapper.findAllComponents(CountrySelect)[0]!.vm.$emit('update:modelValue', 'SK')
    await wrapper.get('[data-test="social_jurisdictions-0-a1_status"]').setValue('verified')

    const issues = wrapper.get('[data-test="issues-social_jurisdictions-0"]').text()
    expect(issues).toContain('payroll.people.statutory_evidence.issue.a1_valid_until_required')
    // Odkaz k A1 je volitelný; blokuje jen chybějící datum platnosti.
    expect(issues).not.toContain('reference_required')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(mocks.saveStatutoryEvidence).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="statutory-evidence-error"]').text())
      .toContain('payroll.people.statutory_evidence.issues_block_save')
  })

  it('cizí zdravotní režim zruší českou pojišťovnu a návrat zpět ji vrátí', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_coverages"]').trigger('click')
    await wrapper.get('[data-test="health_coverages-0-jurisdiction"]')
      .setValue('foreign_regime_verified')

    expect(
      (wrapper.get('[data-test="health_coverages-0-insurer_status"]').element as HTMLSelectElement)
        .value,
    ).toBe('not_applicable')
    expect(wrapper.find('[data-test="health_coverages-0-insurer_code"]').exists()).toBe(false)

    await wrapper.get('[data-test="health_coverages-0-jurisdiction"]')
      .setValue('czech_regime_verified')
    expect(
      (wrapper.get('[data-test="health_coverages-0-insurer_code"]').element as HTMLSelectElement)
        .value,
    ).toBe('205')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(validatorRejection('health_coverages', savedRow('health_coverages'))).toBeNull()
  })

  it('„pojišťovna se netýká“ v českém režimu se pojmenuje dřív, než ji odmítne server', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_coverages"]').trigger('click')
    // Kaskáda hlídá jen přepnutí jurisdikce; sem se uživatel dostane přepnutím
    // samotného stavu pojišťovny — a to je přesně kombinace, kterou server
    // odmítá (`Ověřená česká zdravotní jurisdikce nemůže mít pojišťovnu…`).
    await wrapper.get('[data-test="health_coverages-0-insurer_status"]')
      .setValue('not_applicable')

    expect(wrapper.get('[data-test="issues-health_coverages-0"]').text()).toContain(
      'payroll.people.statutory_evidence.issue.insurer_not_applicable_in_czech_regime',
    )

    await wrapper.get('[data-test="health_coverages-0-insurer_status"]').setValue('unverified')
    expect(wrapper.find('[data-test="issues-health_coverages-0"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(validatorRejection('health_coverages', savedRow('health_coverages'))).toBeNull()
  })

  it('prázdný odkaz uloží jako nepovinný údaj', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    // Volný text se nenabízí, dokud si ho uživatel nevyžádá.
    expect(wrapper.find('[data-test="tax_declarations-0-evidence_reference"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(savedRow('tax_declarations').evidence_reference).toBeNull()
  })

  it('zadanou referenci kontroluje a vlastní platnou hodnotu uloží', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')
    await wrapper.get('[data-test="tax_declarations-0-status"]').setValue('signed')

    await wrapper.get('[data-test="tax_declarations-0-evidence_reference-reason"]')
      .setValue('custom')
    expect(wrapper.find('[data-test="issues-tax_declarations-0"]').exists()).toBe(false)

    await wrapper.get('[data-test="tax_declarations-0-evidence_reference"]')
      .setValue('prohlášení 12/2026')
    expect(wrapper.get('[data-test="issues-tax_declarations-0"]').text())
      .toContain('payroll.people.statutory_evidence.issue.reference_invalid')

    await wrapper.get('[data-test="tax_declarations-0-evidence_reference"]').setValue('12345/2026')
    await wrapper.get('[data-test="tax_declarations-0-evidence_note"]')
      .setValue('Papír ve složce')
    expect(wrapper.find('[data-test="issues-tax_declarations-0"]').exists()).toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(savedRow('tax_declarations')).toMatchObject({
      status: 'signed',
      evidence_reference: '12345/2026',
      evidence_note: 'Papír ve složce',
      effective_from: expect.stringMatching(/-01$/),
      effective_to: null,
    })
    const [personId, payload] = mocks.saveStatutoryEvidence.mock.calls[0]!
    expect(personId).toBe(17)
    // Nedotčené kolekce musí odejít taky — tělo popisuje cílový stav.
    expect(payload.sections.health_coverages).toEqual([])
  })

  it('nabídka důvodů se řídí zvoleným stavem, ne jen názvem pole', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    const reasons = () => wrapper.get('[data-test="tax_declarations-0-evidence_reference-reason"]')
      .findAll('option')
      .map(option => option.attributes('value'))

    expect(reasons()).toEqual(['', 'declaration:38k-not-signed', 'custom'])

    await wrapper.get('[data-test="tax_declarations-0-status"]').setValue('signed')
    expect(reasons()).toEqual(['', 'declaration:38k-signed', 'custom'])

    await wrapper.get('[data-test="tax_declarations-0-status"]').setValue('unverified')
    // Neověřená varianta doklad nést nesmí, tak se pole schová.
    expect(wrapper.find('[data-test="tax_declarations-0-evidence_reference-reason"]').exists())
      .toBe(false)

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(savedRow('tax_declarations')).toMatchObject({
      status: 'unverified',
      evidence_reference: null,
    })
  })

  it('nerezident si vyžádá zahraniční stát a odkaz ponechá nepovinný', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_residences"]').trigger('click')
    await wrapper.get('[data-test="tax_residences-0-residence"]').setValue('non-resident')

    expect(wrapper.find('[data-test="tax_residences-0-country_code"]').exists()).toBe(true)
    expect(
      (wrapper.get('[data-test="tax_residences-0-evidence_reference-reason"]')
        .element as HTMLSelectElement).value,
    ).toBe('')
    expect(wrapper.get('[data-test="issues-tax_residences-0"]').text())
      .toContain('payroll.people.statutory_evidence.issue.country_required')

    await wrapper.findAllComponents(CountrySelect)[0]!.vm.$emit('update:modelValue', 'SK')
    await flushPromises()

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const row = savedRow('tax_residences')
    expect(row).toMatchObject({
      residence: 'non-resident',
      country_code: 'SK',
      evidence_reference: null,
    })
    expect(validatorRejection('tax_residences', row)).toBeNull()
  })

  it('neověřená varianta je nabídnutá jako plnohodnotná volba', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    const options = wrapper.get('[data-test="tax_declarations-0-status"]')
      .findAll('option')
      .map(option => option.attributes('value'))
    expect(options).toEqual(['signed', 'not-signed', 'unverified'])
  })

  it('u uzavřeného období nedovolí posunout začátek ani záznam odebrat', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await startEditing()

    expect(
      wrapper.get('[data-test="tax_declarations-0-effective_from"]').attributes('disabled'),
    ).toBeDefined()
    expect(wrapper.find('[data-test="remove-tax_declarations-0"]').exists()).toBe(false)
    // Ukončit jde — to historii nepřepisuje.
    expect(
      wrapper.get('[data-test="tax_declarations-0-effective_to"]').attributes('disabled'),
    ).toBeUndefined()
    expect(wrapper.find('[data-test="statutory-evidence-frozen"]').exists()).toBe(true)
  })

  it('ukáže nahoře, co u sekce teď platí a od kdy', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    const current = wrapper.get('[data-test="current-tax_declarations"]').text()
    expect(current).toContain('payroll.people.statutory_evidence.option.status.signed')
    expect(current).toContain('payroll.people.statutory_evidence.current_from')
    // Sekce bez záznamu nesmí tvrdit stav, který nemá.
    expect(wrapper.get('[data-test="current-tax_residences"]').text())
      .toContain('payroll.people.statutory_evidence.current_missing')
  })

  it('odkaz na podklad a poznámka jsou sbalené, dokud nic nenesou', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    const details = wrapper.get('[data-test="evidence-details-tax_declarations-0"]')
    expect(details.attributes('open')).toBeUndefined()
    // Sbalené neznamená nedostupné — pole zůstávají v řádku.
    expect(details.find('[data-test="tax_declarations-0-evidence_note"]').exists()).toBe(true)
  })

  it('u zamčeného řádku nabídne novou verzi od dalšího měsíce, ne jen zašedlá pole', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await mounted()

    await wrapper.get('[data-test="change-from-tax_declarations"]').trigger('click')
    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    // Minulost zůstává, jen se uzavře hranicí zmrazení…
    expect(savedRow('tax_declarations')).toMatchObject({
      id: 5,
      effective_from: '2026-01-01',
      effective_to: '2026-04-30',
      status: 'signed',
    })
    // …a nová verze pokračuje prvním dnem dalšího měsíce.
    const created = savedRow('tax_declarations', 1)
    expect(created.id).toBeUndefined()
    expect(created).toMatchObject({
      effective_from: '2026-05-01',
      effective_to: null,
      status: 'signed',
    })
  })

  it('nabídne otevřít k opravě všechny běhy, které hranici drží', async () => {
    mocks.commandRun.mockResolvedValue({})
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      frozen_through: '2026-04-30',
      blockers: [],
      sections: {
        ...filledEvidence().sections,
      },
      frozen_runs: [
        { id: 71, row_version: 3, status: 'approved', period_start: '2026-04-01', command: 'request_correction' },
        { id: 72, row_version: 1, status: 'paid', period_start: '2026-04-01', command: 'request_correction' },
      ],
    }))
    const wrapper = await mounted()

    await wrapper.get('[data-test="open-run-tax_declarations"]').trigger('click')
    await flushPromises()

    expect(mocks.commandRun).toHaveBeenCalledTimes(2)
    expect(mocks.commandRun.mock.calls[0]![0]).toBe(71)
    expect(mocks.commandRun.mock.calls[0]![1]).toBe('request_correction')
    expect(mocks.commandRun.mock.calls[0]![2]).toMatchObject({ row_version: 3 })
    expect(mocks.commandRun.mock.calls[1]![0]).toBe(72)
  })

  it('bez práva na opravu běhu tlačítko nenabídne', async () => {
    mocks.canWrite.mockReturnValue(false)
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      frozen_through: '2026-04-30',
      blockers: [],
      sections: { ...filledEvidence().sections },
      frozen_runs: [
        { id: 71, row_version: 3, status: 'approved', period_start: '2026-04-01', command: 'request_correction' },
      ],
    }))
    const wrapper = await mounted()

    expect(wrapper.find('[data-test="open-run-tax_declarations"]').exists()).toBe(false)
  })

  it('upozorní na dva otevřené záznamy dřív, než je server odmítne jako překryv', async () => {
    mocks.statutoryEvidence.mockResolvedValue(filledEvidence())
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-tax_declarations"]').trigger('click')

    expect(wrapper.get('[data-test="issues-tax_declarations"]').text())
      .toContain('payroll.people.statutory_evidence.issue.multiple_open_rows')

    await wrapper.get('[data-test="tax_declarations-0-effective_to"]').setValue('2026-07-31')
    expect(wrapper.find('[data-test="issues-tax_declarations"]').exists()).toBe(false)
  })

  it('ponechá na obrazovce konkrétní hlášku serveru, ne obecný text', async () => {
    mocks.saveStatutoryEvidence.mockRejectedValue({
      response: { data: { error: { message: 'Evidence „tax_declarations“ musí na sebe navazovat.' } } },
    })
    const wrapper = await startEditing()
    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="statutory-evidence-error"]').text())
      .toContain('musí na sebe navazovat')
  })

  it('při přepnutí osoby načte evidenci znovu', async () => {
    const wrapper = await mounted()
    expect(mocks.statutoryEvidence).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ personId: 42 })
    await flushPromises()

    expect(mocks.statutoryEvidence).toHaveBeenCalledTimes(2)
    expect(mocks.statutoryEvidence.mock.calls[1]![0]).toBe(42)
  })

  it('nastavení zaměstnavatele načte nejvýš jednou, ne na každou kartu osoby', async () => {
    const wrapper = await mounted()
    await wrapper.setProps({ personId: 42 })
    await flushPromises()
    await mounted()

    expect(mocks.employerSettings).toHaveBeenCalledTimes(1)
  })

  /**
   * Výjimky z minima ZP (§ 3 odst. 8 a 9 z. 592/1992) dřív na kartě osoby
   * vůbec nebyly, mzda pak dorovnávala pojistné do minima, které se nedluží.
   */
  it('výjimku z minima zdravotního pojištění jde zadat s přesným dnem, důvodem a dokladem', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_minimum_reductions"]').trigger('click')

    const reasons = wrapper.get('[data-test="health_minimum_reductions-0-reason"]')
      .findAll('option').map(option => option.attributes('value'))
    expect(reasons).toContain('state_insured')
    expect(reasons).toContain('ztp_or_ztp_p')
    expect(reasons).toContain('osvc_minimum_advance')

    await wrapper.get('[data-test="health_minimum_reductions-0-reason"]').setValue('ztp_or_ztp_p')
    // Výjimka začíná uprostřed měsíce. Minimum se krátí po dnech, takže to
    // formulář nesmí hlásit jako chybu „jen celé měsíce".
    await wrapper.get('[data-test="health_minimum_reductions-0-effective_from"]').setValue('2026-08-10')
    await flushPromises()
    expect(wrapper.find('[data-test="issues-health_minimum_reductions-0"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-test="health_minimum_reductions-0-evidence_reference-reason"]')
        .findAll('option').map(option => option.attributes('value')),
    ).toEqual(['', 'minimum:ztp-card', 'custom'])
    await wrapper.get('[data-test="health_minimum_reductions-0-evidence_reference-reason"]')
      .setValue('minimum:ztp-card')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const row = savedRow('health_minimum_reductions')
    expect(row).toMatchObject({
      reason: 'ztp_or_ztp_p',
      evidence_reference: 'minimum:ztp-card',
      effective_from: '2026-08-10',
      effective_to: null,
    })
    expect(validatorRejection('health_minimum_reductions', row)).toBeNull()
  })

  it('neověřenou výjimku ukáže jako blokující stav', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      blockers: ['health_minimum_reduction_unverified'],
      sections: {
        ...emptyEvidence().sections,
        health_minimum_reductions: [{
          id: 3, row_version: 1, reason: 'unverified', evidence_reference: null,
          evidence_note: null, effective_from: '2026-08-01', effective_to: null,
        }],
      },
    }))
    const wrapper = await mounted()

    expect(wrapper.get('[data-test="statutory-evidence-blockers"]').text())
      .toContain('payroll.people.statutory_evidence.blocker.health_minimum_reduction_unverified')
    expect(wrapper.get('[data-test="history-health_minimum_reductions"]').attributes('open'))
      .toBeDefined()
  })

  it('základ u jiného zaměstnavatele zadá v korunách, uloží v haléřích a hned ho nabídne jako plátce doplatku', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_other_employer_bases"]').trigger('click')

    const issues = wrapper.get('[data-test="issues-health_other_employer_bases-0"]').text()
    expect(issues).toContain('payroll.people.statutory_evidence.issue.reference_required')
    expect(issues).toContain('payroll.people.statutory_evidence.issue.amount_required')

    await wrapper.get('[data-test="health_other_employer_bases-0-employer_reference"]')
      .setValue('zamestnavatel:firma-b')
    await wrapper.get('[data-test="health_other_employer_bases-0-assessment_base_minor_units"]')
      .setValue('15 000,50')
    expect(wrapper.find('[data-test="issues-health_other_employer_bases-0"]').exists()).toBe(false)

    // Volba plátce doplatku vidí základ zapsaný ve stejné úpravě.
    await wrapper.get('[data-test="add-health_month_evidence"]').trigger('click')
    const employers = wrapper.get('[data-test="health_month_evidence-0-selected_top_up_employer_reference"]')
      .findAll('option').map(option => option.attributes('value'))
    expect(employers).toContain('zamestnavatel:firma-b')
    await wrapper.get('[data-test="health_month_evidence-0-selected_top_up_employer_reference"]')
      .setValue('zamestnavatel:firma-b')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()

    const base = savedRow('health_other_employer_bases')
    expect(base).toMatchObject({
      employer_reference: 'zamestnavatel:firma-b',
      assessment_base_minor_units: '1500050',
      period_start: currentMonthStart(),
      employment_from: currentMonthStart(),
    })
    expect(validatorRejection('health_other_employer_bases', base)).toBeNull()
    expect(savedRow('health_month_evidence')).toMatchObject({
      selected_top_up_employer_reference: 'zamestnavatel:firma-b',
    })
  })

  it('neplatnou částku pojmenuje a uložení zablokuje, místo tiché nuly', async () => {
    const wrapper = await startEditing()
    await wrapper.get('[data-test="add-health_other_employer_bases"]').trigger('click')
    await wrapper.get('[data-test="health_other_employer_bases-0-employer_reference"]')
      .setValue('zamestnavatel:firma-b')
    await wrapper.get('[data-test="health_other_employer_bases-0-assessment_base_minor_units"]')
      .setValue('15,000,5')

    expect(wrapper.get('[data-test="issues-health_other_employer_bases-0"]').text())
      .toContain('payroll.people.statutory_evidence.issue.amount_invalid')
    // Rozepsaný text zůstane, ať je vidět, co opravit.
    expect(
      (wrapper.get('[data-test="health_other_employer_bases-0-assessment_base_minor_units"]')
        .element as HTMLInputElement).value,
    ).toBe('15,000,5')

    await wrapper.get('[data-test="statutory-evidence-save"]').trigger('click')
    await flushPromises()
    expect(mocks.saveStatutoryEvidence).not.toHaveBeenCalled()
  })

  it('uložený základ ukáže v přehledu s částkou', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      sections: {
        ...emptyEvidence().sections,
        health_other_employer_bases: [{
          id: 4, row_version: 1, period_start: currentMonthStart(),
          employer_reference: 'zamestnavatel:firma-b', assessment_base_minor_units: 1_500_000,
          employment_from: '2026-01-01', employment_to: null, evidence_reference: null,
          evidence_note: null,
        }],
      },
    }))
    const wrapper = await mounted()

    const current = wrapper.get('[data-test="current-health_other_employer_bases"]').text()
    expect(current).toContain('zamestnavatel:firma-b')
    expect(current).toMatch(/15\s000/)
  })
})

/**
 * Hlášení účetní (1. 10. 2026): po kliknutí na „Upravit" u prázdné sekce se
 * „jakoby nic nestalo", a kdo přidal záznam, viděl pod ním jen další „Přidat
 * záznam" a změnu neuložil, protože Uložit bylo jen ve společné liště dole.
 */
describe('PayrollPersonStatutoryEvidencePanel — přidání a uložení v sekci', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    resetDefaultHealthInsurerCode()
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    mocks.saveStatutoryEvidence.mockResolvedValue(filledEvidence())
    mocks.employerSettings.mockResolvedValue({ default_health_insurer_code: '205' })
    // happy-dom scroll neumí; doskok ho volá a v prohlížeči ho má.
    Element.prototype.scrollIntoView = vi.fn()
  })

  function mountOnCard(onRegistry: (registry: PersonCardSaveRegistry) => void) {
    const Card = defineComponent({
      setup() {
        onRegistry(providePersonCardSave())
        return () => h(PayrollPersonStatutoryEvidencePanel, { personId: 17, canWrite: true })
      },
    })
    return mount(Card, { attachTo: document.body })
  }

  it('prázdná sekce nabízí rovnou Přidat záznam a otevře formulář jen v ní', async () => {
    const wrapper = await mounted(true, true)

    expect(wrapper.find('[data-test="edit-health_coverages"]').exists()).toBe(false)
    const add = wrapper.get('[data-test="add-first-health_coverages"]')
    // Chybějící údaj: hlavní akce, plné tlačítko.
    expect(add.classes()).toContain('bg-primary-600')

    await add.trigger('click')
    await flushPromises()

    const insurer = wrapper.get('[data-test="health_coverages-0-insurer_code"]')
    expect(insurer.attributes('disabled')).toBeUndefined()
    expect(document.activeElement).toBe(insurer.element)
    expect(wrapper.get('[data-test="section-health_coverages"]').attributes('data-editing'))
      .toBe('true')
    // Ostatní sekce zůstávají zavřené.
    expect(wrapper.find('[data-test="add-first-tax_declarations"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="inline-bar-tax_declarations"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('nový záznam prázdné sekce platí od měsíce nástupu', async () => {
    const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
      props: { personId: 17, canWrite: true, employmentStartOn: '2026-03-15' },
    })
    await flushPromises()
    await wrapper.get('[data-test="add-first-health_coverages"]').trigger('click')
    await wrapper.get('[data-test="inline-save-health_coverages"]').trigger('click')
    await flushPromises()

    expect(savedRow('health_coverages')).toMatchObject({ effective_from: '2026-03-01' })
  })

  it('doskok z varování otevře nový záznam a kurzor postaví do pojišťovny', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const wrapper = await mounted(true, true)

    const revealed = await (wrapper.vm as unknown as {
      revealSection: (key: string) => Promise<boolean>
    }).revealSection('health_coverages')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(450)

    expect(revealed).toBe(true)
    const insurer = wrapper.get('[data-test="health_coverages-0-insurer_code"]')
    expect(insurer.attributes('disabled')).toBeUndefined()
    expect(document.activeElement).toBe(insurer.element)
    expect(wrapper.find('[data-test="inline-bar-health_coverages"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('doskok před dokončením načtení počká na data', async () => {
    let resolve: (value: PayrollStatutoryEvidence) => void = () => {}
    mocks.statutoryEvidence.mockReturnValue(new Promise((done) => { resolve = done }))
    const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
      props: { personId: 17, canWrite: true },
      attachTo: document.body,
    })
    await (wrapper.vm as unknown as {
      revealSection: (key: string) => Promise<boolean>
    }).revealSection('health_coverages')
    resolve(emptyEvidence())
    await flushPromises()

    expect(wrapper.find('[data-test="health_coverages-0-insurer_code"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('rozpracovaný záznam má Uložit přímo pod sebou a další přidání až po uložení', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="add-first-tax_declarations"]').trigger('click')

    expect(wrapper.find('[data-test="add-tax_declarations"]').exists()).toBe(false)
    const save = wrapper.get('[data-test="inline-save-tax_declarations"]')
    // Předvyplněný záznam je hned platný, Uložit je hlavní krok.
    expect(save.classes()).toContain('bg-primary-600')

    await save.trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="inline-bar-tax_declarations"]').exists()).toBe(false)
    // Po uložení je vidět, co se uložilo, a nabídne se další záznam.
    expect(wrapper.get('[data-test="history-tax_declarations"]').attributes('open')).toBeDefined()
    expect(wrapper.get('[data-test="add-tax_declarations"]').text())
      .toContain('payroll.people.statutory_evidence.add_another')
  })

  it('neúplný záznam nechá Uložit jen obrysové a pojmenuje, co chybí', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="add-first-health_other_employer_bases"]').trigger('click')

    const save = wrapper.get('[data-test="inline-save-health_other_employer_bases"]')
    expect(save.classes()).not.toContain('bg-primary-600')
    expect(wrapper.get('[data-test="inline-bar-health_other_employer_bases"]').text())
      .toContain('payroll.people.statutory_evidence.inline_unsaved_incomplete')
  })

  it('Zahodit vrátí jen tu sekci a zavře její úpravy', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="add-first-tax_declarations"]').trigger('click')
    await wrapper.get('[data-test="add-first-tax_residences"]').trigger('click')

    await wrapper.get('[data-test="inline-discard-tax_declarations"]').trigger('click')

    expect(wrapper.find('[data-test="tax_declarations-0-status"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="inline-bar-tax_declarations"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="inline-save-tax_residences"]').exists()).toBe(true)
  })

  it('na kartě volá Uložit pod sekcí totéž společné uložení jako lišta dole', async () => {
    let registry: PersonCardSaveRegistry | null = null
    const wrapper = mountOnCard((value) => { registry = value })
    await flushPromises()
    const saveAll = vi.spyOn(registry!, 'saveAll')

    await wrapper.get('[data-test="add-first-health_coverages"]').trigger('click')
    // Lišta karty jmenuje konkrétní blok, ne celou evidenci.
    expect(registry!.dirtySections.value.map(section => section.label()))
      .toEqual(['payroll.people.statutory_evidence.section.health_coverages'])
    // Na kartě panel vlastní Uložit dole nekreslí.
    expect(wrapper.find('[data-test="statutory-evidence-save"]').exists()).toBe(false)

    await wrapper.get('[data-test="inline-save-health_coverages"]').trigger('click')
    await flushPromises()

    expect(saveAll).toHaveBeenCalledTimes(1)
    expect(mocks.saveStatutoryEvidence).toHaveBeenCalledTimes(1)
    expect(registry!.hasChanges.value).toBe(false)
    wrapper.unmount()
  })

  it('zastavené uložení ohlásí karta stejně, ať ho spustí kterákoli lišta', async () => {
    mocks.saveStatutoryEvidence.mockRejectedValue(new Error('x'))
    const onStopped = vi.fn()
    const Card = defineComponent({
      setup() {
        providePersonCardSave({ onStopped })
        return () => h(PayrollPersonStatutoryEvidencePanel, { personId: 17, canWrite: true })
      },
    })
    const wrapper = mount(Card)
    await flushPromises()
    await wrapper.get('[data-test="add-first-tax_declarations"]').trigger('click')
    await wrapper.get('[data-test="inline-save-tax_declarations"]').trigger('click')
    await flushPromises()

    expect(onStopped).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="inline-error-tax_declarations"]').exists()).toBe(true)
  })

  it('bloky seskupí pod nadpisy Daň, Sociální a Zdravotní pojištění', async () => {
    const wrapper = await mounted()

    expect(wrapper.get('[data-test="group-tax"]').find('[data-test="section-tax_residences"]').exists())
      .toBe(true)
    expect(wrapper.get('[data-test="group-social"]').find('[data-test="section-social_jurisdictions"]').exists())
      .toBe(true)
    expect(wrapper.get('[data-test="group-health"]').find('[data-test="section-health_coverages"]').exists())
      .toBe(true)
    expect(wrapper.find('[data-test="group-other"]').exists()).toBe(false)
  })
})

describe('PayrollPersonStatutoryEvidencePanel — výchozí záznamy jedním potvrzením', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    resetDefaultHealthInsurerCode()
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    mocks.employerSettings.mockResolvedValue({ default_health_insurer_code: '205' })
  })

  it('u chybějící evidence nabídne doplnění pro tuto osobu od měsíce nástupu', async () => {
    const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
      props: { personId: 17, canWrite: true, employmentStartOn: '2020-03-15' },
      global: { stubs: { PayrollStatutoryBulkDefaultsDialog: { name: 'PayrollStatutoryBulkDefaultsDialog', props: ['effectiveOn', 'employeeIds'], template: '<div data-test="defaults-dialog" />' } } },
    })
    await flushPromises()

    await wrapper.get('[data-test="statutory-evidence-defaults"]').trigger('click')
    const dialog = wrapper.getComponent({ name: 'PayrollStatutoryBulkDefaultsDialog' })
    expect(dialog.props('employeeIds')).toEqual([17])
    expect(dialog.props('effectiveOn')).toBe('2020-03-01')
  })

  /* Q8-48: u budoucího nástupu hlásila evidence k dnešku chybějící údaje. */
  it('u budoucího nástupu vyhodnocuje evidenci k datu nástupu', async () => {
    const future = new Date()
    future.setMonth(future.getMonth() + 3)
    const start = `${future.getFullYear()}-${String(future.getMonth() + 1).padStart(2, '0')}-15`
    mount(PayrollPersonStatutoryEvidencePanel, {
      props: { personId: 17, canWrite: true, employmentStartOn: start },
    })
    await flushPromises()
    expect(mocks.statutoryEvidence.mock.calls[0]?.[1]).toBe(start)
  })

  it('bez oprávnění k zápisu doplnění nenabízí', async () => {
    const wrapper = mount(PayrollPersonStatutoryEvidencePanel, { props: { personId: 17, canWrite: false } })
    await flushPromises()
    expect(wrapper.find('[data-test="statutory-evidence-defaults"]').exists()).toBe(false)
  })
})

/**
 * Důchodové údaje: zdroj kódu D a odečtených dob v ELDP i JMHZ. Den dosažení
 * věku se jen NABÍZÍ z data narození, zapisuje ho účetní.
 */
describe('PayrollPersonStatutoryEvidencePanel — důchodové údaje', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    resetDefaultHealthInsurerCode()
    mocks.saveStatutoryEvidence.mockResolvedValue(filledEvidence())
    mocks.employerSettings.mockResolvedValue({ default_health_insurer_code: '205' })
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('nabídnutý den dosažení důchodového věku předvyplní nový záznam', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      derived: { taxpayer_credit: false, pension_age_suggestion: '2027-11-30' },
    }))
    const wrapper = await mounted()

    expect(wrapper.get('[data-test="pension-age-suggestion"]').text())
      .toContain('payroll.people.statutory_evidence.pension_age_suggestion')
    await wrapper.get('[data-test="add-first-social_pension_age"]').trigger('click')
    // Jediný záznam bez konce platnosti.
    expect(wrapper.find('[data-test="social_pension_age-0-effective_to"]').exists()).toBe(false)
    await wrapper.get('[data-test="inline-save-social_pension_age"]').trigger('click')
    await flushPromises()

    expect(savedRow('social_pension_age')).toMatchObject({
      effective_from: '2027-11-30',
      effective_to: null,
      basis: 'statutory_table',
    })
  })

  it('bez jednoznačného výpočtu den nepředvyplní a uložení zablokuje', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      derived: { taxpayer_credit: false, pension_age_suggestion: null },
    }))
    const wrapper = await mounted()

    expect(wrapper.get('[data-test="pension-age-suggestion"]').text())
      .toContain('payroll.people.statutory_evidence.pension_age_no_suggestion')
    await wrapper.get('[data-test="add-first-social_pension_age"]').trigger('click')
    await wrapper.get('[data-test="inline-save-social_pension_age"]').trigger('click')
    await flushPromises()

    expect(mocks.saveStatutoryEvidence).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="issues-social_pension_age-0"]').text())
      .toContain('payroll.people.statutory_evidence.issue.period_required')
  })

  it('předčasnost se nabízí jen u starobního důchodu a zmrazení důchod nezamyká', async () => {
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence({
      frozen_through: '2026-06-30',
      sections: {
        ...emptyEvidence().sections,
        social_pensions: [{
          id: 9, row_version: 1, pension_type_code: '1', early_retirement: 1,
          reduced_retirement_age: 0, evidence_reference: null, evidence_note: null,
          effective_from: '2026-02-01', effective_to: null,
        }],
      },
    }))
    const wrapper = await mounted()
    await wrapper.get('[data-test="edit-social_pensions"]').trigger('click')

    const from = wrapper.get('[data-test="social_pensions-0-effective_from"]')
    expect(from.attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="change-from-social_pensions"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="social_pensions-0-early_retirement"]').exists()).toBe(true)

    await wrapper.get('[data-test="social_pensions-0-pension_type_code"]').setValue('2')
    expect(wrapper.find('[data-test="social_pensions-0-early_retirement"]').exists()).toBe(false)
    await wrapper.get('[data-test="inline-save-social_pensions"]').trigger('click')
    await flushPromises()

    expect(savedRow('social_pensions')).toMatchObject({ pension_type_code: '2', early_retirement: '0' })
  })
})
