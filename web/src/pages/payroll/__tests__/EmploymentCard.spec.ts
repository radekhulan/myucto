import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { payrollApi, type PayrollEmployment } from '@/api/payroll'

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    transitionEmployment: vi.fn(),
    renameEmployment: vi.fn(),
    setEmploymentMealEntitlementBasis: vi.fn(),
    addEmploymentTerms: vi.fn(),
    updateEmploymentChecklist: vi.fn(),
    deleteEmployment: vi.fn(),
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
      ],
      relationship_detail_codes: [{ code: '1', label: 'Žádné' }],
      countries: [{ code: 'CZ', label: 'Česko' }],
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

const toastMocks = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMocks,
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmploymentCard from '@/pages/payroll/EmploymentCard.vue'
import { resetPayrollOffices } from '@/composables/usePayrollOffices'

/**
 * Formulář podmínek má víc SearchableSelectů (účtárna, obec) — hledání podle
 * jména komponenty trefí ten první, takže se rozlišují podle `data-test`.
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


function employment(): PayrollEmployment {
  return {
    id: 10,
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

describe('EmploymentCard', () => {
  // Nabídka účtáren se drží v paměti modulu na celý běh aplikace; mezi případy
  // se musí vyprázdnit, jinak by druhý test dostal seznam z prvního.
  beforeEach(resetPayrollOffices)

  /**
   * Zaměstnanec převzatý z jiného zpracování dostane výzvu k doplnění úhrnů.
   * Jakmile je někdo doplní, nesmí nad nimi ta výzva viset dál — karta by
   * úkolovala tím, co je hotové.
   */
  it.each([
    [false, 'payroll.people.openings.hint'],
    [true, 'payroll.people.openings.done'],
  ])('u převzatého zaměstnance mluví o úhrnech podle toho, jestli jsou (%s)', async (filled, key) => {
    const wrapper = mount(EmploymentCard, {
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
            mounted() { this.$emit('loaded', filled) },
          },
        },
      },
    })
    await flushPromises()

    const notice = wrapper.get('[data-test="opening-balances-needed"]')
    expect(notice.text()).toContain(key)
  })

  it('umožní novému zaměstnanci potvrdit nulový počáteční stav bez fiktivních měsíců', () => {
    const wrapper = mount(EmploymentCard, {
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

  it('u převzatého zaměstnance začíná počáteční stav měsícem nástupu', () => {
    const wrapper = mount(EmploymentCard, {
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

  it('read-only uživateli ukáže historii a checklist, ale žádné mutace', () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: false },
    })

    expect(wrapper.text()).toContain('payroll.people.timeline_title')
    expect(wrapper.text()).toContain('payroll.people.checklist.employment_contract')
    expect(wrapper.find('[data-test="jmhz-identity-on-date"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="jmhz-identity-form"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.people.transition.preregistered')
  })

  it('oprávněnému uživateli sestaví stavové ActionBar akce a datum účinnosti', () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
      global: {
        stubs: {
          ActionBar: {
            props: ['actions'],
            template: '<div data-test="actions"><span v-for="action in actions" v-show="action.show">{{ action.label }}</span></div>',
          },
        },
      },
    })

    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.transition.preregistered')
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.transition.no_show')
    expect(wrapper.get('[data-test="actions"]').text()).toContain('payroll.people.new_terms')
  })

  it('edituje JMHZ evidenci jako tri-state a čte APZ z připnutých možností', async () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-evidence"]').exists()).toBe(true)
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('yes')
    expect(wrapper.get('[data-test="jmhz-apz-instrument"]').text()).toContain('1 · VPP')
    await wrapper.get('[data-test="jmhz-apz-instrument"]').setValue('1')
    await wrapper.get('[data-test="jmhz-apz-status"]').setValue('no')
    expect(wrapper.find('[data-test="jmhz-apz-instrument"]').exists()).toBe(false)
  })

  it('novou verzi nabídne až ode dne následujícího po poslední verzi', async () => {
    const stored = employment()
    stored.terms[0]!.effective_from = '2099-12-31'
    const wrapper = mount(EmploymentCard, {
      props: { employment: stored, canWrite: true },
    })
    await wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )!.trigger('click')
    await flushPromises()

    const effectiveFrom = wrapper.get('[data-test="terms-effective-from"]')
    expect((effectiveFrom.element as HTMLInputElement).value).toBe('2100-01-01')
    expect(effectiveFrom.attributes('min')).toBe('2100-01-01')
  })

  it('běžný vztah nemá JMHZ výjimku a změnu uloží jen jednou do účinných podmínek', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const profile = wrapper.get('[data-test="jmhz-ordinary-profile"]')
    const checks = profile.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(4)
    expect(checks.every(check => !(check.element as HTMLInputElement).checked)).toBe(true)

    await checks[3].setValue(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_deep_mining_work_applies).toBe(true)
    expect(payload?.jmhz_specific_legal_fact_applies).toBe(false)
  })

  /**
   * Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP se ptá jen tam, kde
   * zařazení neplyne ze samotného druhu vztahu. U pracovního poměru by to bylo
   * pole, kterým uživatel nemůže nic změnit — backend u něj posílá `automatic`.
   */
  it.each([
    ['statutory_body', true],
    ['dpc', true],
    ['partner_dependent', true],
    ['employment', false],
    ['small_scale_employment', false],
    ['dpp', false],
  ] as const)('nabídne zařazení pro srážkovou daň jen u %s (%s)', async (relationType, visible) => {
    const wrapper = mount(EmploymentCard, {
      props: {
        employment: { ...employment(), relation_type: relationType },
        canWrite: true,
      },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const field = wrapper.find('[data-test="other-withholding-eligibility"]')
    expect(field.exists()).toBe(visible)
  })

  /**
   * Odpověď „neurčeno" je legitimní stav uložených podmínek, ale nesmí se
   * z formuláře ztratit — jinak by ho uložení shodilo na jinou hodnotu.
   */
  it('předvyplní zařazení pro srážkovou daň z uložených podmínek', async () => {
    const stored = employment()
    stored.relation_type = 'statutory_body'
    stored.terms[0]!.other_withholding_eligibility = 'eligible'
    const wrapper = mount(EmploymentCard, {
      props: { employment: stored, canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    expect(
      (wrapper.get('[data-test="other-withholding-eligibility"]').element as HTMLSelectElement).value,
    ).toBe('eligible')
  })

  /**
   * Zvýšená sazba § 5a odst. 1 písm. b) a c) platí jen doloženému zařazení.
   * Na podklad se karta proto ptá teprve tehdy, když si kategorii někdo
   * vybral — u běžné sazby by chtěla doklad, který neexistuje.
   */
  it('u zvýšené sazby zaměstnavatele se doptá na podklad, u běžné ne', async () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const category = wrapper.get('[data-test="social-employer-rate-category"]')
    expect((category.element as HTMLSelectElement).value).toBe('ordinary')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(false)

    await category.setValue('risk_employment')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(true)

    await category.setValue('ordinary')
    expect(wrapper.find('[data-test="social-employer-rate-category-evidence"]').exists()).toBe(false)
  })

  it('uloží zvýšenou sazbu a slevu i bez volitelných odkazů na podklady', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="social-employer-rate-category"]')
      .setValue('risk_employment')
    await wrapper.get('[data-test="social-part-time-discount-reason"]')
      .setValue('under_21')
    await wrapper.get('textarea').setValue('Změna ověřených podmínek')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.social_employer_rate_category).toBe('risk_employment')
    expect(payload?.social_employer_rate_category_evidence).toBeNull()
    expect(payload?.social_part_time_discount_reason).toBe('under_21')
    expect(payload?.social_part_time_discount_evidence).toBeNull()
  })

  it('řídí 10502 podle serverové politiky a pro S nastaví pevné Žádné', async () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

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

  it('při otevření opravy odstraní historické 10502 u činnosti, která je zakazuje', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const legacy = employment()
    legacy.terms[0]!.activity_code = 'A'
    legacy.terms[0]!.jmhz_relationship_detail_code = '1'
    const wrapper = mount(EmploymentCard, {
      props: { employment: legacy, canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-relationship-detail"]').exists()).toBe(false)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_relationship_detail_code).toBeNull()
  })

  it('vybere obec atomicky z připnutého CISOB a odešle kanonický název i kód', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const municipality = selectByTest(wrapper, 'jmhz-municipality')
    municipality.vm.$emit('search', 'Praha')
    await flushPromises()
    municipality.vm.$emit('update:modelValue', '554782')
    await flushPromises()
    await wrapper.get('textarea').setValue('Ověření pracoviště')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(payload?.jmhz_workplace_municipality_code).toBe('554782')
    expect(payload?.work_place).toBe('Hlavní město Praha')
    expect(payload?.jmhz_workplace_country_code).toBe('CZ')

    const reopen = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await reopen!.trigger('click')
    await flushPromises()
    const reopenedMunicipality = selectByTest(wrapper, 'jmhz-municipality')
    reopenedMunicipality.vm.$emit('search', 'Praha')
    await flushPromises()
    reopenedMunicipality.vm.$emit('update:modelValue', '554782')
    await flushPromises()
    reopenedMunicipality.vm.$emit('update:modelValue', null)
    await flushPromises()
    await wrapper.get('textarea').setValue('Vymazání pracoviště')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const cleared = vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2]
    expect(cleared?.jmhz_workplace_municipality_code).toBeNull()
    expect(cleared?.work_place).toBeNull()
    expect(cleared?.jmhz_workplace_country_code).toBeNull()
  })

  it('nabídne smazání vztahu v „…" a v potvrzení jmenuje, co přesně zmizí', async () => {
    vi.mocked(payrollApi.deleteEmployment).mockResolvedValue({})
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="action-delete-employment"]').trigger('click')
    await flushPromises()

    expect(confirm.mock.calls[0]![0]).toContain('payroll.people.delete.confirm')
    expect(confirm.mock.calls[0]![0]).toContain('cascade.checklist')
    expect(confirm.mock.calls[0]![0]).toContain('cascade.terms')
    expect(payrollApi.deleteEmployment).toHaveBeenCalledWith(10, 1)
    expect(wrapper.emitted('deleted')).toEqual([[10]])
    confirm.mockRestore()
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
    const wrapper = mount(EmploymentCard, {
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

  it('u převzatého vztahu skryje interní značky a registraci nabídne s varováním', () => {
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
    const wrapper = mount(EmploymentCard, {
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

    const wrapper = mount(EmploymentCard, {
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

  /**
   * Časová osa dřív vypisovala hodnoty diffu syrově z databáze, takže uživatel
   * v české aplikaci četl „pending → completed" a „→ partner_dependent".
   */
  it('v časové ose nenechá projít syrovou databázovou hodnotu', () => {
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

    const text = mount(EmploymentCard, {
      props: { employment: detail, canWrite: false },
    }).text()

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
   * Karta ukazovala deset povinností a deset událostí naráz, takže jeden člověk
   * se dvěma vztahy zabral přes čtyřicet řádků evidence.
   */
  it('povinnosti otevře, jen když je co plnit', () => {
    const pending = employment()
    expect(mount(EmploymentCard, { props: { employment: pending, canWrite: false } })
      .get('[data-test="employment-checklist"]').attributes('open')).toBeDefined()

    const done = employment()
    done.checklist = done.checklist.map(item => ({ ...item, status: 'completed' as const }))
    expect(mount(EmploymentCard, { props: { employment: done, canWrite: false } })
      .get('[data-test="employment-checklist"]').attributes('open')).toBeUndefined()
  })

  it('časovou osu nechá sbalenou', () => {
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: false },
    })
    expect(wrapper.get('[data-test="employment-timeline"]').attributes('open')).toBeUndefined()
  })

  it('skončený vztah sbalí celý, aktivní nechá otevřený', async () => {
    const closed = employment()
    closed.status = 'archived'
    closed.end_date = '2026-06-30'
    closed.allowed_transitions = []

    const wrapper = mount(EmploymentCard, {
      props: { employment: closed, canWrite: true },
      global: { stubs: actionBarStub },
    })

    expect(wrapper.find('[data-test="employment-checklist"]').exists()).toBe(false)
    await wrapper.get('[data-test="employment-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="employment-checklist"]').exists()).toBe(true)

    const open = mount(EmploymentCard, {
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
    const wrapper = mount(EmploymentCard, {
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

  it('u nástupu v budoucnu nabídne předregistraci, ne potvrzení', () => {
    const future = employment()
    future.start_date = '2099-01-01'

    const wrapper = mount(EmploymentCard, {
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
    const wrapper = mount(EmploymentCard, {
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

  it('uloží explicitní zákonný režim nároku na stravování', async () => {
    const updated = { ...employment(), meal_entitlement_basis: 'calendar_day' as const }
    vi.mocked(payrollApi.setEmploymentMealEntitlementBasis).mockResolvedValue(updated)
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="employment-meal-entitlement-basis"]')
      .setValue('calendar_day')
    await flushPromises()

    expect(payrollApi.setEmploymentMealEntitlementBasis)
      .toHaveBeenCalledWith(10, 1, 'calendar_day')
    expect(wrapper.emitted('updated')).toEqual([[updated]])
  })

  it('česky vysvětlí zámek režimu po schváleném příspěvku', async () => {
    toastMocks.error.mockClear()
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
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
      global: { stubs: actionBarStub },
    })

    await wrapper.get('[data-test="employment-meal-entitlement-basis"]')
      .setValue('calendar_day')
    await flushPromises()

    expect(toastMocks.error)
      .toHaveBeenCalledWith('payroll.people.meal_entitlement_basis.locked')
    expect(wrapper.emitted('updated')).toBeUndefined()
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

    const wrapper = mount(EmploymentCard, {
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
    confirmSpy.mockRestore()
  })

  it('změnu stavu neukáže dvakrát — hlavička ji už nese', () => {
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

    const text = mount(EmploymentCard, {
      props: { employment: detail, canWrite: false },
    }).text()

    expect(text).toContain('payroll.people.employment_status.ended')
    expect(text).not.toContain('payroll.people.term_field.status')
  })

  /**
   * Účtárnu nešlo vybrat NIKDE ve frontendu — karta ji jen vypisovala, přestože
   * na ni míří blokátor běhu `employment_without_office`. Tohle je ta chybějící
   * cesta: vybraná účtárna musí dojít v podmínkách na server.
   */
  it('nabídne výběr mzdové účtárny a pošle ji v nové verzi podmínek', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const office = selectByTest(wrapper, 'terms-office')
    // Deaktivovaná účtárna se nenabízí — vybrat jde jen aktivní.
    expect(office.props('options')).toEqual([
      { value: 7, label: 'Praha', secondary: 'PHA' },
    ])
    office.vm.$emit('update:modelValue', 7)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2].office_id).toBe(7)
  })

  /**
   * Chybějící účtárna je UPOZORNĚNÍ, ne zákaz: karta řekne, co kvůli ní nepůjde,
   * ale nic na ní nezablokuje. Blokátorem se to stane až při uzamčení vstupů běhu.
   */
  it('u vztahu bez účtárny varuje, u vztahu s účtárnou mlčí', () => {
    const without = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    expect(without.find('[data-test="employment-office-missing"]').exists()).toBe(true)

    const withOffice = mount(EmploymentCard, {
      props: {
        employment: { ...employment(), office_id: 7, office_code: 'PHA', office_name: 'Praha' },
        canWrite: true,
      },
    })
    expect(withOffice.find('[data-test="employment-office-missing"]').exists()).toBe(false)
    expect(withOffice.get('[data-test="employment-office"]').text()).toBe('Praha')
  })

  /**
   * Důvod změny bere server jako volitelný text (`optionalText`, 500 znaků).
   * Formulář ho měl `required`, takže kdo si přišel opravit úvazek, musel napřed
   * vymyslet větu do časové osy.
   */
  it('uloží novou verzi podmínek i bez vyplněného důvodu změny', async () => {
    vi.mocked(payrollApi.addEmploymentTerms).mockResolvedValue(employment())
    const wrapper = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    const edit = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )
    await edit!.trigger('click')
    await flushPromises()

    const reason = wrapper.get('[data-test="terms-change-reason"]')
    expect(reason.attributes('required')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(payrollApi.addEmploymentTerms).mock.calls.at(-1)?.[2].change_reason).toBeNull()
  })

  /**
   * Podrobnosti se u běžného pracovního poměru sbalí a otevřou se samy jen tam,
   * kde je někdo vyplnil — jinak měl formulář přes dvacet polí, ze kterých pět
   * lidí ze šesti nepotřebuje ani jedno.
   */
  it('sbalí podrobnosti podmínek, dokud v nich něco není', async () => {
    const plain = mount(EmploymentCard, {
      props: { employment: employment(), canWrite: true },
    })
    await plain.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )!.trigger('click')
    await flushPromises()
    expect(plain.get('[data-test="terms-advanced"]').attributes('open')).toBeUndefined()

    const filled = employment()
    filled.terms[0].activity_code = '1'
    const withDetail = mount(EmploymentCard, {
      props: { employment: filled, canWrite: true },
    })
    await withDetail.findAll('button').find(button =>
      button.text().includes('payroll.people.new_terms'),
    )!.trigger('click')
    await flushPromises()
    expect(withDetail.get('[data-test="terms-advanced"]').attributes('open')).toBeDefined()
  })
})
