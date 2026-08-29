import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

import CountrySelect from '@/components/ui/CountrySelect.vue'
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
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
    },
    other_employer_bases: [],
    blockers: [
      'tax_declaration_evidence_missing',
      'tax_residence_evidence_missing',
      'social_jurisdiction_evidence_missing',
      'working_pensioner_discount_evidence_missing',
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
      social_jurisdictions: [],
      social_discount_claims: [],
      health_coverages: [],
      health_month_evidence: [],
    },
  })
}

async function mounted(canWrite = true) {
  const wrapper = mount(PayrollPersonStatutoryEvidencePanel, {
    props: { personId: 17, canWrite },
  })
  await flushPromises()
  return wrapper
}

async function startEditing(canWrite = true) {
  const wrapper = await mounted(canWrite)
  await wrapper.get('[data-test="start-statutory-evidence"]').trigger('click')
  return wrapper
}

/** Poslední tělo poslané na server; test si z něj vezme jeden řádek sekce. */
function savedRow(section: string, index = 0): PayrollStatutoryEvidenceRow {
  const [, payload] = mocks.saveStatutoryEvidence.mock.calls[0]!
  return payload.sections[section][index]
}

describe('PayrollPersonStatutoryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canWrite.mockReturnValue(true)
    resetDefaultHealthInsurerCode()
    mocks.statutoryEvidence.mockResolvedValue(emptyEvidence())
    mocks.saveStatutoryEvidence.mockResolvedValue(filledEvidence())
    mocks.employerSettings.mockResolvedValue({ default_health_insurer_code: '205' })
  })

  it('pojmenuje konkrétně, co chybí, a co se stane, když to zůstane nevyplněné', async () => {
    const wrapper = await mounted()

    const blockers = wrapper.get('[data-test="statutory-evidence-blockers"]')
    expect(blockers.findAll('li')).toHaveLength(5)
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blocker.tax_declaration_evidence_missing',
    )
    expect(blockers.text()).toContain(
      'payroll.people.statutory_evidence.blockers_consequence',
    )
    expect(wrapper.find('[data-test="statutory-evidence-complete"]').exists()).toBe(false)
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
  })

  it('nový záznam běžného českého případu je rovnou platný podle pravidel serveru', async () => {
    const wrapper = await startEditing()

    for (const section of [
      'tax_declarations',
      'tax_residences',
      'social_jurisdictions',
      'social_discount_claims',
      'health_coverages',
      'health_month_evidence',
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
})
