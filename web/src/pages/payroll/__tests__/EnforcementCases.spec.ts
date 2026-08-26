import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type {
  EnforcementCaseDetail,
  EnforcementCaseSummary,
  EnforcementDependant,
  EnforcementMonthEvidence,
} from '@/api/payrollEnforcement'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  casesPage: vi.fn(),
  detail: vi.fn(),
  monthEvidence: vi.fn(),
  dependants: vi.fn(),
  peoplePage: vi.fn(),
  person: vi.fn(),
  institutionAccounts: vi.fn(),
  deleteCase: vi.fn(),
  updateClaim: vi.fn(),
  deleteClaim: vi.fn(),
  canRead: vi.fn(),
  canWrite: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  pensionEvidenceValues: ['unknown', 'none', 'verified'],
  payrollEnforcementApi: {
    casesPage: m.casesPage,
    detail: m.detail,
    create: vi.fn(),
    addClaim: vi.fn(),
    updateClaim: m.updateClaim,
    deleteClaim: m.deleteClaim,
    updateEvidence: vi.fn(),
    transition: vi.fn(),
    deleteCase: m.deleteCase,
    monthEvidence: m.monthEvidence,
    saveMonthEvidence: vi.fn(),
    dependants: m.dependants,
    addDependant: vi.fn(),
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    institutionAccounts: m.institutionAccounts,
  },
}))

vi.mock('@/api/documents', () => ({
  documentsApi: { search: vi.fn() },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.error, success: m.success, warning: vi.fn() }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import EnforcementCases from '@/pages/payroll/EnforcementCases.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

function summary(overrides: Partial<EnforcementCaseSummary> = {}): EnforcementCaseSummary {
  return {
    id: 11,
    employee_id: 3,
    full_name: 'Syntetický Povinný',
    case_kind: 'enforcement',
    status: 'received',
    effective_from: '2026-05-01',
    effective_to: null,
    evidence_complete: false,
    recipient_verified: false,
    row_version: 1,
    claim_count: 1,
    outstanding_minor_units: 250_000,
    created_at: '2026-05-01 08:00:00',
    updated_at: '2026-05-01 08:00:00',
    ...overrides,
  }
}

function page(cases: EnforcementCaseSummary[], total = cases.length, offset = 0) {
  return { cases, total, limit: 20, offset }
}

function detailOf(item: EnforcementCaseSummary): EnforcementCaseDetail {
  return {
    ...item,
    recipient_institution_id: null,
    claims: [],
    events: [],
    ledger: [],
    settlement: {
      claims: [],
      withheld_minor: 0,
      held_minor: 0,
      liability_minor: 0,
      settled_minor: 0,
      outstanding_minor: 0,
      remaining_minor: 0,
    },
  }
}

function verifiedClaim(): EnforcementCaseDetail['claims'][number] {
  return {
    id: 51,
    case_id: 11,
    legal_basis: 'statutory',
    category: 'non_priority',
    outstanding_minor_units: 250_000,
    maintenance_weight_minor_units: null,
    priority_date: '2026-05-01',
    order_issued_on: '2026-05-01',
    legal_title_verified: true,
    order_or_notice_delivered: true,
    priority_classification_verified: true,
    agreement_verified: false,
    due_monetary_claim_verified: true,
    is_active: true,
    row_version: 1,
  }
}

function monthEvidenceOf(
  overrides: Partial<EnforcementMonthEvidence> = {},
): EnforcementMonthEvidence {
  return {
    id: 5,
    employee_id: 3,
    period_start: '2026-06-01',
    claim_register_evidence_complete: false,
    dependants_evidence_complete: false,
    spouse_evidence_complete: false,
    pension_evidence: 'unknown',
    has_multiple_payers: false,
    protected_amount_override_minor_units: null,
    protected_amount_override_verified: false,
    insolvency_mode: 'none',
    insolvency_decision_verified: false,
    insolvency_recipient_verified: false,
    insolvency_payment_instruction_id: null,
    insolvency_employment_id: null,
    insolvency_institution_account_id: null,
    insolvency_decision_document_id: null,
    insolvency_payment_instruction_hash: null,
    court_determined_amount_minor_units: null,
    row_version: 1,
    ...overrides,
  }
}

function dependantOf(overrides: Partial<EnforcementDependant> = {}): EnforcementDependant {
  return {
    id: 1,
    employee_id: 3,
    dependant_kind: 'dependant',
    // Platnost od roku 2020 do odvolání, ať test nezávisí na tom, kdy běží.
    valid_from: '2020-01-01',
    valid_to: null,
    eligibility_verified: true,
    excluded_for_maintenance: false,
    row_version: 1,
    ...overrides,
  }
}

function mountPage() {
  return mount(EnforcementCases, { global: { stubs: { RouterLink: true } } })
}

/** Rozbalí jediný případ v seznamu a počká na doplňkové dotazy panelu. */
async function expandFirstCase(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get('[data-test="enforcement-detail-11"]').trigger('click')
  await flushPromises()
}

describe('EnforcementCases', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.canRead.mockReturnValue(true)
    m.canWrite.mockReturnValue(true)
    m.casesPage.mockResolvedValue(page([summary()]))
    m.detail.mockImplementation(async () => detailOf(summary()))
    m.peoplePage.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
    m.person.mockResolvedValue({ id: 3, full_name: 'Syntetický Povinný' })
    m.institutionAccounts.mockResolvedValue([])
    m.deleteCase.mockResolvedValue({ deleted: true, id: 11 })
    m.updateClaim.mockResolvedValue(verifiedClaim())
    m.deleteClaim.mockResolvedValue({
      deleted: true,
      id: 51,
      case_id: 11,
      case_row_version: 2,
    })
    m.monthEvidence.mockResolvedValue(monthEvidenceOf())
    m.dependants.mockResolvedValue([])
  })

  it('offers deletion for an unused received case even after draft evidence changed', async () => {
    const unused = summary({
      claim_count: 0,
      outstanding_minor_units: 0,
      recipient_verified: true,
      row_version: 3,
    })
    m.casesPage.mockResolvedValue(page([unused]))
    m.detail.mockResolvedValue(detailOf(unused))
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    const action = wrapper.findComponent({ name: 'ActionBar' })
      .props('actions').find((item: any) => item.key === 'delete')
    expect(action).toMatchObject({ variant: 'danger', tier: 'overflow', show: true })
    wrapper.unmount()
  })

  it('confirms deletion and sends the current row version', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const unused = summary({ claim_count: 0, outstanding_minor_units: 0, row_version: 3 })
    m.casesPage.mockResolvedValue(page([unused]))
    m.detail.mockResolvedValue(detailOf(unused))
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    const action = wrapper.findComponent({ name: 'ActionBar' })
      .props('actions').find((item: any) => item.key === 'delete')
    await action.run()
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith('payroll.enforcement.delete_confirm')
    expect(m.deleteCase).toHaveBeenCalledWith(11, 3)
    expect(m.success).toHaveBeenCalledWith('payroll.enforcement.case_deleted')
    expect(wrapper.find('[data-test="enforcement-detail-panel"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('vede nový prázdný případ jediným srozumitelným dalším krokem', async () => {
    const unused = summary({ claim_count: 0, outstanding_minor_units: 0 })
    m.casesPage.mockResolvedValue(page([unused]))
    m.detail.mockResolvedValue(detailOf(unused))
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    expect(wrapper.get('[data-test="enforcement-next-step"]').text())
      .toContain('payroll.enforcement.next_steps.add_claim.title')
    await wrapper.get('[data-test="enforcement-next-step-action"]').trigger('click')

    expect(wrapper.find('[data-test="enforcement-claim-form"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('nabídne navigační akci i pro neúplnou pohledávku a podklady', async () => {
    const incompleteClaim = summary({ claim_count: 1 })
    m.casesPage.mockResolvedValue(page([incompleteClaim]))
    m.detail.mockResolvedValue({
      ...detailOf(incompleteClaim),
      claims: [{ ...verifiedClaim(), priority_date: null }],
    })
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    expect(wrapper.get('[data-test="enforcement-next-step"]').text())
      .toContain('payroll.enforcement.next_steps.verify_claims.title')
    expect(wrapper.get('[data-test="enforcement-next-step-action"]').text())
      .toContain('payroll.enforcement.next_steps.verify_claims.action')
    wrapper.unmount()

    const incompleteEvidence = summary({ claim_count: 1, evidence_complete: false })
    m.casesPage.mockResolvedValue(page([incompleteEvidence]))
    m.detail.mockResolvedValue({
      ...detailOf(incompleteEvidence),
      claims: [verifiedClaim()],
    })
    const evidenceWrapper = mountPage()
    await flushPromises()
    await expandFirstCase(evidenceWrapper)

    expect(evidenceWrapper.get('[data-test="enforcement-next-step"]').text())
      .toContain('payroll.enforcement.next_steps.verify_evidence.title')
    expect(evidenceWrapper.get('[data-test="enforcement-next-step-action"]').text())
      .toContain('payroll.enforcement.next_steps.verify_evidence.action')
    evidenceWrapper.unmount()
  })

  it('umožní opravit a smazat rozpracovanou pohledávku před zahájením srážení', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const received = summary({ claim_count: 1 })
    const claim = verifiedClaim()
    const initial = { ...detailOf(received), claims: [claim] }
    const corrected = {
      ...initial,
      claims: [{ ...claim, outstanding_minor_units: 123_400 }],
    }
    const withoutClaim = {
      ...detailOf({ ...received, claim_count: 0, outstanding_minor_units: 0 }),
      claims: [],
    }
    m.casesPage.mockResolvedValue(page([received]))
    m.detail
      .mockResolvedValueOnce(initial)
      .mockResolvedValueOnce(corrected)
      .mockResolvedValueOnce(withoutClaim)

    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    await wrapper.get('[data-test="edit-claim-51"]').trigger('click')
    expect((wrapper.get('[data-test="claim-amount"]').element as HTMLInputElement).value)
      .toBe('2500')
    await wrapper.get('[data-test="claim-amount"]').setValue('1234')
    await wrapper.get('[data-test="enforcement-claim-form"]').trigger('submit')
    await flushPromises()

    expect(m.updateClaim).toHaveBeenCalledWith(11, 51, expect.objectContaining({
      outstanding_minor_units: 123_400,
      row_version: 1,
    }))
    expect(m.success).toHaveBeenCalledWith('payroll.enforcement.claim_updated')

    await wrapper.get('[data-test="delete-claim-51"]').trigger('click')
    await flushPromises()
    expect(m.deleteClaim).toHaveBeenCalledWith(11, 51, 1)
    expect(m.success).toHaveBeenCalledWith('payroll.enforcement.claim_deleted')
    expect(wrapper.find('[data-test="delete-claim-51"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('schová méně časté stavové změny, ale ponechá je dostupné', async () => {
    const active = summary({
      status: 'remit',
      evidence_complete: true,
      recipient_verified: true,
    })
    m.casesPage.mockResolvedValue(page([active]))
    m.detail.mockResolvedValue({
      ...detailOf(active),
      claims: [verifiedClaim()],
      recipient_institution_id: 9,
    })
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    expect(wrapper.get('[data-test="enforcement-next-step"]').text())
      .toContain('payroll.enforcement.next_steps.monthly_check.title')
    expect(wrapper.get('[data-test="enforcement-next-step-action"]').text())
      .toContain('payroll.enforcement.next_steps.monthly_check.action')
    expect(wrapper.find('[data-test="enforcement-state-actions"]').exists()).toBe(false)

    await wrapper.get('[data-test="enforcement-state-actions-toggle"]').trigger('click')
    expect(wrapper.get('[data-test="enforcement-state-actions"]').text())
      .toContain('payroll.enforcement.commands.defer_no_withholding')
    expect(wrapper.get('[data-test="enforcement-state-actions"]').text())
      .toContain('payroll.enforcement.commands.stop')
    wrapper.unmount()
  })

  it('localizes the reason why a used case can no longer be deleted', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const unused = summary({ claim_count: 0, outstanding_minor_units: 0, row_version: 3 })
    m.casesPage.mockResolvedValue(page([unused]))
    m.detail.mockResolvedValue(detailOf(unused))
    m.deleteCase.mockRejectedValue({
      response: {
        data: {
          error: {
            code: 'enforcement_case_delete_blocked',
            message: 'Případ nelze smazat, protože už vstoupil do výpočtu.',
            blocker: 'allocation_exists',
            suggestion: 'stop',
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()
    await expandFirstCase(wrapper)

    const action = wrapper.findComponent({ name: 'ActionBar' })
      .props('actions').find((item: any) => item.key === 'delete')
    await action.run()
    await flushPromises()

    expect(m.error).toHaveBeenCalledWith(
      'payroll.enforcement.delete_blocked.allocation_exists',
    )
    wrapper.unmount()
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", dostala by
   * prvních padesát případů a o zbytku by mlčela — firma se šedesáti exekucemi
   * by se o deseti z nich nedozvěděla.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(m.casesPage).toHaveBeenCalledTimes(1)
    expect(m.casesPage.mock.calls[0][0]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })

  it('použije hledací výběr pro filtr i nový případ místo úplného seznamu osob', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.findAllComponents(PayrollPersonSearchSelect)).toHaveLength(1)
    const filterInput = wrapper.get('[data-test="enforcement-employee-filter"] input')
    expect((filterInput.element as HTMLInputElement).value).toBe('')
    expect(filterInput.attributes('placeholder')).toBe('payroll.enforcement.all_employees')
    await wrapper.get('button[aria-expanded="false"]').trigger('click')
    expect(wrapper.findAllComponents(PayrollPersonSearchSelect)).toHaveLength(2)
    expect(wrapper.find('select[data-test="enforcement-employee-filter"]').exists()).toBe(false)
    const requiredPicker = wrapper.findAllComponents(PayrollPersonSearchSelect)[0]
    expect(requiredPicker.get('input').attributes('required')).toBeDefined()
    expect(requiredPicker.get('input').attributes('aria-required')).toBe('true')
    wrapper.unmount()
  })

  it('zachová deep-link osoby ve filtru i mimo první stránku našeptávače', async () => {
    m.routeQuery = { person: '87' }
    m.person.mockResolvedValue({ id: 87, full_name: 'Povinný z odkazu' })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.casesPage).toHaveBeenCalledWith({ employee_id: 87, limit: 20, offset: 0 })
    expect(m.person).toHaveBeenCalledWith(87)
    expect((wrapper.get('[data-test="enforcement-employee-filter"] input').element as HTMLInputElement).value)
      .toBe('Povinný z odkazu')
    wrapper.unmount()
  })

  it('pages through the list and re-asks the server with the new offset', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(45)

    pager.vm.$emit('update:page', 2)
    await flushPromises()

    expect(m.casesPage).toHaveBeenCalledTimes(2)
    expect(m.casesPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds everything', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="enforcement-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the status filter narrows the list', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(m.casesPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })

    await wrapper.get('[data-test="enforcement-status-filter"]').setValue('paid')
    await flushPromises()

    expect(m.casesPage.mock.calls[2][0]).toEqual({ status: 'paid', limit: 20, offset: 0 })
    wrapper.unmount()
  })

  /*
   * Rozbalený detail patří k řádku seznamu. Na druhé stránce ten řádek není,
   * takže by panel visel u případu, který na obrazovce nikde není.
   */
  it('collapses the expanded case when the user leaves its page', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="enforcement-detail-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="enforcement-detail-panel"]').exists()).toBe(true)

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()

    expect(wrapper.find('[data-test="enforcement-detail-panel"]').exists()).toBe(false)
    wrapper.unmount()
  })

  /*
   * Prázdný seznam a nenačtený seznam vedou uživatele k opačnému jednání
   * (založ případ vs. zkus to znovu), takže je nesmí kreslit stejně.
   */
  it('offers a retry instead of an empty state when the page fails to load', async () => {
    m.casesPage.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('payroll.enforcement.empty_title')
    expect(m.error).toHaveBeenCalledWith('payroll.enforcement.load_failed')
    wrapper.unmount()
  })

  // Hledání lidí se načítá až při otevření našeptávače; jeho výpadek nesmí
  // potopit stránkovaný seznam případů.
  it('keeps the paged list when only the people search fails', async () => {
    m.peoplePage.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="enforcement-employee-filter"] input').trigger('focus')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Syntetický Povinný')
    expect(wrapper.find('[data-test="enforcement-employee-filter"] [role="alert"]').exists()).toBe(true)
    wrapper.unmount()
  })

  /*
   * Rozsah měsíční evidence zrcadlí GarnishmentCalculator::evidenceScope().
   * Panel má tři checkboxy, ale ne jedno pravidlo: rejstřík pohledávek se váže
   * na to, jestli je z čeho srážet, kdežto nároky na to, jestli je někdo
   * uplatňuje — a při souběhu plátců je určuje soud. Obrazovka o rozsahu
   * nerozhoduje, jen nesmí pobízet k potvrzení, které nic nedokládá.
   */
  describe('rozsah měsíční evidence', () => {
    it('oddělí běžnou měsíční kontrolu od výjimek a správy vyživovaných osob', async () => {
      m.monthEvidence.mockResolvedValue(monthEvidenceOf({
        has_multiple_payers: true,
        insolvency_mode: 'court_determined_amount',
        court_determined_amount_minor_units: 12_345,
      }))
      m.dependants.mockResolvedValue([dependantOf()])

      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      expect(wrapper.find('[data-test="month-evidence-claim_register"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="month-exceptions-panel"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="dependants-panel"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="month-exceptions-summary"]').text())
        .toContain('payroll.enforcement.monthly_exceptions.summary_active')
      expect(wrapper.get('[data-test="month-exceptions-values"]').text())
        .toContain('payroll.enforcement.month_evidence.insolvency_court')
      expect(wrapper.get('[data-test="dependants-summary"]').text())
        .toContain('payroll.enforcement.dependants_summary')

      await wrapper.get('[data-test="month-exceptions-toggle"]').trigger('click')
      await wrapper.get('input[type="month"]').setValue('2025-12')
      await flushPromises()
      const exceptions = wrapper.get('[data-test="month-exceptions-panel"]')
      expect(exceptions.find('[data-test="month-evidence-multiple-payers"]').exists()).toBe(true)
      expect(exceptions.find('[data-test="insolvency-mode-impact"]').exists()).toBe(false)
      expect(exceptions.get('[data-test="open-insolvency-workspace"]').attributes('to'))
        .toBe('/payroll/insolvency?person=3&period=2025-12')

      await wrapper.get('[data-test="dependants-toggle"]').trigger('click')
      expect(wrapper.get('[data-test="dependants-panel"]').text())
        .toContain('payroll.enforcement.dependant_kind.dependant')
      wrapper.unmount()
    })

    it('greys out all three confirmations for a person without a live case', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      for (const key of ['claim_register', 'dependants', 'spouse']) {
        expect(wrapper.get(`[data-test="month-evidence-${key}"]`).attributes('disabled'))
          .toBeDefined()
      }
      expect(wrapper.get('[data-test="month-evidence-claim_register-note"]').text())
        .toBe('payroll.enforcement.month_evidence.scope.claim_register_idle')
      expect(wrapper.get('[data-test="month-evidence-dependants-note"]').text())
        .toBe('payroll.enforcement.month_evidence.scope.allowance_not_claimed')
      wrapper.unmount()
    })

    it('keeps all three confirmations live for a person with a withholding case', async () => {
      const live = summary({
        status: 'withhold_and_hold',
        effective_from: '2020-01-01',
        effective_to: null,
      })
      m.casesPage.mockResolvedValue(page([live]))
      m.detail.mockImplementation(async () => detailOf(live))
      m.dependants.mockResolvedValue([
        dependantOf(),
        dependantOf({ id: 2, dependant_kind: 'spouse_partner' }),
      ])

      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      for (const key of ['claim_register', 'dependants', 'spouse']) {
        expect(wrapper.get(`[data-test="month-evidence-${key}"]`).attributes('disabled'))
          .toBeUndefined()
        expect(wrapper.find(`[data-test="month-evidence-${key}-note"]`).exists()).toBe(false)
      }
      wrapper.unmount()
    })

    /*
     * Uplatněný a nedoložený nárok v měsíci bez srážky je třetí stav, ne „není
     * co dokládat": nezabavitelná částka drží i strop dobrovolné dohody
     * o srážkách (§ 148 odst. 2 zákoníku práce), takže tenhle checkbox musí
     * zůstat k vyplnění — a musí být vidět proč.
     */
    it('keeps an undocumented allowance actionable in a month without withholding', async () => {
      m.dependants.mockResolvedValue([dependantOf()])

      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      expect(wrapper.get('[data-test="month-evidence-dependants"]').attributes('disabled'))
        .toBeUndefined()
      expect(wrapper.get('[data-test="month-evidence-dependants-note"]').text())
        .toBe('payroll.enforcement.month_evidence.scope.nothing_withheld')
      // Rejstřík se řídí jiným pravidlem a bez pohledávky dokládat nemá co.
      expect(wrapper.get('[data-test="month-evidence-claim_register"]').attributes('disabled'))
        .toBeDefined()
      wrapper.unmount()
    })

    // Při souběhu plátců určuje nezabavitelnou částku soud — uplatněný nárok
    // proto vypadne z rozsahu, ačkoli je uplatněný.
    it('drops the allowances out of scope when multiple payers pay the income', async () => {
      m.monthEvidence.mockResolvedValue(monthEvidenceOf({ has_multiple_payers: true }))
      m.dependants.mockResolvedValue([dependantOf()])

      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      expect(wrapper.get('[data-test="month-evidence-dependants"]').attributes('disabled'))
        .toBeDefined()
      expect(wrapper.get('[data-test="month-evidence-dependants-note"]').text())
        .toBe('payroll.enforcement.month_evidence.scope.allowance_multiple_payers')
      wrapper.unmount()
    })

    /*
     * Rozsah se váže na VŠECHNY případy osoby, ne na filtrovanou stránku
     * seznamu — u člověka se dvěma exekucemi by filtr na jeden stav schoval
     * ten, ze kterého se sráží.
     */
    it('asks for the whole person instead of trusting the filtered page', async () => {
      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      expect(m.casesPage).toHaveBeenCalledWith({ employee_id: 3, limit: 100, offset: 0 })
      wrapper.unmount()
    })

    // Nenačtený seznam případů nesmí nic zešednout: obrazovka by tvrdila, že
    // není co dokládat, a přitom by o případech osoby nevěděla nic.
    it('keeps the confirmations live when the person lookup fails', async () => {
      m.casesPage.mockImplementation(async (params: { employee_id?: number }) =>
        params?.employee_id ? Promise.reject(new Error('network')) : page([summary()]))
      m.dependants.mockResolvedValue([dependantOf()])

      const wrapper = mountPage()
      await flushPromises()
      await expandFirstCase(wrapper)

      expect(wrapper.get('[data-test="month-evidence-claim_register"]').attributes('disabled'))
        .toBeUndefined()
      expect(wrapper.get('[data-test="month-evidence-dependants"]').attributes('disabled'))
        .toBeUndefined()
      wrapper.unmount()
    })
  })
})
