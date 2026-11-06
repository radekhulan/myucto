import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { payrollApi, type PayrollEmployment } from '@/api/payroll'
import DateInput from '@/components/ui/DateInput.vue'
import { revealField } from '@/utils/revealField'

vi.mock('@/utils/revealField', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/utils/revealField')>()
  return { ...actual, revealField: vi.fn(actual.revealField) }
})

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    transitionEmployment: vi.fn(),
    saveStatutoryOpenings: vi.fn(),
    renameEmployment: vi.fn(),
    setEmploymentMealEntitlementBasis: vi.fn(),
    remunerationOptions: vi.fn().mockResolvedValue({ minimum_hourly_40h_minor: null, ruleset_id: null }),
    addEmploymentTerms: vi.fn(),
    correctEmploymentTerms: vi.fn(),
    updateEmploymentChecklist: vi.fn(),
    confirmEmploymentStart: vi.fn(),
    deleteEmployment: vi.fn(),
    // Panel zásad příplatků se na kartě montuje taky — bez tovární funkce by
    // spadl do chybové větve a test by měřil něco jiného, než chce měřit.
    employmentSurchargePolicies: vi.fn().mockResolvedValue({
      policies: [],
      statutory_default: {
        overtime_mode: 'surcharge',
        holiday_mode: 'compensatory_time_off',
        difficult_environment_factors: null,
      },
      kinds: [],
      ruleset_id: 'synthetic',
    }),
    createEmploymentSurchargePolicy: vi.fn(),
    employmentJmhzEvidenceOptions: vi.fn().mockResolvedValue({
      package_key: 'synthetic',
      manifest_sha256: 'a'.repeat(64),
      external_codebooks: {
        overlay_key: 'synthetic-overlay',
        manifest_sha256: 'b'.repeat(64),
        snapshot_date: '2026-08-13',
        effective_from: '2026-01-01',
        verified_through: '2026-08-13',
        base_spec_manifest_sha256: 'a'.repeat(64),
      },
      apz_instruments: [{ code: '1', label: 'VPP' }],
      activity_codes: [
        { code: '1', label: 'Pracovní poměr', relationship_detail_mode: 'select' },
        { code: 'A', label: 'Dohoda', relationship_detail_mode: 'forbidden' },
        { code: 'S', label: 'Společník nebo jednatel', relationship_detail_mode: 'fixed_none' },
        { code: '13', label: 'Jiný příjem ze závislé činnosti', relationship_detail_mode: 'fixed_none' },
      ],
      relationship_detail_codes: [
        { code: '1', label: 'Žádné' },
        { code: '2', label: 'Výkon trestu odnětí svobody' },
      ],
      countries: [{ code: 'CZ', label: 'Česko' }],
      tax_identifier_types: [{ code: 'D', label: 'DIČ' }],
      education_levels: [{ code: 'A', label: 'Bez vzdělání' }],
      work_mode_codes: [{ code: '1', label: 'Jednosměnný pracovní režim' }],
      workplace_progress_codes: [{ code: '1', label: 'V prostorách zaměstnavatele' }],
      pension_type_codes: [{ code: '1', label: 'starobní' }],
      proof_identity_type_codes: [{ code: 'I', label: 'Průkaz totožnosti' }],
      health_restriction_type_codes: [{ code: '1', label: 'III. stupeň invalidity' }],
      foreign_worker_free_access_reason_codes: [
        { code: '1', label: 'Občan EU/EHP a Švýcarska' },
      ],
      foreign_worker_permit_type_codes: [{ code: '1', label: 'povolení k zaměstnání' }],
      labour_office_codes: [{ code: 'HMP', label: 'Krajská pobočka pro hlavní město Prahu' }],
    }),
    searchJmhzMunicipalities: vi.fn().mockResolvedValue([
      { code: '554782', label: 'Hlavní město Praha' },
    ]),
    // Nabídku mzdových účtáren bere karta z nastavení zaměstnavatele.
    employerSettings: vi.fn().mockResolvedValue({
      offices: [
        { id: 7, code: 'PHA', name: 'Praha', social_security_variable_symbol: null, is_active: true, row_version: 1 },
        { id: 8, code: 'OLD', name: 'Zrušená', social_security_variable_symbol: null, is_active: false, row_version: 1 },
      ],
    }),
  },
}))

// Rozcestník navazujících agend má vlastní test (EmploymentAgendaPanel.spec.ts).
// Tady by jen tahal auth store a další požadavek do každého mountu karty.
vi.mock('@/pages/payroll/EmploymentAgendaPanel.vue', () => ({
  default: { template: '<div data-test="employment-agendas-stub" />' },
}))

// Panel odloženého příjmu má vlastní test (EmploymentDeferredIncomePanel.spec.ts).
vi.mock('@/pages/payroll/EmploymentDeferredIncomePanel.vue', () => ({
  default: { template: '<div data-test="deferred-income" />' },
}))

vi.mock('@/pages/payroll/EmploymentDimensionsPanel.vue', () => ({
  default: { name: 'EmploymentDimensionsPanel', props: ['employmentId', 'canWrite'], template: '<div />' },
}))

vi.mock('@/pages/payroll/EmploymentSurchargePolicyPanel.vue', () => ({
  default: { name: 'EmploymentSurchargePolicyPanel', props: ['employmentId', 'canWrite'], template: '<div />' },
}))

vi.mock('@/pages/payroll/EmploymentExitDocumentsPanel.vue', () => ({
  default: { name: 'EmploymentExitDocumentsPanel', props: ['employment', 'canWrite', 'terminationReasonKind', 'employeeStatedReason'], template: '<div />' },
}))

vi.mock('@/pages/payroll/EmploymentWageStatementPanel.vue', () => ({
  default: { name: 'EmploymentWageStatementPanel', props: ['employmentId', 'canWrite'], template: '<div />' },
}))

vi.mock('@/pages/payroll/EmploymentRegistrationPanel.vue', () => ({
  default: { name: 'EmploymentRegistrationPanel', props: ['employmentId', 'personId', 'canWrite', 'a2Prefill', 'terminationEndDate', 'masterDataVersion'], template: '<div />' },
}))

vi.mock('@/pages/payroll/EmploymentTerminationPanel.vue', () => ({
  default: { name: 'EmploymentTerminationPanel', props: ['employmentId', 'canWrite'], emits: ['loaded'], template: '<div />' },
}))

const toastMocks = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMocks,
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmploymentCard from '@/pages/payroll/EmploymentCard.vue'
import { resetPayrollOffices } from '@/composables/usePayrollOffices'
import { resetPayrollJmhzOptions } from '@/composables/usePayrollJmhzOptions'

/**
 * Karta má víc SearchableSelectů (účtárna, obec) — hledání podle jména
 * komponenty trefí ten první, takže se rozlišují podle `data-test`.
 */
function selectByTest(wrapper: VueWrapper, test: string) {
  const found = wrapper.findAllComponents({ name: 'SearchableSelect' })
    .find(component => component.attributes('data-test') === test)
  if (!found) throw new Error(`SearchableSelect [data-test="${test}"] nenalezen`)
  return found
}

const actionBarStub = {
  ActionBar: {
    props: ['actions'],
    template: '<div data-test="actions"><button v-for="action in actions" v-show="action.show" :key="action.key" type="button" :data-test="`action-${action.key}`" @click="action.run && action.run()">{{ action.label }}</button></div>',
  },
}

/**
 * Karta je od téhle verze rovnou editovatelná — nic se neotevírá tlačítkem.
 * Po mountu se ale čeká na číselníky (účtárny, JMHZ), takže se `flushPromises`
 * dělá vždycky; jinak by testy sahaly na prázdné nabídky.
 */
async function mountCard(
  employmentValue: PayrollEmployment = employment(),
  options: Record<string, unknown> = {},
) {
  const wrapper = mount(EmploymentCard, {
    props: { employment: employmentValue, canWrite: true },
    ...options,
  })
  await flushPromises()
  return wrapper
}

function employment(): PayrollEmployment {
  return {
    id: 10,
    tax_declaration: null,
    health_insurer: null,
    employee_id: 20,
    office_id: null,
    office_code: null,
    office_name: null,
    code: 'HPP-1',
    relation_type: 'employment',
    meal_entitlement_basis: 'shift',
    status: 'planned',
    is_primary: true,
    start_date: '2026-01-01',
    actual_start_date: null,
    end_date: null,
    archived_at: null,
    is_legacy_projection: false,
    monthly_gross_minor: 4000000,
    row_version: 1,
    // Nástup jde z plánovaného potvrdit rovnou; předregistrace zůstává volbou.
    allowed_transitions: ['preregistered', 'active', 'no_show'],
    can_delete: true,
    delete_blocker: null,
    delete_cascade: { terms: 1, checklist: 4, events: 1 },
    accounting: {
      gross_debit: '521',
      gross_credit: '331',
      employer_insurance_debit: '524',
      employer_insurance_credit: '336',
    },
    terms: [{
      id: 1,
      office_id: null,
      office_code: null,
      effective_from: '2026-01-01',
      effective_to: null,
      contract_signed_on: null,
      planned_start_on: '2026-01-01',
      actual_start_on: null,
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: null,
      regular_workplace: null,
      jmhz_workplace_municipality_code: null,
      jmhz_workplace_country_code: null,
      jmhz_apz_contribution_status: 'unverified',
      jmhz_apz_instrument_code: null,
      jmhz_functional_benefits_status: 'unverified',
      jmhz_temporary_assignment_status: 'unverified',
      jmhz_orchard_discount_eligible: false,
      jmhz_specific_legal_fact_applies: false,
      jmhz_ozp_employment_support_applies: false,
      jmhz_deep_mining_work_applies: false,
      cz_isco_code: null,
      activity_code: null,
      jmhz_relationship_detail_code: null,
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      social_employer_rate_category: 'ordinary',
      social_employer_rate_category_evidence: null,
      social_part_time_discount_reason: 'none' as const,
      social_part_time_discount_evidence: null,
      social_part_time_discount_notified_on: null,
      tax_declaration_signed: false,
      is_primary: true,
      change_reason: 'Initial',
      row_version: 1,
      created_at: '2026-01-01 00:00:00',
    }],
    checklist: [{
      id: 1,
      phase: 'onboarding',
      item_key: 'employment_contract',
      status: 'pending',
      due_date: '2026-01-01',
      completed_at: null,
      note: null,
      row_version: 1,
    }],
    timeline: [{
      id: 1,
      event_type: 'created',
      from_status: null,
      to_status: 'planned',
      effective_on: '2026-01-01',
      note: null,
      diff: { relation_type: { from: null, to: 'employment' } },
      created_at: '2026-01-01 00:00:00',
    }],
  }
}

describe('ukončení zapsané dopředu', () => {
  /* C-17: vztah s budoucím dnem skončení trvá, karta ho nesmí sbalit jako archiv. */
  it('ukáže „končí" a kartu nechá rozbalenou', async () => {
    const future = new Date()
    future.setMonth(future.getMonth() + 1)
    const endDate = future.toISOString().slice(0, 10)
    const wrapper = await mountCard({ ...employment(), status: 'ended', end_date: endDate })

    expect(wrapper.find('[data-test="employment-ends-on"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employment-toggle"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('skončený vztah dál sbalí', async () => {
    const wrapper = await mountCard({ ...employment(), status: 'ended', end_date: '2020-01-31' })

    expect(wrapper.find('[data-test="employment-ends-on"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="employment-toggle"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('doskok na podklady JMHZ', () => {
  it.each(['office_id', 'weekly_hours', 'social_employer_rate_category', 'social_part_time_discount_reason'])('zvýrazní konkrétní podmínku %s v uzavřeném vztahu', async field => {
    HTMLElement.prototype.scrollIntoView = vi.fn()
    const wrapper = await mountCard({ ...employment(), status: 'ended', relation_type: 'dpc' }, { attachTo: document.body })
    await (wrapper.vm as unknown as { focusSection: (panel: string, field: string) => Promise<void> }).focusSection('employment_terms', field)
    await flushPromises()
    expect(wrapper.get(`[data-a1-field="${field}"]`).classes()).toContain('field-flash')
    wrapper.unmount()
  })

  it('otevře seznam povinností přímo na registraci zdravotního pojištění', async () => {
    HTMLElement.prototype.scrollIntoView = vi.fn()
    const item = employment()
    item.status = 'ended'
    item.checklist[0]!.item_key = 'health_insurance_registration'
    item.checklist[0]!.status = 'completed'
    const wrapper = await mountCard(item, { attachTo: document.body })
    await (wrapper.vm as unknown as { focusSection: (panel: string, field: string) => Promise<void> }).focusSection('employment_checklist', 'health_insurance_registration')
    await flushPromises()
    expect(wrapper.get('[data-a1-field="health_insurance_registration"]').classes()).toContain('field-flash')
    expect(wrapper.get('[data-test="employment-checklist"]').attributes('open')).toBeDefined()
    wrapper.unmount()
  })

  it('otevře ukončený vztah a přesnou výjimku pouze v cílové kartě', async () => {
    HTMLElement.prototype.scrollIntoView = vi.fn()
    const first = await mountCard({ ...employment(), id: 41 }, { attachTo: document.body })
    const second = await mountCard({ ...employment(), id: 42, status: 'ended' }, { attachTo: document.body })
    expect(second.find('[data-test="jmhz-ordinary-profile"]').exists()).toBe(false)
    await (second.vm as unknown as { focusSection: (panel: string, field: string) => Promise<void> })
      .focusSection('jmhz_profile', 'jmhz_deep_mining_work_applies')
    await flushPromises()
    expect(second.get('[data-a1-field="jmhz_deep_mining_work_applies"]').classes()).toContain('field-flash')
    expect(second.get('[data-test="terms-advanced"]').attributes('open')).toBeDefined()
    expect(first.get('[data-a1-field="jmhz_deep_mining_work_applies"]').classes()).not.toContain('field-flash')
    first.unmount()
    second.unmount()
  })
})

describe('EmploymentCard', () => {
  // Nabídky se drží v paměti modulu na celý běh aplikace; mezi případy se
  // musí vyprázdnit, jinak by druhý test dostal seznam z prvního.
  beforeEach(() => {
    resetPayrollOffices()
    resetPayrollJmhzOptions()
    // Volání se musí počítat od nuly: řada případů tvrdí, že se DRUHÁ cesta
    // uložení nepoužila, a nasčítaná volání z předchozího testu by to zakryla.
    // `clearAllMocks` maže jen historii volání, ne návratové hodnoty z továrny.
    vi.clearAllMocks()
    vi.mocked(payrollApi.remunerationOptions).mockResolvedValue({ minimum_hourly_40h_minor: null, ruleset_id: null })
  })

  /**
   * Zaměstnanec převzatý z jiného zpracování dostane výzvu k doplnění úhrnů.
   * Jakmile je někdo doplní, nesmí nad nimi ta výzva viset dál — karta by
   * úkolovala tím, co je hotové.
   */
  it.each([
    [false, 'payroll.people.openings.hint'],
    [true, 'payroll.people.openings.done'],
  ])('u převzatého zaměstnance mluví o úhrnech podle toho, jestli jsou (%s)', async (filled, key) => {
    const wrapper = await mountCard({ ...employment(), start_date: '2025-04-01' }, {
      props: {
        employment: { ...employment(), start_date: '2025-04-01' },
        canWrite: true,
        payrollStartPeriod: '2026-08-01',
      },
      global: {
        stubs: {
          // Panel počátečních stavů si data načítá sám; tady jde jen o to,
          // co kartě ohlásí.
          PayrollOpeningBalancesPanel: {
            emits: ['loaded'],
            template: '<div />',
            // Atrapa je prostý objekt (ne argument `mount`), takže na ni Vue
            // nedosadí svoje typy a `this.$emit` se musí doplnit ručně.
            mounted(this: { $emit: (event: string, value: boolean) => void }) {
              this.$emit('loaded', filled)
            },
          },
        },
      },
    })

    expect(wrapper.get('[data-test="opening-balances-needed"]').text()).toContain(key)
  })

  it('umožní novému zaměstnanci potvrdit nulový počáteční stav bez fiktivních měsíců', async () => {
    const wrapper = await mountCard(employment(), {
      props: {
        employment: { ...employment(), start_date: '2026-08-01' },
        canWrite: true,
        payrollStartPeriod: '2026-08-01',
      },
      global: {
        stubs: {
          PayrollOpeningBalancesPanel: {
            props: ['includePriorMonths', 'firstIncludedMonth'],
            template: '<div data-test="opening-panel" :data-prior="String(includePriorMonths)" :data-first="String(firstIncludedMonth)" />',
          },
        },
      },
    })

    expect(wrapper.find('[data-test="opening-balances-needed"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="opening-panel"]').attributes('data-prior')).toBe('false')
    expect(wrapper.get('[data-test="opening-panel"]').attributes('data-first')).toBe('null')
  })

  /**
   * Blokace mzdového běhu „chybí počáteční roční úhrny" vede na kartu hlavního
   * vztahu (`panel=opening_balances`). Karta na povel doskočí na Počáteční
   * stavy a seznam osob podle `isPrimary` pozná, kterou kartu oslovit.
   */
  it('na povel doskočí na počáteční stavy hlavního vztahu', async () => {
    const wrapper = await mountCard(employment(), {
      props: {
        employment: { ...employment(), start_date: '2026-08-01', is_primary: true },
        canWrite: true,
        payrollStartPeriod: '2026-08-01',
      },
      global: {
        stubs: {
          PayrollOpeningBalancesPanel: { template: '<div />' },
        },
      },
    })
    const target = wrapper.get('[data-test="opening-balances-needed"]').element as HTMLElement
    const scrolled = vi.fn()
    target.scrollIntoView = scrolled
    const exposed = wrapper.vm as unknown as { focusSection: (panel: string) => Promise<void>, isPrimary: boolean }

    await exposed.focusSection('opening_balances')

    expect(exposed.isPrimary).toBe(true)
    expect(scrolled).toHaveBeenCalled()
  })

  it('u převzatého zaměstnance začíná počáteční stav měsícem nástupu', async () => {
    const wrapper = await mountCard(employment(), {
      props: {
        employment: { ...employment(), start_date: '2026-03-10' },
        canWrite: true,
        payrollStartPeriod: '2026-08-01',
      },
      global: {
        stubs: {
          PayrollOpeningBalancesPanel: {
            props: ['includePriorMonths', 'firstIncludedMonth'],
            template: '<div data-test="opening-panel" :data-prior="String(includePriorMonths)" :data-first="String(firstIncludedMonth)" />',
          },
        },
      },
    })

    expect(wrapper.get('[data-test="opening-panel"]').attributes('data-prior')).toBe('true')
    expect(wrapper.get('[data-test="opening-panel"]').attributes('data-first')).toBe('3')
  })

  /**
   * Read-only uživatel vidí totéž, jen nic nepřepíše. Pole zůstávají —
   * zašedlá hodnota se čte líp než tabulka, která ji vypisuje jinde a jinak.
   */
  it('read-only uživateli ukáže údaje zamčené, ne skryté', async () => {
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: false },
    })

    expect(wrapper.text()).toContain('payroll.people.timeline_title')
    expect(wrapper.text()).toContain('payroll.people.checklist.employment_contract')
    expect(
      (wrapper.get('[data-test="terms-monthly-gross"]').element as HTMLInputElement).disabled,
    ).toBe(true)
    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.people.transition.preregistered')
  })

  /**
   * V liště zůstal jen životní cyklus. Úprava podmínek se nezahajuje tlačítkem —
   * pole jsou editovatelná rovnou, takže „Nová verze podmínek" jako akce zmizela
   * a rozhodnutí padá až u Uložit.
   */
  it('v liště nechá jen životní cyklus, ne zahájení úpravy', async () => {
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    const actions = wrapper.get('[data-test="actions"]').text()
    expect(actions).toContain('payroll.people.transition.preregistered')
    expect(actions).toContain('payroll.people.transition.no_show')
    expect(actions).not.toContain('payroll.people.new_terms')
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  /**
   * ⚠️ Jádro karty: běžná úprava NESMÍ zakládat novou verzi podmínek.
   * Kdo si přišel opravit překlep, dřív tím do evidence zapsal změnu podmínek
   * k datu, které si musel vymyslet.
   */
  it('běžnou úpravu uloží jako opravu platné verze, ne jako novou', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    // Dokud není co uložit, lišta se neukazuje — karta nevypadá jako formulář.
    expect(wrapper.find('[data-test="terms-save-bar"]').exists()).toBe(false)

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('37.50')
    expect(wrapper.find('[data-test="terms-save-bar"]').exists()).toBe(true)
    // Datum účinnosti se u opravy vůbec neptá.
    expect(wrapper.find('[data-test="terms-effective-from"]').exists()).toBe(false)

    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.addEmploymentTerms).not.toHaveBeenCalled()
    const [id, rowVersion, payload] = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)!
    expect(id).toBe(10)
    expect(rowVersion).toBe(1)
    expect(payload.weekly_hours).toBe('37.50')
    // Účinnost drží opravovaná verze; klient ji neposílá, aby s ní nešlo hnout.
    expect('effective_from' in payload).toBe(false)
  })

  it('novou verzi založí, jen když si ji uživatel vybere — a vyžádá si datum', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const stored = employment()
    stored.terms[0]!.effective_from = '2099-12-31'
    const wrapper = await mountCard(stored)

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('30.00')
    await wrapper.get('[data-test="save-mode-version"]').setValue()

    // DateInput píše datum v jazyce aplikace; ISO drží skryté pole pro kalendář,
    // které nese i dolní mez (`min` je prop komponenty, ne atribut textového pole).
    const effectiveFrom = wrapper.get('[data-test="terms-effective-from"]')
    expect((effectiveFrom.element as HTMLInputElement).value).toBe('01. 01. 2100')
    const effectiveFromNative = effectiveFrom.element.parentElement!
      .querySelector('input[type="date"]') as HTMLInputElement
    expect(effectiveFromNative.value).toBe('2100-01-01')
    expect(effectiveFromNative.min).toBe('2100-01-01')

    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
    expect(vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2].effective_from)
      .toBe('2100-01-01')
  })

  /**
   * Zúčtované období se přepsat nedá. Server to odmítne kódem, ale samotné
   * „nepovedlo se" by uživatele nechalo tápat — karta proto rovnou přepne na
   * cestu, která projde.
   */
  it('po odmítnutí opravy ze zúčtovaného období přepne na novou verzi', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockRejectedValue({
      response: { data: { error: { code: 'payroll_terms_settled', message: 'Už je zúčtováno.' } } },
    })
    const wrapper = await mountCard()

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('20.00')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('Už je zúčtováno.')
    expect((wrapper.get('[data-test="save-mode-version"]').element as HTMLInputElement).checked)
      .toBe(true)
    expect(wrapper.find('[data-test="terms-effective-from"]').exists()).toBe(true)
  })

  it.each(['employment', 'small_scale_employment', 'dpp', 'dpc'] as const)('uloží hodinovou sazbu a podklad pro %s bez měsíční částky', async (relation) => {
    const stored = employment()
    stored.relation_type = relation
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    expect(wrapper.get('[data-test="terms-remuneration-type"]').text()).toContain(
      `payroll.people.remuneration.hourly_${['dpp', 'dpc'].includes(relation) ? 'reward' : 'wage'}`,
    )
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    expect(wrapper.find('[data-test="terms-monthly-gross"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="terms-hourly-warning"]').text()).toContain('payroll.people.remuneration.calculation_evidence')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('180,50')
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('  Syntetická dohoda 2026  ')
    await wrapper.get('[data-test="terms-remuneration-basis-kind"]').setValue('agreement')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.correctEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({
      remuneration_type: 'hourly', hourly_rate_minor: 18050,
      remuneration_basis: 'Syntetická dohoda 2026', remuneration_basis_kind: 'agreement', monthly_gross_minor: null,
    }))
    expect(payrollApi.addEmploymentTerms).not.toHaveBeenCalled()
  })

  it.each(['dpp', 'dpc'] as const)('u %s předvyplní po přepnutí na hodinovou odměnu podklad dohoda', async (relation) => {
    const stored = employment()
    stored.relation_type = relation
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    expect((wrapper.get('[data-test="terms-remuneration-basis-kind"]').element as HTMLSelectElement).value).toBe('agreement')
  })

  it('chybějící druh podkladu zvýrazní přímo u pole', async () => {
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200')
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('Syntetická smlouva')
    await wrapper.get('[data-test="terms-remuneration-basis-kind"]').setValue('')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    const kind = wrapper.get('[data-test="terms-remuneration-basis-kind"]')
    expect(kind.classes()).toContain('border-danger-500')
    expect(kind.attributes('aria-invalid')).toBe('true')
    expect(kind.element.parentElement?.textContent).toContain('payroll.people.remuneration.basis_kind_required')
    await kind.setValue('contract')
    expect(wrapper.get('[data-test="terms-remuneration-basis-kind"]').classes()).not.toContain('border-danger-500')
  })

  it('u pracovního poměru předvyplní mzdový výměr s datem účinnosti a text sleduje datum, dokud ho nikdo nepřepíše', async () => {
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await flushPromises()
    expect((wrapper.get('[data-test="terms-remuneration-basis-kind"]').element as HTMLSelectElement).value).toBe('wage_statement')
    const basis = () => (wrapper.get('[data-test="terms-remuneration-basis"]').element as HTMLInputElement).value
    expect(basis()).toContain('payroll.people.remuneration.basis_default_wage_statement')
    wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput).vm.$emit('update:modelValue', '2099-03-15')
    await flushPromises()
    expect(basis()).toMatch(/15\D+0?3\D+2099/)
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('Vlastní výměr č. 7')
    wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput).vm.$emit('update:modelValue', '2099-04-01')
    await flushPromises()
    expect(basis()).toBe('Vlastní výměr č. 7')
  })

  it.each(['dpp', 'dpc'] as const)('u %s předvyplní i označení dohody', async (relation) => {
    const stored = employment()
    stored.relation_type = relation
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await flushPromises()
    expect((wrapper.get('[data-test="terms-remuneration-basis"]').element as HTMLInputElement).value)
      .toBe(`payroll.people.remuneration.basis_default_${relation}`)
  })

  it('serverovou chybu s details.field ukáže u pole, otevře podrobnosti a na pole najede', async () => {
    vi.mocked(revealField).mockClear()
    vi.mocked(payrollApi.correctEmploymentTerms).mockRejectedValue({
      response: { status: 422, data: { error: { code: 'validation_failed', message: 'Obec pracoviště není v číselníku.', details: { field: 'work_place' } } } },
    })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('38')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('Obec pracoviště není v číselníku.')
    const note = wrapper.get('[data-test="terms-workplace"] [data-test="field-note-error"]')
    expect(note.text()).toContain('Obec pracoviště není v číselníku.')
    expect(revealField).toHaveBeenCalledWith('[data-test="jmhz-municipality"]', expect.anything())
    expect((wrapper.get('[data-test="terms-advanced"]').element as HTMLDetailsElement).open).toBe(true)
  })

  it('serverovou chybu u základního pole zvýrazní a po opravě hodnoty hlášku u pole schová', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockRejectedValue({
      response: { status: 422, data: { error: { code: 'validation_failed', message: 'Druh činnosti neplatí.', details: { field: 'activity_code' } } } },
    })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('38')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()
    const activity = wrapper.get('[data-test="jmhz-activity-code"]')
    expect(activity.classes()).toContain('border-danger-500')
    expect(activity.attributes('aria-invalid')).toBe('true')
    expect(activity.element.parentElement?.textContent).toContain('Druh činnosti neplatí.')
    await activity.setValue('1')
    expect(wrapper.get('[data-test="jmhz-activity-code"]').classes()).not.toContain('border-danger-500')
  })

  it('chybu bez details.field (starší server) ukáže jen v souhrnném pruhu', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockRejectedValue({
      response: { status: 422, data: { error: { code: 'validation_failed', message: 'Obecná chyba.' } } },
    })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('38')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('Obecná chyba.')
    expect(wrapper.find('[data-test="field-note-error"]').exists()).toBe(false)
  })

  it('varování z uložení ukáže žlutě u pole i souhrnně a hlásí, že uložení proběhlo', async () => {
    toastMocks.warning.mockClear()
    toastMocks.success.mockClear()
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({
      employment: employment(),
      warnings: [{ field: 'activity_code', code: 'codebook_mismatch', message: 'Druh činnosti není v aktuálním číselníku.' }],
    })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('38')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()
    const activity = wrapper.get('[data-test="jmhz-activity-code"]')
    expect(activity.classes()).toContain('border-warning-500')
    expect(activity.element.parentElement?.querySelector('[data-test="field-note-warning"]')?.textContent).toContain('Druh činnosti není v aktuálním číselníku.')
    expect(wrapper.get('[data-test="terms-save-warnings"]').text()).toContain('Druh činnosti není v aktuálním číselníku.')
    expect(toastMocks.warning).toHaveBeenCalledWith(expect.stringContaining('payroll.people.terms_saved_with_warnings'))
    expect(toastMocks.success).not.toHaveBeenCalled()
  })

  it('aktivní předpis základní mzdy nabídne ukončit ke dni před účinností a uložení zopakuje s příznakem', async () => {
    vi.mocked(payrollApi.addEmploymentTerms)
      .mockRejectedValueOnce({ response: { status: 422, data: { error: {
        code: 'validation_failed',
        message: 'Vztah má aktivní předpis Základní mzda.',
        details: { field: 'remuneration_type', code: 'recurring_base_wage_active', recurring_name: 'Základní mzda' },
      } } } })
      .mockResolvedValueOnce({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200')
    wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput).vm.$emit('update:modelValue', '2099-03-01')
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()
    expect(vi.mocked(payrollApi.addEmploymentTerms).mock.calls[0]![2]).not.toHaveProperty('end_recurring_base')
    const confirm = wrapper.get('[data-test="terms-end-recurring-base-confirm"]')
    expect(confirm.text()).toContain('payroll.people.remuneration.end_recurring_base_named')
    expect(confirm.text()).toContain('Základní mzda')
    expect(confirm.text()).toMatch(/28\D+0?2\D+2099/)
    await confirm.trigger('click')
    await flushPromises()
    expect(vi.mocked(payrollApi.addEmploymentTerms).mock.calls[1]![2]).toMatchObject({ remuneration_type: 'hourly', end_recurring_base: true, effective_from: '2099-03-01' })
    expect(wrapper.find('[data-test="terms-end-recurring-base"]').exists()).toBe(false)
  })

  it.each(['', '0', '-1', '180.555', '10000000.01', 'nesjednáno'])('odmítne neplatnou hodinovou sazbu %s před uložením', async (rate) => {
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue(rate)
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('Syntetická smlouva')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('payroll.people.remuneration.hourly_invalid')
    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
  })

  it('vyžaduje podklad a uloží hodinovou sazbu také jako novou účinnou verzi', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    expect(wrapper.get('[data-test="save-mode-version"]').element).toHaveProperty('checked', true)
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200.05')
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('payroll.people.remuneration.basis_required')
    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('Syntetický mzdový výměr')
    await wrapper.get('[data-test="terms-remuneration-basis-kind"]').setValue('')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('payroll.people.remuneration.basis_kind_required')
    await wrapper.get('[data-test="terms-remuneration-basis-kind"]').setValue('wage_statement')
    await wrapper.get('[data-test="save-mode-version"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.addEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({
      remuneration_type: 'hourly', hourly_rate_minor: 20005, monthly_gross_minor: null,
      effective_from: expect.any(String),
    }))
  })

  it('historii ukáže z jednotlivých verzí a při přechodu na měsíční mzdu hodinovou sazbu vymaže', async () => {
    const stored = employment()
    stored.monthly_gross_minor = null
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis: 'Nová dohoda', monthly_gross_minor: null })
    stored.terms.push({ ...stored.terms[0]!, id: 99, remuneration_type: 'monthly', hourly_rate_minor: null, monthly_gross_minor: 4000000, remuneration_basis: 'Původní výměr', effective_from: '2025-01-01', effective_to: '2025-12-31' })
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    expect(wrapper.get('[data-test="terms-hourly-rate"]').element).toHaveProperty('value', '180.50')
    expect(wrapper.findAll('[data-test="terms-history-row"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="terms-history"]').text()).toContain('Původní výměr')
    expect(wrapper.get('[data-test="terms-history"]').text()).toContain('Nová dohoda')
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('monthly')
    await wrapper.get('[data-test="terms-monthly-gross"]').setValue('42000')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.correctEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'monthly', hourly_rate_minor: null, monthly_gross_minor: 4200000 }))
  })

  it('při přechodu z hodinové na měsíční vyžaduje výslovnou částku a nepřevezme starou projekci; nulu přijme', async () => {
    const stored = employment()
    stored.monthly_gross_minor = 4000000
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis: 'Syntetická dohoda', monthly_gross_minor: null })
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('monthly')
    expect(wrapper.get('[data-test="terms-monthly-gross"]').element).toHaveProperty('value', '')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(wrapper.get('[data-test="terms-save-error"]').text()).toContain('payroll.people.remuneration.monthly_required')
    expect(payrollApi.addEmploymentTerms).not.toHaveBeenCalled()
    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
    await wrapper.get('[data-test="terms-monthly-gross"]').setValue('0')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.addEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'monthly', hourly_rate_minor: null, monthly_gross_minor: 0 }))
  })

  it('u stávajícího měsíčního režimu dovolí ponechat mzdu nesjednanou', async () => {
    const stored = employment()
    stored.terms[0]!.remuneration_type = 'monthly'
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-monthly-gross"]').setValue('')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.correctEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'monthly', monthly_gross_minor: null }))
  })

  it('z výslovného režimu nenabízí návrat na dosavadní režim bez určení', async () => {
    for (const mode of ['hourly', 'monthly'] as const) {
      const stored = employment()
      Object.assign(stored.terms[0]!, mode === 'hourly'
        ? { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'agreement', remuneration_basis: 'Syntetická dohoda' }
        : { remuneration_type: 'monthly' })
      const wrapper = await mountCard(stored)
      const select = wrapper.get('[data-test="terms-remuneration-type"]')
      expect(select.text()).not.toContain('payroll.people.remuneration.legacy')
      expect(select.findAll('option').map(option => option.attributes('value'))).toContain('monthly')
    }
    const legacy = await mountCard(employment())
    expect(legacy.get('[data-test="terms-remuneration-type"]').text()).toContain('payroll.people.remuneration.legacy')
  })

  it('u legacy dohody zachová NULL režim a nesjednanou částku; sazbu neodvozuje z pravděpodobného výdělku', async () => {
    const stored = employment()
    stored.relation_type = 'dpp'
    stored.monthly_gross_minor = null
    Object.assign(stored.terms[0]!, { remuneration_type: null, monthly_gross_minor: null, probable_hourly_earning_minor: 18050, probable_earning_rationale: 'Syntetický podklad' })
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    expect(wrapper.get('[data-test="terms-history"]').text()).toContain('payroll.people.remuneration.legacy')
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('20.00')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.correctEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: null, hourly_rate_minor: null, remuneration_basis_kind: null, monthly_gross_minor: null }))
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    expect(wrapper.get('[data-test="terms-hourly-rate"]').element).toHaveProperty('value', '')
    expect(wrapper.get('[data-test="terms-hourly-warning"]').text()).toContain('rate_distinction')
  })

  it('přechod do hodinového režimu zachová i účinnost uprostřed měsíce', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('hourly')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200')
    await wrapper.get('[data-test="terms-remuneration-basis"]').setValue('Syntetická smlouva')
    await wrapper.get('[data-test="terms-remuneration-basis-kind"]').setValue('contract')
    const picker = wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput)
    picker.vm.$emit('update:modelValue', '2099-03-15')
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.addEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'hourly', hourly_rate_minor: 20000, effective_from: '2099-03-15' }))
  })

  it('změnu sazby při zachovaném hodinovém režimu dovolí i uprostřed měsíce', async () => {
    const stored = employment()
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'contract', remuneration_basis: 'Syntetická smlouva', effective_from: '2026-01-15' })
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200')
    const picker = wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput)
    picker.vm.$emit('update:modelValue', '2099-03-15')
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.addEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'hourly', hourly_rate_minor: 20000, effective_from: '2099-03-15' }))
  })

  it('zpřesnění legacy na měsíční režim dovolí i uprostřed měsíce', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()
    await wrapper.get('[data-test="terms-remuneration-type"]').setValue('monthly')
    const picker = wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput)
    picker.vm.$emit('update:modelValue', '2099-03-15')
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.addEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ remuneration_type: 'monthly', effective_from: '2099-03-15' }))
    expect(payrollApi.remunerationOptions).not.toHaveBeenCalled()
  })

  it('minimum načítá jen pro hodinový režim a kratším sjednaným úvazkem nesnižuje 40h spodní mez', async () => {
    const ordinary = await mountCard()
    expect(payrollApi.remunerationOptions).not.toHaveBeenCalled()
    ordinary.unmount()
    const stored = employment()
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'contract', remuneration_basis: 'Syntetická smlouva', weekly_hours: '20.00' })
    vi.mocked(payrollApi.remunerationOptions).mockResolvedValue({ minimum_hourly_40h_minor: 20000, ruleset_id: 'synthetic' })
    const wrapper = await mountCard(stored)
    expect(payrollApi.remunerationOptions).toHaveBeenCalledWith('2026-01-01')
    expect(wrapper.get('[data-test="terms-hourly-minimum-warning"]').text()).toContain('minimum_low')
    expect(wrapper.get('[data-test="terms-hourly-minimum"]').text()).toContain('minimum_context')
  })

  it('při změně účinného roku ignoruje opožděné minimum pro předchozí datum', async () => {
    let resolveOlder!: (value: { minimum_hourly_40h_minor: number; ruleset_id: string }) => void
    let resolveNewer!: (value: { minimum_hourly_40h_minor: number; ruleset_id: string }) => void
    const older = new Promise<{ minimum_hourly_40h_minor: number; ruleset_id: string }>(resolve => { resolveOlder = resolve })
    const newer = new Promise<{ minimum_hourly_40h_minor: number; ruleset_id: string }>(resolve => { resolveNewer = resolve })
    vi.mocked(payrollApi.remunerationOptions).mockImplementation(date => date === '2099-01-01' ? older : date === '2100-01-01' ? newer : Promise.resolve({ minimum_hourly_40h_minor: null, ruleset_id: null }))
    const stored = employment()
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'contract', remuneration_basis: 'Syntetická smlouva' })
    const wrapper = await mountCard(stored)
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('150')
    const picker = wrapper.get('[data-test="terms-effective-from-field"]').findComponent(DateInput)
    picker.vm.$emit('update:modelValue', '2099-01-01')
    await flushPromises()
    picker.vm.$emit('update:modelValue', '2100-01-01')
    await flushPromises()
    resolveNewer({ minimum_hourly_40h_minor: 20000, ruleset_id: 'synthetic-newer' })
    await flushPromises()
    expect(wrapper.get('[data-test="terms-hourly-minimum-warning"]').text()).toContain('2100')
    resolveOlder({ minimum_hourly_40h_minor: 10000, ruleset_id: 'synthetic-older' })
    await flushPromises()
    expect(wrapper.get('[data-test="terms-hourly-minimum-warning"]').text()).toContain('2100')
  })

  it('nedostupné minimum označí jako neověřené a nezablokuje uložení', async () => {
    vi.mocked(payrollApi.remunerationOptions).mockRejectedValue(new Error('Ruleset nedostupný'))
    const stored = employment()
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'contract', remuneration_basis: 'Syntetická smlouva' })
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: stored, warnings: [] })
    const wrapper = await mountCard(stored)
    expect(wrapper.get('[data-test="terms-hourly-minimum-unverified"]').text()).toContain('minimum_unverified')
    await wrapper.get('[data-test="terms-hourly-rate"]').setValue('200')
    await wrapper.get('[data-test="save-mode-correct"]').setValue()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    expect(payrollApi.correctEmploymentTerms).toHaveBeenCalledWith(10, 1, expect.objectContaining({ hourly_rate_minor: 20000 }))
  })

  it('nepovolí změnu hodinové sazby bez zápisového práva', async () => {
    const stored = employment()
    Object.assign(stored.terms[0]!, { remuneration_type: 'hourly', hourly_rate_minor: 20000, remuneration_basis: 'Syntetický výměr' })
    const wrapper = await mountCard(stored, { props: { employment: stored, canWrite: false } })
    expect(wrapper.get('[data-test="terms-remuneration-type"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="terms-hourly-rate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="terms-remuneration-basis"]').attributes('disabled')).toBeDefined()
  })

  it('mzdu vezme jako součást téže změny a pošle ji v haléřích', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    expect((wrapper.get('[data-test="terms-monthly-gross"]').element as HTMLInputElement).value)
      .toBe('40000')
    await wrapper.get('[data-test="terms-monthly-gross"]').setValue('45000,50')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2].monthly_gross_minor)
      .toBe(4500050)
  })

  /* Q8-11: úvazek se zadával jako 9375 místo 93,75 %. */
  it('úvazek ukáže i přijme v procentech s desetinnou čárkou', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    expect((wrapper.get('[data-test="terms-workload"]').element as HTMLInputElement).value).toBe('100')
    await wrapper.get('[data-test="terms-workload"]').setValue('93,75')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2].workload_basis_points)
      .toBe(9375)
  })

  /**
   * Pravděpodobný výdělek (§ 355 ZP) je výjimka, ne běžný krok: u HPP se průměr
   * spočítá z uzavřených běhů sám. Pole proto na kartě není vidět, dokud si ho
   * účetní nevyžádá — běžná cesta zůstává stejně dlouhá.
   */
  it('pravděpodobný výdělek nabídne až na vyžádání a pošle ho v haléřích', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    expect(wrapper.find('[data-test="terms-probable-earning-hourly"]').exists()).toBe(false)
    await wrapper.get('[data-test="terms-probable-earning-open"]').trigger('click')
    await wrapper.get('[data-test="terms-probable-earning-hourly"]').setValue('280,50')
    await wrapper.get('[data-test="terms-probable-earning-rationale"]')
      .setValue('Sjednaná odměna za úkol.')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.probable_hourly_earning_minor).toBe(28050)
    expect(payload?.probable_earning_rationale).toBe('Sjednaná odměna za úkol.')
  })

  /** § 355 odst. 2 ZP — částka bez odůvodnění neprojde ani serverem, ani DB. */
  it('pravděpodobný výdělek bez odůvodnění neodešle a řekne proč', async () => {
    const wrapper = await mountCard()

    await wrapper.get('[data-test="terms-probable-earning-open"]').trigger('click')
    await wrapper.get('[data-test="terms-probable-earning-hourly"]').setValue('280')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="terms-save-error"]').text())
      .toContain('payroll.people.probable_earning_rationale_required')
  })

  it('nesmyslnou mzdu neodešle a řekne proč', async () => {
    const wrapper = await mountCard()

    await wrapper.get('[data-test="terms-monthly-gross"]').setValue('čtyřicet tisíc')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="terms-save-error"]').text())
      .toContain('payroll.people.gross_invalid')
  })

  /**
   * Režim stravování má vlastní endpoint, ale pro uživatele je to jedno pole
   * mezi ostatními — ukládá se tímtéž tlačítkem a podmínky pak musí jet
   * s NOVOU verzí řádku, jinak by druhý zápis spadl na konflikt verzí.
   */
  it('uloží režim stravování i podmínky jedním tlačítkem a v pořadí', async () => {
    const afterMeal = { ...employment(), meal_entitlement_basis: 'calendar_day' as const, row_version: 2 }
    vi.mocked(payrollApi.setEmploymentMealEntitlementBasis).mockResolvedValue(afterMeal)
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: afterMeal, warnings: [] })
    const wrapper = await mountCard()

    await wrapper.get('[data-test="employment-meal-entitlement-basis"]').setValue('calendar_day')
    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('38.00')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.setEmploymentMealEntitlementBasis)
      .toHaveBeenCalledWith(10, 1, 'calendar_day')
    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[1]).toBe(2)
  })

  it('zahodí rozdělanou změnu a lištu schová', async () => {
    const wrapper = await mountCard()

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('12.00')
    expect(wrapper.find('[data-test="terms-save-bar"]').exists()).toBe(true)

    await wrapper.get('[data-test="terms-discard"]').trigger('click')
    expect(wrapper.find('[data-test="terms-save-bar"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="terms-weekly-hours"]').element as HTMLInputElement).value)
      .toBe('40.00')
  })

  /**
   * Prohlášení k dani nesmí jít zadat na dvou místech. Karta ho jen ukazuje
   * a vede tam, kde se nastavuje — jinak se rozejde se zákonnou evidencí
   * a mzdový běh spadne na `tax_declaration_term_conflict`.
   */
  it('prohlášení k dani a pojišťovnu jen ukazuje a odkazuje do evidence', async () => {
    const wrapper = await mountCard({
      ...employment(),
      tax_declaration: { status: 'signed' as const, effective_from: '2026-01-01', effective_to: null },
      health_insurer: { status: 'verified' as const, code: '111', effective_from: '2026-01-01' },
    })

    expect(wrapper.get('[data-test="employment-tax-declaration"]').text())
      .toContain('payroll.people.tax_declaration_state.signed')
    expect(wrapper.get('[data-test="employment-health-insurer"]').text()).toContain('111')
    // Editovatelné zaškrtávátko pro prohlášení na kartě není nikde.
    expect(wrapper.find('input[type="checkbox"][name="tax_declaration_signed"]').exists())
      .toBe(false)

    await wrapper.get('[data-test="employment-tax-declaration-link"]').trigger('click')
    expect(wrapper.emitted('focusStatutoryEvidence')).toHaveLength(1)
  })

  /** UI-4: pojišťovna platná až od nástupu se ukazuje s datem „od", ne jako nezadaná. */
  it('pojišťovnu platnou od budoucího nástupu ukáže s datem od', async () => {
    const wrapper = await mountCard({
      ...employment(),
      health_insurer: { status: 'verified' as const, code: '111', effective_from: '2099-10-01' },
    })

    const insurer = wrapper.get('[data-test="employment-health-insurer"]').text()
    expect(insurer).toContain('payroll.people.health_insurer_from')
    expect(insurer).not.toContain('payroll.people.health_insurer_state.missing')
  })

  it('bez záznamu v evidenci hlásí nezadáno, ne mlčky nepodepsáno', async () => {
    const wrapper = await mountCard()

    expect(wrapper.get('[data-test="employment-tax-declaration"]').text())
      .toContain('payroll.people.tax_declaration_state.missing')
    expect(wrapper.get('[data-test="employment-health-insurer"]').text())
      .toContain('payroll.people.health_insurer_state.missing')
  })

  it('edituje JMHZ evidenci jako tri-state a čte APZ z připnutých možností', async () => {
    const wrapper = await mountCard()

    expect(wrapper.find('[data-test="jmhz-evidence"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('yes')
    expect(wrapper.get('[data-test="jmhz-apz-instrument"]').text()).toContain('1 · VPP')
    await wrapper.get('[data-test="jmhz-apz-instrument"]').setValue('1')
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('no')
    expect(wrapper.find('[data-test="jmhz-apz-instrument"]').exists()).toBe(false)
  })

  it('u druhu činnosti mimo pojištění a u vězně vysvětlí formulář hlášení', async () => {
    const wrapper = await mountCard()

    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('1')
    await wrapper.get('[data-test="jmhz-relationship-detail"]').setValue('1')
    expect(wrapper.find('[data-test="jmhz-form-hint"]').exists()).toBe(false)

    await wrapper.get('[data-test="jmhz-relationship-detail"]').setValue('2')
    expect(wrapper.get('[data-test="jmhz-form-hint"]').text()).toBe('payroll.people.jmhz_evidence.form_hint_prisoner')

    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('13')
    await wrapper.get('[data-test="jmhz-activity-code"]').trigger('change')
    expect(wrapper.get('[data-test="jmhz-form-hint"]').text()).toBe('payroll.people.jmhz_evidence.form_hint_outside_insurance')
  })

  it('běžný vztah nemá JMHZ výjimku a změnu uloží jen jednou do účinných podmínek', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    const profile = wrapper.get('[data-test="jmhz-ordinary-profile"]')
    const checks = profile.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(4)
    expect(checks.every(check => !(check.element as HTMLInputElement).checked)).toBe(true)

    await checks[3].setValue(true)
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_deep_mining_work_applies).toBe(true)
    expect(payload?.jmhz_specific_legal_fact_applies).toBe(false)
  })

  /**
   * Srážku podle § 6 odst. 4 písm. b) ZDP určuje jen úhrn příjmů od plátce
   * v měsíci, ne účast na nemocenském pojištění. Pole „prohlášení plátce
   * o účasti“ proto karta nenabízí u žádného druhu vztahu.
   */
  it.each(['statutory_body', 'dpc', 'partner_dependent', 'employment', 'small_scale_employment', 'dpp'] as const)(
    'nenabízí prohlášení plátce k srážkové dani u %s',
    async relationType => {
      const wrapper = await mountCard({ ...employment(), relation_type: relationType })

      expect(wrapper.find('[data-test="other-withholding-eligibility"]').exists()).toBe(false)
    },
  )

  it('při uložení podmínek už prohlášení plátce k srážkové dani neposílá', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    const checks = wrapper.get('[data-test="jmhz-ordinary-profile"]').findAll('input[type="checkbox"]')
    await checks[3].setValue(true)
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload).toBeDefined()
    expect(payload).not.toHaveProperty('other_withholding_eligibility')
  })

  /**
   * Zvýšená sazba § 5a odst. 1 písm. b) a c) platí jen doloženému zařazení.
   * Na podklad se karta proto ptá teprve tehdy, když si kategorii někdo
   * vybral — u běžné sazby by chtěla doklad, který neexistuje.
   */
  it('u zvýšené sazby zaměstnavatele se doptá na podklad, u běžné ne', async () => {
    const wrapper = await mountCard()

    const category = wrapper.get('[data-test="social-employer-rate-category"]')
    expect((category.element as HTMLSelectElement).value).toBe('ordinary')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(false)

    await category.setValue('risk_employment')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(true)

    await category.setValue('ordinary')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(false)
  })

  it('uloží zvýšenou sazbu a slevu i bez volitelných odkazů na podklady', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    await wrapper.get('[data-test="social-employer-rate-category"]').setValue('risk_employment')
    await wrapper.get('[data-test="social-part-time-discount-reason"]').setValue('under_21')
    await wrapper.get('[data-test="terms-change-reason"]').setValue('Změna ověřených podmínek')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.social_employer_rate_category).toBe('risk_employment')
    expect(payload?.social_employer_rate_category_evidence).toBeNull()
    expect(payload?.social_part_time_discount_reason).toBe('under_21')
    expect(payload?.social_part_time_discount_evidence).toBeNull()
  })

  /**
   * JMHZ 10274: záchranář (6) a HZS podniku (7) sdílí sazbu písm. b), hlášení
   * je ale rozlišuje. U písm. c) je kód pevný a karta jen řekne, co se vykáže.
   */
  it('u písm. b) se zeptá na kategorizaci rizika a pošle ji, u písm. c) jen vysvětlí', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    expect(wrapper.find('[data-test="jmhz-risk-categorization"]').exists()).toBe(false)
    await wrapper.get('[data-test="social-employer-rate-category"]').setValue('risk_employment')
    expect(wrapper.find('[data-test="jmhz-risk-categorization"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="jmhz-risk-categorization-auto"]').exists()).toBe(true)

    await wrapper.get('[data-test="social-employer-rate-category"]').setValue('rescue_and_company_fire_service')
    await wrapper.get('[data-test="jmhz-risk-categorization"]').setValue('7')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.social_employer_rate_category).toBe('rescue_and_company_fire_service')
    expect(payload?.jmhz_risk_categorization_code).toBe('7')
  })

  /**
   * Dočasné přidělení (agentura práce): hlášení chce uživatele (kontrola 103).
   * Karta se na něj ptá až u „ano" a pole druhého druhu uživatele vyčistí.
   */
  it('u dočasného přidělení se zeptá na uživatele a pošle jen pole zvoleného druhu', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    expect(wrapper.find('[data-test="jmhz-assignment-user"]').exists()).toBe(false)
    await wrapper.get('[data-test="jmhz-temporary-assignment"]').setValue('yes')
    await wrapper.get('[data-test="jmhz-assignment-user-kind"]').setValue('foreign')
    await wrapper.get('[data-test="jmhz-assignment-user-foreign-id"]').setValue('12345678')
    await wrapper.get('[data-test="jmhz-assignment-user-name"]').setValue('Synthetische Nutzer GmbH')
    await wrapper.get('[data-test="jmhz-assignment-user-kind"]').setValue('ico')
    await wrapper.get('[data-test="jmhz-assignment-user-ico"]').setValue('00000027')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_temporary_assignment_status).toBe('yes')
    expect(payload?.jmhz_assignment_user_kind).toBe('ico')
    expect(payload?.jmhz_assignment_user_ico).toBe('00000027')
    expect(payload?.jmhz_assignment_user_foreign_id).toBeNull()
    expect(payload?.jmhz_assignment_user_name).toBeNull()

    await wrapper.get('[data-test="jmhz-temporary-assignment"]').setValue('no')
    expect(wrapper.find('[data-test="jmhz-assignment-user"]').exists()).toBe(false)
  })

  it('řídí 10502 podle serverové politiky a pro S nastaví pevné Žádné', async () => {
    const wrapper = await mountCard()

    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('1')
    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-relationship-detail"]').setValue('1')
    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('A')
    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(false)
    await wrapper.get('[data-test="jmhz-activity-code"]').setValue('S')
    const fixedDetail = wrapper.get('[data-test="jmhz-relationship-detail"]')
    expect((fixedDetail.element as HTMLSelectElement).value).toBe('1')
    expect((fixedDetail.element as HTMLSelectElement).disabled).toBe(true)
  })

  /**
   * Srovnání historického 10502 podle číselníku není změna uživatele — nesmí
   * proto samo vyvolat lištu s Uložit. Neplatný kód se srovná při nejbližším
   * skutečném zápisu.
   */
  it('historické 10502 srovná tiše a odešle až s první skutečnou změnou', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const legacy = employment()
    legacy.terms[0]!.activity_code = 'A'
    legacy.terms[0]!.jmhz_relationship_detail_code = '1'
    const wrapper = await mountCard(legacy)

    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="terms-save-bar"]').exists()).toBe(false)

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('35.00')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
      .jmhz_relationship_detail_code).toBeNull()
  })

  it('vybere obec atomicky z připnutého CISOB a odešle kanonický název i kód', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    const municipality = selectByTest(wrapper, 'jmhz-municipality')
    municipality.vm.$emit('search', 'Praha')
    await flushPromises()
    municipality.vm.$emit('update:modelValue', '554782')
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_workplace_municipality_code).toBe('554782')
    expect(payload?.work_place).toBe('Hlavní město Praha')
    expect(payload?.jmhz_workplace_country_code).toBe('CZ')
  })

  /**
   * Vymazání obce musí sundat i název a stát — jinak by v podmínkách zůstal
   * text pracoviště bez kódu, se kterým podání JMHZ neprojde. Vlastní případ:
   * po úspěšném uložení se karta překreslí z odpovědi serveru, takže „vyplnit
   * a hned smazat" v jednom testu měří jen to překreslení.
   */
  it('vymazání obce sundá i název a stát pracoviště', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const stored = employment()
    stored.terms[0]!.jmhz_workplace_municipality_code = '554782'
    stored.terms[0]!.work_place = 'Hlavní město Praha'
    stored.terms[0]!.jmhz_workplace_country_code = 'CZ'
    const wrapper = await mountCard(stored)

    selectByTest(wrapper, 'jmhz-municipality').vm.$emit('update:modelValue', null)
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    const cleared = vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(cleared?.jmhz_workplace_municipality_code).toBeNull()
    expect(cleared?.work_place).toBeNull()
    expect(cleared?.jmhz_workplace_country_code).toBeNull()
  })

  it('nabídne smazání vztahu v „…" a v potvrzení jmenuje, co přesně zmizí', async () => {
    vi.mocked(payrollApi.deleteEmployment).mockResolvedValue({})
    const confirm = vi.spyOn(window, 'confirm')
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="action-delete-employment"]').trigger('click')
    await flushPromises()

    const message = document.body.querySelector('[data-test="employment-confirm-message"]')?.textContent ?? ''
    expect(message).toContain('payroll.people.delete.confirm')
    expect(message).toContain('cascade.checklist')
    expect(message).toContain('cascade.terms')
    expect(payrollApi.deleteEmployment).not.toHaveBeenCalled()
    document.body.querySelector<HTMLButtonElement>('[data-test="employment-confirm-ok"]')!.click()
    await flushPromises()
    expect(confirm).not.toHaveBeenCalled()
    expect(payrollApi.deleteEmployment).toHaveBeenCalledWith(10, 1)
    expect(wrapper.emitted('deleted')).toEqual([[10]])
    confirm.mockRestore()
    wrapper.unmount()
  })

  /* PREZEC osoby C se neobjevil ve frontě: předregistrace podání nezakládá. */
  it('po předregistraci řekne, že PREZEC se připraví v panelu registrace, a sjede na něj', async () => {
    const planned = employment()
    planned.status = 'planned'
    planned.allowed_transitions = ['preregistered']
    vi.mocked(payrollApi.transitionEmployment).mockReset().mockResolvedValue({ ...planned, status: 'preregistered' })
    const wrapper = await mountCard(planned, {
      props: { employment: planned, canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="action-transition-preregistered"]').trigger('click')
    await flushPromises()

    expect(payrollApi.transitionEmployment).toHaveBeenCalledTimes(1)
    expect(toastMocks.info).toHaveBeenCalledWith('payroll.people.card_confirm.preregistered_next')
    expect(vi.mocked(revealField).mock.calls.some(([selector]) => selector === '[data-test="employment-registration"]')).toBe(true)
    wrapper.unmount()
  })

  /*
   * Q8-42: ukončení vztahu se potvrzovalo nativním `window.confirm` a panel
   * Skončení vztahu (důvod, dohoda, odstupné) se musel hledat až potom.
   */
  it('ukončení vztahu potvrdí dialog aplikace a karta pak sjede na Skončení vztahu', async () => {
    const ended = { ...employment(), status: 'ended' as const, end_date: '2026-10-31' }
    vi.mocked(payrollApi.transitionEmployment).mockReset().mockResolvedValue(ended)
    const nativeConfirm = vi.spyOn(window, 'confirm')
    const active = employment()
    active.allowed_transitions = ['ended']
    const wrapper = await mountCard(active, {
      props: { employment: active, canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="action-transition-ended"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="employment-confirm-hint"]')?.textContent)
      .toContain('payroll.people.card_confirm.end_hint')
    document.body.querySelector<HTMLButtonElement>('[data-test="employment-confirm-cancel"]')!.click()
    await flushPromises()
    expect(payrollApi.transitionEmployment).not.toHaveBeenCalled()

    await wrapper.get('[data-test="action-transition-ended"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLButtonElement>('[data-test="employment-confirm-ok"]')!.click()
    await flushPromises()
    expect(nativeConfirm).not.toHaveBeenCalled()
    expect(payrollApi.transitionEmployment).toHaveBeenCalledTimes(1)
    expect(vi.mocked(revealField).mock.calls.some(([selector]) => selector === '[data-test="employment-termination"]')).toBe(true)
    nativeConfirm.mockRestore()
    wrapper.unmount()
  })

  it('důvod, proč smazat nejde, řekne až při pokusu', async () => {
    toastMocks.error.mockClear()
    const blocked: PayrollEmployment = {
      ...employment(),
      can_delete: false,
      delete_blocker: {
        code: 'payroll_employment_in_run',
        message: 'Pracovní vztah je zahrnutý v revizi mzdového běhu.',
        employment_id: 10,
        employment_code: 'HPP-1',
      },
      delete_cascade: {},
    }
    const wrapper = await mountCard(blocked, {
      props: { employment: blocked, canWrite: true },
      global: { stubs: actionBarStub },
    })

    // Trvalý odstavec pod kartou vysvětloval něco, co uživatel zrovna nedělá;
    // akce se proto nabízí a důvod přijde na kliknutí.
    expect(wrapper.find('[data-test="employment-delete-blocker"]').exists()).toBe(false)
    await wrapper.get('[data-test="action-delete-employment"]').trigger('click')
    expect(toastMocks.error)
      .toHaveBeenCalledWith('Pracovní vztah je zahrnutý v revizi mzdového běhu.')
    // `no_show` zůstává — je to jiný případ, ne náhrada za mazání.
    expect(wrapper.get('[data-test="actions"]').text())
      .toContain('payroll.people.transition.no_show')
  })

  it('u převzatého vztahu skryje interní značky a registraci nabídne s varováním', async () => {
    const legacy: PayrollEmployment = {
      ...employment(),
      code: 'legacy',
      is_legacy_projection: true,
      checklist: [{
        id: 2,
        phase: 'onboarding',
        item_key: 'social_jmhz_registration',
        status: 'pending',
        due_date: '2026-01-01',
        completed_at: null,
        note: null,
        row_version: 1,
      }],
      timeline: [{
        id: 1,
        event_type: 'created',
        from_status: null,
        to_status: 'planned',
        effective_on: '2026-01-01',
        note: 'Legacy projekce',
        diff: null,
        created_at: '2026-01-01T00:00:00Z',
      }],
    }
    const wrapper = await mountCard(legacy, {
      props: { employment: legacy, canWrite: true },
      global: { stubs: actionBarStub },
    })

    // Kód `legacy` je interní značka převodu, ne údaj zaměstnavatele.
    expect(wrapper.find('[data-test="employment-code"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Legacy projekce')
    expect(wrapper.text()).not.toContain('payroll.people.legacy_projection')
    // Zákonná povinnost se neskrývá, jen se k ní přidá varování.
    expect(wrapper.get('[data-test="legacy-registration-warning"]').text())
      .toContain('payroll.people.registration_legacy_warning')
    expect(wrapper.findComponent({ name: 'EmploymentRegistrationPanel' }).exists()).toBe(true)
  })

  it('odhadnutý nástup ukáže s vysvětlením a jde potvrdit', async () => {
    const estimated: PayrollEmployment = { ...employment(), start_estimated: true, row_version: 4 }
    const confirmed: PayrollEmployment = { ...estimated, start_estimated: false, row_version: 5 }
    vi.mocked(payrollApi.confirmEmploymentStart).mockResolvedValue(confirmed)
    const wrapper = await mountCard(estimated, {
      props: { employment: estimated, canWrite: true },
      global: {
        stubs: {
          ...actionBarStub,
          RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)" v-bind="$attrs"><slot /></a>' },
        },
      },
    })

    const banner = wrapper.get('[data-test="employment-start-estimated"]')
    expect(banner.text()).toContain('payroll.people.start_estimated.title')
    expect(banner.text()).toContain('payroll.people.start_estimated.hint')
    expect(banner.find('[data-test="employment-start-import"]').exists()).toBe(true)
    await banner.get('[data-test="employment-start-confirm"]').trigger('click')
    await flushPromises()

    expect(payrollApi.confirmEmploymentStart).toHaveBeenCalledWith(estimated.id, 4)
    expect(wrapper.emitted('updated')?.at(-1)).toEqual([confirmed])
  })

  it('povinnost vyřízenou předchozím programem neukáže jako nesplněnou ani nenabídne k odškrtnutí', async () => {
    const taken: PayrollEmployment = {
      ...employment(),
      is_legacy_projection: true,
      checklist: [{
        id: 2,
        phase: 'onboarding',
        item_key: 'social_jmhz_registration',
        status: 'pending',
        effective_status: 'completed',
        effective_reason: 'predecessor',
        due_date: null,
        completed_at: null,
        note: null,
        row_version: 1,
      }],
    }
    const wrapper = await mountCard(taken, {
      props: { employment: taken, canWrite: true },
      global: { stubs: actionBarStub },
    })

    expect(wrapper.get('[data-test="checklist-predecessor-social_jmhz_registration"]').text())
      .toContain('payroll.people.checklist_predecessor_handled')
    expect(wrapper.get('[data-test="employment-checklist"]').text()).toContain('payroll.people.checklist_all_done')
    expect(wrapper.find('[data-test="checklist-na-social_jmhz_registration"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="legacy-registration-warning"]').exists()).toBe(false)
  })

  /**
   * Varování bylo slepá ulička — svítilo natrvalo a nedalo se s ním nic udělat,
   * přestože u někoho přihlášeného mimo MyÚčto je jen šum.
   */
  it('varování o dvojí přihlášce jde vyřešit a pak zmizí', async () => {
    const legacy = employment()
    legacy.is_legacy_projection = true
    legacy.checklist = [{
      id: 2,
      phase: 'onboarding',
      item_key: 'social_jmhz_registration',
      status: 'pending',
      due_date: '2026-01-01',
      completed_at: null,
      note: null,
      row_version: 3,
    }]

    const wrapper = await mountCard(legacy, {
      props: { employment: legacy, canWrite: true },
      global: { stubs: actionBarStub },
    })

    const resolved = { ...legacy }
    resolved.checklist = [{ ...legacy.checklist[0], status: 'not_applicable' as const }]
    vi.mocked(payrollApi.updateEmploymentChecklist).mockResolvedValue(resolved)

    await wrapper.get('[data-test="registration-already-done"]').trigger('click')
    await flushPromises()

    expect(payrollApi.updateEmploymentChecklist)
      .toHaveBeenCalledWith(10, 'social_jmhz_registration', {
        row_version: 3,
        status: 'not_applicable',
      })

    await wrapper.setProps({ employment: resolved })
    expect(wrapper.find('[data-test="legacy-registration-warning"]').exists()).toBe(false)
  })

  /*
   * § 38j odst. 3 ZDP: potvrzení o zdanitelných příjmech do 10 dnů od žádosti.
   * Den žádosti se zapisuje přímo u položky checklistu, jinak termín nevznikne.
   */
  it('u potvrzení o příjmech zapíše den žádosti zaměstnance', async () => {
    const ended = employment()
    ended.checklist = [{
      id: 5,
      phase: 'offboarding',
      item_key: 'taxable_income_confirmation',
      status: 'pending',
      due_date: null,
      completed_at: null,
      note: null,
      row_version: 2,
    }]
    const wrapper = await mountCard(ended, {
      props: { employment: ended, canWrite: true },
      global: { stubs: actionBarStub },
    })
    vi.mocked(payrollApi.updateEmploymentChecklist).mockResolvedValue(ended)

    const block = wrapper.get('[data-test="checklist-confirmation-request"]')
    const save = block.get('[data-test="checklist-confirmation-request-save"]')
    expect((save.element as HTMLButtonElement).disabled).toBe(true)

    await block.findComponent(DateInput).vm.$emit('update:modelValue', '2026-09-21')
    await save.trigger('click')
    await flushPromises()

    expect(payrollApi.updateEmploymentChecklist)
      .toHaveBeenCalledWith(10, 'taxable_income_confirmation', {
        row_version: 2,
        status: 'pending',
        requested_on: '2026-09-21',
      })
  })

  /**
   * UX-5: oznámení ZP se dřív skládalo v šesti krocích na stránce ZP. Otevřená
   * povinnost na kartě vede rovnou tam, s obdobím nástupu a pojišťovnou.
   */
  it('u otevřené registrace ZP vede přímo na sestavení oznámení za měsíc nástupu', async () => {
    const hired = employment()
    hired.health_insurer = { status: 'verified', code: '111', effective_from: '2026-01-01' }
    hired.checklist = [{
      id: 6,
      phase: 'onboarding',
      item_key: 'health_insurance_registration',
      status: 'pending',
      due_date: '2026-01-09',
      completed_at: null,
      note: null,
      row_version: 1,
    }]
    const wrapper = await mountCard(hired, {
      props: { employment: hired, canWrite: true },
      global: {
        stubs: {
          ...actionBarStub,
          RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)" v-bind="$attrs"><slot /></a>' },
        },
      },
    })

    const link = wrapper.get('[data-test="checklist-health-notification-health_insurance_registration"]')
    expect(JSON.parse(link.attributes('data-to')!)).toEqual({
      path: '/payroll/submissions/health',
      query: { period: '2026-01', hoz: '1', insurer: '111' },
    })
  })

  /**
   * Časová osa dřív vypisovala hodnoty diffu syrově z databáze, takže uživatel
   * v české aplikaci četl „pending → completed" a „→ partner_dependent".
   */
  it('v časové ose nenechá projít syrovou databázovou hodnotu', async () => {
    const detail = employment()
    detail.timeline = [{
      id: 2,
      event_type: 'checklist_changed',
      from_status: null,
      to_status: null,
      effective_on: '2026-01-05',
      note: null,
      diff: {
        social_jmhz_registration: { from: 'pending', to: 'completed' },
        relation_type: { from: null, to: 'partner_dependent' },
      },
      created_at: '2026-01-05 00:00:00',
    }]

    const text = (await mountCard(detail, {
      props: { employment: detail, canWrite: false },
    })).text()

    expect(text).toContain('payroll.people.checklist_status.pending')
    expect(text).toContain('payroll.people.checklist_status.completed')
    expect(text).toContain('payroll.people.relations.partner_dependent')
    // Syrová podoba by zněla „…social_jmhz_registration: pending → completed";
    // po překladu stojí mezi dvojtečkou a hodnotou vždycky překladový klíč.
    expect(text).not.toContain(': pending')
    expect(text).not.toContain('→ completed')
    expect(text).not.toContain('→ partner_dependent')
  })

  /**
   * Změna mzdy se v časové ose ukládá v haléřích. Syrově vypsaná by z ní
   * udělala „4000000 → 4500000" — číslo, které se musí v hlavě dělit stem.
   */
  it('změnu mzdy vypíše v korunách, ne v haléřích', async () => {
    const detail = employment()
    detail.timeline = [{
      id: 4,
      event_type: 'terms_corrected',
      from_status: null,
      to_status: null,
      effective_on: '2026-01-05',
      note: null,
      diff: { monthly_gross_minor: { from: 4000000, to: 4500000 } },
      created_at: '2026-01-05 00:00:00',
    }]

    const text = (await mountCard(detail, {
      props: { employment: detail, canWrite: false },
    })).text()

    expect(text).toContain('payroll.people.event.terms_corrected')
    expect(text).not.toContain('4000000')
  })

  /**
   * Karta ukazovala deset povinností a deset událostí naráz, takže jeden člověk
   * se dvěma vztahy zabral přes čtyřicet řádků evidence.
   */
  it('povinnosti otevře, jen když je co plnit', async () => {
    const pending = await mountCard(employment(), {
      props: { employment: employment(), canWrite: false },
    })
    expect(pending.get('[data-test="employment-checklist"]').attributes('open')).toBeDefined()

    const doneValue = employment()
    doneValue.checklist = doneValue.checklist.map(item => ({ ...item, status: 'completed' as const }))
    const done = await mountCard(doneValue, {
      props: { employment: doneValue, canWrite: false },
    })
    expect(done.get('[data-test="employment-checklist"]').attributes('open')).toBeUndefined()
  })

  it('časovou osu nechá sbalenou', async () => {
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: false },
    })
    expect(wrapper.get('[data-test="employment-timeline"]').attributes('open')).toBeUndefined()
  })

  it('skončený vztah sbalí celý, aktivní nechá otevřený', async () => {
    const closed = employment()
    closed.status = 'archived'
    closed.end_date = '2026-06-30'
    closed.allowed_transitions = []

    const wrapper = await mountCard(closed, {
      props: { employment: closed, canWrite: true },
      global: { stubs: actionBarStub },
    })

    expect(wrapper.find('[data-test="employment-checklist"]').exists()).toBe(false)
    await wrapper.get('[data-test="employment-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="employment-checklist"]').exists()).toBe(true)

    const open = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })
    expect(open.find('[data-test="employment-toggle"]').exists()).toBe(false)
    expect(open.find('[data-test="employment-checklist"]').exists()).toBe(true)
  })

  /**
   * Nástup starý rok a půl zůstával „plánovaný", protože nikdo neproklikal dva
   * stavové přechody — a tím vztah vypadl i z výplatní listiny.
   */
  it('nástup, který už nastal, potvrdí jedním krokem a k datu nástupu', async () => {
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    const confirm = wrapper.get('[data-test="action-confirm-start"]')
    expect(confirm.text()).toContain('payroll.people.confirm_start')
    // „Zahájit" se vedle toho nenabízí podruhé.
    expect(wrapper.find('[data-test="action-transition-active"]').isVisible()).toBe(false)

    vi.mocked(payrollApi.transitionEmployment).mockResolvedValue(employment())
    await confirm.trigger('click')
    await flushPromises()

    expect(payrollApi.transitionEmployment).toHaveBeenCalledWith(10, 'active', {
      row_version: 1,
      effective_on: '2026-01-01',
    })
  })

  /**
   * UX-7: nový nástup potřeboval rozbalit „Počáteční stavy", uložit prázdnou
   * tabulku a teprve potom potvrdit nástup. Potvrzení teď nulový stav zapíše
   * samo — po výslovném souhlasu, a jen když ještě uložený není.
   */
  it.each([
    [true, 1, 1],
    [false, 0, 0],
  ])('potvrzení nového nástupu zapíše nulový počáteční stav (souhlas %s)', async (agreed, openingCalls, transitionCalls) => {
    vi.mocked(payrollApi.saveStatutoryOpenings).mockReset().mockResolvedValue({ openings: {} } as never)
    vi.mocked(payrollApi.transitionEmployment).mockReset().mockResolvedValue(employment())
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true, payrollStartPeriod: '2025-12-01' },
      global: {
        stubs: {
          ...actionBarStub,
          PayrollOpeningBalancesPanel: {
            emits: ['loaded'],
            template: '<div />',
            mounted(this: { $emit: (event: string, value: boolean) => void }) {
              this.$emit('loaded', false)
            },
          },
        },
      },
    })

    await wrapper.get('[data-test="action-confirm-start"]').trigger('click')
    await flushPromises()

    document.body.querySelector<HTMLButtonElement>(
      agreed ? '[data-test="employment-confirm-ok"]' : '[data-test="employment-confirm-cancel"]',
    )!.click()
    await flushPromises()
    expect(payrollApi.saveStatutoryOpenings).toHaveBeenCalledTimes(openingCalls)
    if (openingCalls > 0) {
      expect(payrollApi.saveStatutoryOpenings).toHaveBeenCalledWith(employment().employee_id, {
        year: 2026,
        source_reference: '',
        months: [],
      })
    }
    expect(payrollApi.transitionEmployment).toHaveBeenCalledTimes(transitionCalls)
    wrapper.unmount()
  })

  it('s uloženým počátečním stavem potvrdí nástup bez dotazu', async () => {
    vi.mocked(payrollApi.saveStatutoryOpenings).mockReset()
    vi.mocked(payrollApi.transitionEmployment).mockReset().mockResolvedValue(employment())
    const confirmSpy = vi.spyOn(window, 'confirm')
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true, payrollStartPeriod: '2025-12-01' },
      global: {
        stubs: {
          ...actionBarStub,
          PayrollOpeningBalancesPanel: {
            emits: ['loaded'],
            template: '<div />',
            mounted(this: { $emit: (event: string, value: boolean) => void }) {
              this.$emit('loaded', true)
            },
          },
        },
      },
    })

    await wrapper.get('[data-test="action-confirm-start"]').trigger('click')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="employment-confirm-message"]')).toBeNull()
    expect(payrollApi.saveStatutoryOpenings).not.toHaveBeenCalled()
    expect(payrollApi.transitionEmployment).toHaveBeenCalledTimes(1)
    confirmSpy.mockRestore()
  })

  /**
   * Obecné „nepovedlo se" bylo slepá ulička: účetní nevěděla, co má změnit, a
   * tiskla totéž tlačítko znovu. Server překážku pojmenuje — musí být vidět.
   */
  it('u odmítnuté změny stavu ukáže důvod ze serveru, ne obecné nepovedlo se', async () => {
    toastMocks.error.mockClear()
    vi.mocked(payrollApi.transitionEmployment).mockRejectedValue({
      response: {
        data: {
          error: {
            code: 'payroll_employment_in_run',
            message: 'Vztah je zahrnutý v uzavřeném mzdovém běhu 8/2026.',
          },
        },
      },
    })

    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })
    await wrapper.get('[data-test="action-confirm-start"]').trigger('click')
    await flushPromises()

    expect(toastMocks.error)
      .toHaveBeenCalledWith('Vztah je zahrnutý v uzavřeném mzdovém běhu 8/2026.')
  })

  it('u nástupu v budoucnu nabídne předregistraci, ne potvrzení', async () => {
    const future = employment()
    future.start_date = '2099-01-01'

    const wrapper = await mountCard(future, {
      props: { employment: future, canWrite: true },
      global: { stubs: actionBarStub },
    })

    expect(wrapper.find('[data-test="action-confirm-start"]').isVisible()).toBe(false)
    expect(wrapper.get('[data-test="action-transition-preregistered"]').isVisible()).toBe(true)
  })

  /**
   * Kód se generuje sám, ale je to párovací klíč CSV importu docházky —
   * kdo importuje, musí ho umět srovnat s tím, co posílá druhá strana.
   */
  it('označení pro import docházky jde změnit', async () => {
    const wrapper = await mountCard(employment(), {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    expect(wrapper.find('[data-test="employment-rename"]').exists()).toBe(false)
    await wrapper.get('[data-test="action-rename-employment"]').trigger('click')
    await wrapper.get('[data-test="employment-code-input"]').setValue('DOCHAZKA-7')

    vi.mocked(payrollApi.renameEmployment).mockResolvedValue(employment())
    await wrapper.get('[data-test="employment-rename"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.renameEmployment).toHaveBeenCalledWith(10, 1, 'DOCHAZKA-7')
  })

  it('česky vysvětlí zámek režimu stravování po schváleném příspěvku', async () => {
    vi.mocked(payrollApi.setEmploymentMealEntitlementBasis).mockRejectedValue({
      response: {
        data: {
          error: {
            code: 'meal_entitlement_basis_locked',
            message: 'Serverová technická zpráva.',
          },
        },
      },
    })
    const wrapper = await mountCard()

    await wrapper.get('[data-test="employment-meal-entitlement-basis"]').setValue('calendar_day')
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="terms-save-error"]').text())
      .toContain('payroll.people.meal_entitlement_basis.locked')
    expect(payrollApi.correctEmploymentTerms).not.toHaveBeenCalled()
  })

  /**
   * Archiv býval slepá ulička — omylem archivovaný vztah šel jen smazat,
   * a to u vztahu s navázanými mzdami nejde vůbec.
   */
  it('archivovaný vztah nabídne návrat pod jménem, které uživatel hledá', async () => {
    const archived = employment()
    archived.status = 'archived'
    archived.end_date = '2026-06-30'
    // Server vybírá cíl podle historie — karta z něj dělá jedno tlačítko.
    archived.allowed_transitions = ['ended']

    const wrapper = await mountCard(archived, {
      props: { employment: archived, canWrite: true },
      global: { stubs: actionBarStub },
    })
    await wrapper.get('[data-test="employment-toggle"]').trigger('click')

    const action = wrapper.get('[data-test="action-transition-ended"]')
    expect(action.text()).toBe('payroll.people.transition.unarchive')
    expect(action.text()).not.toContain('payroll.people.transition.ended')

    // Návrat z archivu nic neukončuje, takže se neptá „Ukončit vztah?".
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    await action.trigger('click')
    expect(confirmSpy).not.toHaveBeenCalled()
    expect(document.body.querySelector('[data-test="employment-confirm-message"]')).toBeNull()
    confirmSpy.mockRestore()
  })

  it('změnu stavu neukáže dvakrát — hlavička ji už nese', async () => {
    const detail = employment()
    detail.timeline = [{
      id: 3,
      event_type: 'status_changed',
      from_status: 'active',
      to_status: 'ended',
      effective_on: '2026-06-30',
      note: null,
      diff: { status: { from: 'active', to: 'ended' } },
      created_at: '2026-06-30 00:00:00',
    }]

    const text = (await mountCard(detail, {
      props: { employment: detail, canWrite: false },
    })).text()

    expect(text).toContain('payroll.people.employment_status.ended')
    expect(text).not.toContain('payroll.people.term_field.status')
  })

  /**
   * Účtárnu nešlo vybrat NIKDE ve frontendu — karta ji jen vypisovala, přestože
   * na ni míří blokátor běhu `employment_without_office`.
   */
  it('nabídne výběr mzdové účtárny mezi základními údaji a pošle ji na server', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    const office = selectByTest(wrapper, 'terms-office')
    // Deaktivovaná účtárna se nenabízí — vybrat jde jen aktivní.
    expect(office.props('options')).toEqual([
      { value: 7, label: 'Praha', secondary: 'PHA' },
    ])
    office.vm.$emit('update:modelValue', 7)
    await flushPromises()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2].office_id).toBe(7)
  })

  /**
   * Chybějící účtárna je UPOZORNĚNÍ, ne zákaz: karta řekne, co kvůli ní nepůjde,
   * ale nic na ní nezablokuje. Stojí hned u pole, kterým se to spraví.
   */
  it('u vztahu bez účtárny varuje, u vztahu s účtárnou mlčí', async () => {
    const without = await mountCard()
    expect(without.find('[data-test="employment-office-missing"]').exists()).toBe(true)

    const withOffice = await mountCard({
      ...employment(),
      office_id: 7,
      office_code: 'PHA',
      office_name: 'Praha',
    })
    expect(withOffice.find('[data-test="employment-office-missing"]').exists()).toBe(false)
  })

  /**
   * Důvod změny bere server jako volitelný text (`optionalText`, 500 znaků).
   * Formulář ho měl `required`, takže kdo si přišel opravit úvazek, musel napřed
   * vymyslet větu do časové osy.
   */
  it('uloží změnu i bez vyplněného důvodu', async () => {
    vi.mocked(payrollApi.correctEmploymentTerms).mockResolvedValue({ employment: employment(), warnings: [] })
    const wrapper = await mountCard()

    await wrapper.get('[data-test="terms-weekly-hours"]').setValue('39.00')
    const reason = wrapper.get('[data-test="terms-change-reason"]')
    expect(reason.attributes('required')).toBeUndefined()
    await wrapper.get('form[data-test="employment-terms"]').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.correctEmploymentTerms).mock.calls.at(-1)?.[2].change_reason)
      .toBeNull()
  })

  /**
   * Postranní pruh je jeden a týž prvek na všech šířkách — pod zlomem `2xl`
   * jen spadne do toku. Kdyby se vykresloval dvakrát (jednou pro úzké, jednou
   * pro široké), rozešel by se a `data-test` by přestaly být jednoznačné.
   */
  it('rozcestník i zrcadlo evidence drží v jednom postranním pruhu', async () => {
    const wrapper = await mountCard()

    expect(wrapper.findAll('[data-test="employment-rail"]')).toHaveLength(1)
    const rail = wrapper.get('[data-test="employment-rail"]')
    expect(rail.find('[data-test="employment-agendas-stub"]').exists()).toBe(true)
    expect(rail.find('[data-test="employment-person-evidence"]').exists()).toBe(true)
    // Editace zůstává v hlavním sloupci, ne v pruhu.
    expect(rail.find('[data-test="employment-terms"]').exists()).toBe(false)
    // Pruh se ukotví teprve na širokém monitoru; níž je to obyčejný blok toku.
    //
    // ⚠️ Zlom je `xl`, ne `2xl`, a je to měřené rozhodnutí: obrazovka 4096 px
    // při škálování 156 % dá 1454 CSS pixelů, takže na 2xl by pruh nenaskočil
    // ani na 4K monitoru. CSS pixely nejsou fyzické.
    expect(rail.classes()).toContain('xl:sticky')
    // Vyšší než okno se musí dát projet uvnitř, ne uvíznout ukotvený.
    expect(rail.classes().some(name => name.startsWith('xl:max-h-'))).toBe(true)
    expect(rail.classes()).toContain('xl:overflow-y-auto')
  })

  /**
   * Podrobnosti se u běžného pracovního poměru sbalí a otevřou se samy jen tam,
   * kde je někdo vyplnil — jinak by karta začínala dvaceti poli, ze kterých pět
   * lidí ze šesti nepotřebuje ani jedno.
   */
  it('sbalí podrobnosti podmínek, dokud v nich něco není', async () => {
    const plain = await mountCard()
    expect(plain.get('[data-test="terms-advanced"]').attributes('open')).toBeUndefined()

    const filled = employment()
    filled.terms[0]!.regular_workplace = 'Dílna'
    const withDetail = await mountCard(filled)
    expect(withDetail.get('[data-test="terms-advanced"]').attributes('open')).toBeDefined()
  })
})
