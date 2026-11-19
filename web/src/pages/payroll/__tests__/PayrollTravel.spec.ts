import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  listPage: vi.fn(),
  preview: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  approve: vi.fn(),
  materialize: vi.fn(),
  context: vi.fn(),
  canWrite: vi.fn(),
  toastWarning: vi.fn(),
  toastError: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payrollTravel', () => ({
  payrollTravelApi: {
    listPage: m.listPage,
    preview: m.preview,
    create: m.create,
    update: m.update,
    calculation: vi.fn(),
    approve: m.approve,
    materialize: m.materialize,
  },
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { context: m.context },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, success: vi.fn(), warning: m.toastWarning, info: vi.fn() }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
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

import PayrollTravel from '@/pages/payroll/PayrollTravel.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

function trip(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    employee_id: 3,
    employment_id: 5,
    employee_name: 'Syntetická cestující',
    employment_code: 'SYN-TRV-1',
    relation_type: 'employment',
    country_code: 'CZ',
    timezone_name: 'Europe/Prague',
    departure_at_utc: '2026-06-10 06:00:00',
    arrival_at_utc: '2026-06-10 14:00:00',
    departure_at_local: '2026-06-10 08:00:00',
    arrival_at_local: '2026-06-10 16:00:00',
    origin_place: 'Praha',
    destination_place: 'Brno',
    purpose: 'Jednání',
    transport_mode: 'public_transport',
    meal_rate_band_1_minor: 20000,
    meal_rate_band_2_minor: null,
    meal_rate_band_3_minor: null,
    advance_minor: 0,
    settlement_period_start: '2026-06-01',
    status: 'draft',
    entitlement_total_minor: 20000,
    exempt_total_minor: 18500,
    taxable_total_minor: 1500,
    ruleset_id: 'cz-payroll-2026.travel-allowances.v2',
    calculation: null,
    row_version: 1,
    items: [],
    free_meals: {},
    ...overrides,
  }
}

function tripsPage(trips: unknown[], total = trips.length) {
  return { trips, total, limit: 20, offset: 0 }
}

describe('PayrollTravel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.context.mockResolvedValue([
      { id: 5, employee_id: 3, code: 'SYN-TRV-1', relation_type: 'employment', status: 'active', full_name: 'Syntetická cestující' },
    ])
    m.listPage.mockResolvedValue(tripsPage([trip()]))
  })

  it('renders both the desktop table and the mobile cards', async () => {
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.findAll('[data-test="travel-row"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="travel-card"]')).toHaveLength(1)
  })

  it('hides write and approve actions without the matching permission', async () => {
    m.canWrite.mockImplementation(() => false)
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.find('[data-test="travel-new"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll_travel.actions.approve')
  })

  it('offers posting to payroll only for an approved settlement', async () => {
    m.listPage.mockResolvedValue(tripsPage([trip({ status: 'approved' })]))
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.find('[data-test="travel-materialize"]').exists()).toBe(true)
  })

  it('previews the split before saving and keeps one shared save button', async () => {
    m.preview.mockResolvedValue({
      status: 'supported',
      blockers: [],
      ruleset_ids: ['cz-payroll-2026.travel-allowances.v2'],
      meal_days: [{
        kind: 'meal_allowance',
        date: '2026-06-10',
        minutes: 480,
        band: 1,
        free_meals: 0,
        base_rate_minor: 20000,
        statutory_minimum_minor: 15500,
        tax_exempt_maximum_minor: 18500,
        entitlement_minor: 20000,
        exempt_minor: 18500,
        taxable_minor: 1500,
        ruleset_id: 'cz-payroll-2026.travel-allowances.v2',
      }],
      items: [],
      entitlement_total_minor: 20000,
      exempt_total_minor: 18500,
      taxable_total_minor: 1500,
      advance_minor: 0,
      settlement_difference_minor: 20000,
      steps: [],
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="travel-preview-button"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="travel-preview"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="travel-save"]')).toHaveLength(1)
  })

  /*
   * § 183 ZP — vyúčtování je nárok minus záloha. Formulář musí poslat, kde se
   * rozdíl vypořádá, a náhled ukázat, co z toho plyne; přeplatek zálohy se ze
   * mzdy nesráží a uživatel se o něm musí dozvědět.
   */
  it('sends the chosen advance settlement and shows the refund in the preview', async () => {
    m.preview.mockResolvedValue({
      status: 'supported',
      blockers: [],
      ruleset_ids: [],
      meal_days: [],
      items: [],
      entitlement_total_minor: 20000,
      exempt_total_minor: 18500,
      taxable_total_minor: 1500,
      advance_minor: 30000,
      settlement_difference_minor: -10000,
      steps: [],
      settlement: {
        mode: 'payroll',
        entitlement_minor: 20000,
        exempt_minor: 18500,
        taxable_minor: 1500,
        advance_minor: 30000,
        payroll_exempt_minor: 18500,
        payroll_advance_offset_minor: 20000,
        payroll_net_minor: 0,
        cash_payout_minor: 0,
        employee_refund_minor: 10000,
      },
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    await wrapper.find('[data-test="travel-advance"]').setValue('300')
    const settlement = wrapper.findComponent('[data-test="travel-advance-settlement"]') as VueWrapper<any>
    expect(settlement.props('options')).toEqual([
      expect.objectContaining({ value: 'payroll' }),
      expect.objectContaining({ value: 'cash' }),
    ])
    settlement.vm.$emit('update:modelValue', 'cash')
    await flushPromises()
    expect(wrapper.text()).toContain('payroll_travel.settlement.help_cash')

    await wrapper.find('[data-test="travel-preview-button"]').trigger('click')
    await flushPromises()

    expect(m.preview.mock.calls[0][0]).toEqual(expect.objectContaining({
      advance: '300',
      advance_settlement: 'cash',
    }))
    expect(wrapper.find('[data-test="travel-settlement-preview"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="travel-settlement-refund"]').exists()).toBe(true)
  })

  /*
   * § 183 odst. 1 ZP: proklik z hlídače termínů otevře editor cesty, kde se
   * zapíše den předložení dokladů; seznam ukazuje, do kdy doklady čekají.
   */
  it('opens the trip from the deadline link and sends the documents submission date', async () => {
    m.routeQuery = { period: '2026-06', trip: '7' }
    m.listPage.mockResolvedValue(tripsPage([trip({ documents_due_on: '2026-06-24' })]))
    m.preview.mockResolvedValue({
      status: 'supported', blockers: [], ruleset_ids: [], meal_days: [], items: [],
      entitlement_total_minor: 0, exempt_total_minor: 0, taxable_total_minor: 0,
      advance_minor: 0, settlement_difference_minor: 0, steps: [],
    })
    try {
      const wrapper = mount(PayrollTravel)
      await flushPromises()

      expect(m.listPage.mock.calls[0][0]).toBe('2026-06')
      expect(wrapper.get('[data-test="travel-documents-due"]').text())
        .toContain('payroll_travel.deadline.documents_due')
      expect(wrapper.find('[data-test="travel-editor"]').exists()).toBe(true)
      expect(m.routerReplace).toHaveBeenCalledWith({ query: { period: '2026-06' } })

      const field = wrapper.get('[data-test="travel-documents-submitted-on"]')
      const input = field.element.tagName === 'INPUT' ? field : field.get('input')
      await input.setValue('2026-06-12')
      await wrapper.find('[data-test="travel-preview-button"]').trigger('click')
      await flushPromises()

      expect(m.preview.mock.calls[0][0]).toEqual(expect.objectContaining({
        documents_submitted_on: '2026-06-12',
      }))
    } finally {
      m.routeQuery = {}
    }
  })

  it('warns after posting to payroll when the employee has to return part of the advance', async () => {
    m.listPage.mockResolvedValue(tripsPage([trip({ status: 'approved', advance_minor: 30000 })]))
    m.materialize.mockResolvedValue({
      status: 'materialized',
      trip_id: 7,
      period: '2026-06',
      created_count: 3,
      replayed_count: 0,
      created: [],
      replayed: [],
      settlement: {
        mode: 'payroll',
        entitlement_minor: 20000,
        exempt_minor: 18500,
        taxable_minor: 1500,
        advance_minor: 30000,
        payroll_exempt_minor: 18500,
        payroll_advance_offset_minor: 20000,
        payroll_net_minor: 0,
        cash_payout_minor: 0,
        employee_refund_minor: 10000,
      },
      posting: null,
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-materialize"]').trigger('click')
    await flushPromises()

    expect(m.toastWarning).toHaveBeenCalledWith('payroll_travel.settlement.refund_notice')
  })

  it('shows a posting error from cash settlement instead of hiding it as skipped', async () => {
    m.listPage.mockResolvedValue(tripsPage([trip({ status: 'approved', advance_minor: 10000 })]))
    m.materialize.mockResolvedValue({
      status: 'materialized',
      trip_id: 7,
      period: '2026-06',
      created_count: 1,
      replayed_count: 0,
      created: [],
      replayed: [],
      settlement: {
        mode: 'cash',
        entitlement_minor: 20000,
        exempt_minor: 18500,
        taxable_minor: 1500,
        advance_minor: 10000,
        payroll_exempt_minor: 0,
        payroll_advance_offset_minor: 0,
        payroll_net_minor: 1500,
        cash_payout_minor: 8500,
        employee_refund_minor: 0,
      },
      posting: { status: 'error', journal_entry_id: null, reason: 'dimension_required', message: 'Chybí činnost.' },
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-materialize"]').trigger('click')
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith('payroll_travel.settlement.posting_failed')
    expect(m.toastWarning).not.toHaveBeenCalledWith('payroll_travel.settlement.posting_skipped')
  })

  it('filters employment relations by the employee selected in the editor', async () => {
    m.context.mockResolvedValue([
      { id: 5, employee_id: 3, code: 'SYN-TRV-1', relation_type: 'employment', status: 'active', full_name: 'Syntetická cestující' },
      { id: 6, employee_id: 3, code: 'SYN-TRV-2', relation_type: 'dpp', status: 'active', full_name: 'Syntetická cestující' },
      { id: 7, employee_id: 4, code: 'SYN-OTHER', relation_type: 'dpc', status: 'active', full_name: 'Jiná cestující' },
    ])
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    wrapper.findComponent(PayrollPersonSearchSelect)
      .vm.$emit('update:modelValue', 3)
    await flushPromises()

    const employment = wrapper.findComponent('[data-test="travel-employment"]') as VueWrapper<any>
    expect(employment.props('options')).toHaveLength(2)
    expect(employment.props('options')).toEqual(expect.arrayContaining([
      expect.objectContaining({ value: 5 }),
      expect.objectContaining({ value: 6 }),
    ]))

    wrapper.findComponent(PayrollPersonSearchSelect)
      .vm.$emit('update:modelValue', 4)
    await flushPromises()

    expect(employment.props('options')).toEqual([
      expect.objectContaining({ value: 7 }),
    ])
    expect(employment.props('modelValue')).toBe(7)
  })

  it('shows the exact server message inline when saving fails', async () => {
    m.create.mockRejectedValue({
      response: { data: { error: { message: 'Sazba stravného pásma 1 je nižší než zákonné minimum.' } } },
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="travel-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="travel-error"]').text())
      .toContain('Sazba stravného pásma 1 je nižší než zákonné minimum.')
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", dostala by
   * prvních padesát cest a o zbytku by mlčela — uživatel se šedesáti cestami by
   * se o deseti z nich nedozvěděl.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(m.listPage).toHaveBeenCalledTimes(1)
    expect(m.listPage.mock.calls[0][1]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })

  it('pages through the list and re-asks the server with the new offset', async () => {
    m.listPage.mockResolvedValue({
      trips: Array.from({ length: 20 }, (_, index) => trip({ id: index + 1 })),
      total: 45,
      limit: 20,
      offset: 0,
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(45)

    pager.vm.$emit('update:page', 2)
    await flushPromises()

    expect(m.listPage).toHaveBeenCalledTimes(2)
    expect(m.listPage.mock.calls[1][1]).toEqual({ limit: 20, offset: 20 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds everything', async () => {
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.find('[data-test="travel-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the period changes', async () => {
    m.listPage.mockResolvedValue({
      trips: Array.from({ length: 20 }, (_, index) => trip({ id: index + 1 })),
      total: 45,
      limit: 20,
      offset: 0,
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(m.listPage.mock.calls[1][1]).toEqual({ limit: 20, offset: 20 })

    await wrapper.find('[data-test="travel-period"]').setValue('2026-01')
    await flushPromises()

    expect(m.listPage.mock.calls[2][0]).toBe('2026-01')
    expect(m.listPage.mock.calls[2][1]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })
})
