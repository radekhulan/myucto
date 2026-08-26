import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { DeductionAgreementDetail, DeductionAgreementSummary } from '@/api/payrollDeductions'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  agreementsPage: vi.fn(),
  agreement: vi.fn(),
  peoplePage: vi.fn(),
  person: vi.fn(),
  canWrite: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payrollDeductions', () => ({
  deductionAgreementKinds: ['advance', 'meal', 'contribution', 'damage', 'other'],
  deductionPriorityFloor: 10,
  deductionPriorityCeiling: 9999,
  payrollDeductionsApi: {
    agreementsPage: m.agreementsPage,
    agreement: m.agreement,
    create: vi.fn(),
    update: vi.fn(),
    transition: vi.fn(),
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { peoplePage: m.peoplePage, person: m.person },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
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

import DeductionAgreements from '@/pages/payroll/DeductionAgreements.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

function summary(overrides: Partial<DeductionAgreementSummary> = {}): DeductionAgreementSummary {
  return {
    id: 21,
    employee_id: 3,
    full_name: 'Syntetická Srážková',
    agreement_reference: 'SRZ-1',
    title: 'Stravenky',
    deduction_kind: 'meal',
    status: 'active',
    priority_no: 100,
    requested_minor: 50_000,
    basis_points: null,
    basis_amount_minor: null,
    total_limit_minor: null,
    withheld_total_minor: 0,
    remaining_limit_minor: null,
    valid_from: '2026-01-01',
    valid_to: null,
    recipient_reference: null,
    note: null,
    row_version: 1,
    version_no: 1,
    enters_payroll_run: true,
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-01-01 08:00:00',
    ...overrides,
  }
}

function page(agreements: DeductionAgreementSummary[], total = agreements.length, offset = 0) {
  return { agreements, total, limit: 20, offset }
}

function detailOf(item: DeductionAgreementSummary): DeductionAgreementDetail {
  return { ...item, versions: [], ledger: [] }
}

describe('DeductionAgreements', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.canWrite.mockReturnValue(true)
    m.agreementsPage.mockResolvedValue(page([summary()]))
    m.agreement.mockImplementation(async () => detailOf(summary()))
    m.peoplePage.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
    m.person.mockResolvedValue({ id: 3, full_name: 'Syntetická Srážková' })
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", dostala by
   * prvních padesát dohod a o zbytku by mlčela — zaměstnanci z šesté desítky by
   * srážka podle výpisu vůbec nevznikla.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(m.agreementsPage).toHaveBeenCalledTimes(1)
    expect(m.agreementsPage.mock.calls[0][0]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })

  it('použije hledací výběr pro filtr i formulář místo úplného seznamu osob', async () => {
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(wrapper.findAllComponents(PayrollPersonSearchSelect)).toHaveLength(1)
    const filterInput = wrapper.get('[data-test="deduction-employee-filter"] input')
    expect((filterInput.element as HTMLInputElement).value).toBe('')
    expect(filterInput.attributes('placeholder')).toBe('payroll.deductions.all_employees')
    await wrapper.get('button').trigger('click')
    expect(wrapper.findAllComponents(PayrollPersonSearchSelect)).toHaveLength(2)
    expect(wrapper.find('select[data-test="deduction-employee-filter"]').exists()).toBe(false)
    const requiredPicker = wrapper.findAllComponents(PayrollPersonSearchSelect)[1]
    expect(requiredPicker.get('input').attributes('required')).toBeDefined()
    expect(requiredPicker.get('input').attributes('aria-required')).toBe('true')
    wrapper.unmount()
  })

  it('zachová deep-link osoby ve filtru i mimo první stránku našeptávače', async () => {
    m.routeQuery = { person: '93' }
    m.person.mockResolvedValue({ id: 93, full_name: 'Osoba z odkazu' })
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(m.agreementsPage).toHaveBeenCalledWith({ employee_id: 93, limit: 20, offset: 0 })
    expect(m.person).toHaveBeenCalledWith(93)
    expect((wrapper.get('[data-test="deduction-employee-filter"] input').element as HTMLInputElement).value)
      .toBe('Osoba z odkazu')
    wrapper.unmount()
  })

  it('u 500 zaměstnanců načte do filtru jen omezenou stránku výsledků', async () => {
    m.peoplePage.mockResolvedValue({
      items: Array.from({ length: 25 }, (_, index) => ({
        id: index + 1,
        full_name: `Syntetická osoba ${index + 1}`,
      })),
      total: 500,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    const input = wrapper.get('[data-test="deduction-employee-filter"] input[role="combobox"]')
    await input.trigger('focus')
    await flushPromises()

    expect(m.peoplePage).toHaveBeenCalledWith({ limit: 25, offset: 0, q: '' })
    expect(wrapper.findAll('[role="option"]')).toHaveLength(25)
    expect(wrapper.find('[data-test="searchable-select-truncated"]').exists()).toBe(true)
    expect(wrapper.find('select[data-test="deduction-employee-filter"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('pages through the list and re-asks the server with the new offset', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(45)

    pager.vm.$emit('update:page', 2)
    await flushPromises()

    expect(m.agreementsPage).toHaveBeenCalledTimes(2)
    expect(m.agreementsPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds everything', async () => {
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(wrapper.find('[data-test="deduction-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the status filter narrows the list', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(m.agreementsPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })

    await wrapper.get('[data-test="deduction-status-filter"]').setValue('paused')
    await flushPromises()

    expect(m.agreementsPage.mock.calls[2][0]).toEqual({ status: 'paused', limit: 20, offset: 0 })
    wrapper.unmount()
  })

  /*
   * Rozbalený detail patří k řádku seznamu. Na druhé stránce ten řádek není,
   * takže by panel visel u dohody, kterou na obrazovce nikdo nevidí.
   */
  it('collapses the expanded agreement when the user leaves its page', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))
    m.agreement.mockImplementation(async (id: number) => detailOf(summary({ id })))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    await wrapper.get('[data-test="deduction-detail-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="deduction-detail-panel"]').exists()).toBe(true)

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()

    expect(wrapper.find('[data-test="deduction-detail-panel"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // Chyba načtení musí zůstat na obrazovce; toast by zmizel a uživatel by
  // prázdný výpis četl jako „žádné srážky nejsou".
  it('keeps the server message visible when the page fails to load', async () => {
    m.agreementsPage.mockRejectedValue({
      response: { data: { error: { message: 'Seznam se nepodařilo načíst.' } } },
    })

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Seznam se nepodařilo načíst.')
    wrapper.unmount()
  })
})
