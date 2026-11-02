import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  getGeneralLedger: vi.fn(),
  replace: vi.fn(),
}))

const defaultRouteQuery = {
  period_id: '3',
  from: '2026-01-01',
  to: '2026-12-31',
  analytics: '1',
  account_id: '3138611',
}
const routeQuery: Record<string, string> = { ...defaultRouteQuery }

// Dimenze (Firma → Dimenze) jsou u firmy vypnuté — jejich komponenty se nevykreslí.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false }, canEdit: { value: false }, loading: { value: false },
    types: { value: [] }, values: { value: [] }, documentTypes: { value: [] },
    valueById: { value: new Map() }, typeById: { value: new Map() },
    load: () => Promise.resolve(), reload: () => Promise.resolve(),
    options: () => [], treeOf: () => [], pathOf: () => [], labelOf: () => '', valueLabel: () => '',
  }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    getGeneralLedger: m.getGeneralLedger,
    exportReport: vi.fn(),
    getAccountStatement: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: { download: 'M0 0' }, btnOutline: () => '' }))
vi.mock('@/composables/usePaneDom', () => ({ usePaneDom: () => document }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: unknown[]) => ({
    columns,
    isVisible: () => true,
    densityClass: ref(''),
    setFlag: vi.fn(),
    flag: () => false,
    sort: ref(null),
    toggleSort: vi.fn(),
  }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  savedFilterTone: () => 'neutral',
  useSavedFilters: () => ({
    filters: ref([]),
    activeId: ref(null),
    clearActive: vi.fn(),
    apply: vi.fn(),
    applyDefaultIfAny: vi.fn().mockResolvedValue(false),
  }),
}))

import GeneralLedger from '@/pages/accounting/GeneralLedger.vue'

function report(periodId: number) {
  const year = periodId === 3 ? 2026 : 2027
  return {
    period: { id: periodId, fiscal_year: year, starts_on: `${year}-01-01`, ends_on: `${year}-12-31` },
    from: `${year}-01-01`,
    to: `${year}-12-31`,
    analytics: true,
    draft_count: 0,
    months: [],
    accounts: [{
      account_id: 3138611,
      account_code: '221.400',
      name: 'Běžný účet',
      account_type: 'asset',
      is_synthetic: false,
      opening_md: 0,
      opening_d: 0,
      months: {},
      turnover_md: 100,
      turnover_d: 0,
      closing_md: 100,
      closing_d: 0,
    }],
    totals: { opening_md: 0, opening_d: 0, turnover_md: 100, turnover_d: 0, closing_md: 100, closing_d: 0 },
  }
}

function allPeriodsReport() {
  return {
    ...report(4),
    period: null,
    all_periods: true,
    from: '2026-01-01',
    to: '2027-12-31',
    months: [],
  }
}

describe('GeneralLedger period navigation', () => {
  beforeEach(() => {
    for (const key of Object.keys(routeQuery)) delete routeQuery[key]
    Object.assign(routeQuery, defaultRouteQuery)
    m.listPeriods.mockReset()
    m.getGeneralLedger.mockReset()
    m.replace.mockReset()
    m.listPeriods.mockResolvedValue([
      { id: 3, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'closed' },
      { id: 4, fiscal_year: 2027, starts_on: '2027-01-01', ends_on: '2027-12-31', status: 'open' },
    ])
    m.getGeneralLedger.mockImplementation((params: { period_id?: number; all_periods?: number }) => Promise.resolve(
      params.all_periods ? allPeriodsReport() : report(params.period_id!),
    ))
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('při změně období přepíše rozsah a zachová vybraný rozbalený účet', async () => {
    const wrapper = mount(GeneralLedger, {
      global: {
        stubs: {
          ActivationBanner: true,
          SavedFiltersMenu: true,
          ColumnPicker: true,
          DensityToggle: true,
          EmptyState: true,
          RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()

    expect(wrapper.get('#gl-account-3138611').classes()).toContain('bg-primary-50/40')
    await wrapper.get('select').setValue('4')
    await flushPromises()

    expect(m.getGeneralLedger).toHaveBeenLastCalledWith(expect.objectContaining({
      period_id: 4,
      from: '2027-01-01',
      to: '2027-12-31',
      analytics: 1,
    }))
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({
      period_id: '4',
      from: '2027-01-01',
      to: '2027-12-31',
      analytics: '1',
      account_id: '3138611',
    }) })
    expect(wrapper.get('#gl-account-3138611').classes()).toContain('bg-primary-50/40')
  })

  it('volba Vše načte celý rozsah období a zachová vybraný účet', async () => {
    const wrapper = mount(GeneralLedger, {
      global: {
        stubs: {
          ActivationBanner: true,
          SavedFiltersMenu: true,
          ColumnPicker: true,
          DensityToggle: true,
          EmptyState: true,
          RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()

    expect(wrapper.get('select').findAll('option').some(option => option.text() === 'accounting.general_ledger.all_periods')).toBe(true)
    await wrapper.get('select').setValue('')
    await flushPromises()

    expect(m.getGeneralLedger).toHaveBeenLastCalledWith(expect.objectContaining({
      all_periods: 1,
      from: '2026-01-01',
      to: '2027-12-31',
      analytics: 1,
    }))
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({
      all_periods: '1',
      from: '2026-01-01',
      to: '2027-12-31',
      analytics: '1',
      account_id: '3138611',
    }) })
    expect(wrapper.get('#gl-account-3138611').classes()).toContain('bg-primary-50/40')
  })

  it('odkaz přes dvě období otevře hlavní knihu v režimu všech období', async () => {
    delete routeQuery.period_id
    routeQuery.from = '2026-10-01'
    routeQuery.to = '2027-03-31'
    routeQuery.dimension_value_id = '9'
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    expect(m.getGeneralLedger).toHaveBeenCalledWith(expect.objectContaining({
      all_periods: 1, from: '2026-10-01', to: '2027-03-31', dimension_value_id: '9',
    }))
    expect(m.replace).toHaveBeenCalledWith({ query: expect.objectContaining({ all_periods: '1' }) })
    wrapper.unmount()
  })

  it('po otevření URL obnoví filtr dodavatele, klienta a položky', async () => {
    routeQuery.vendor = 'Dodavatel A'
    routeQuery.client = 'Klient B'
    routeQuery.item = 'Služba C'
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    expect(m.getGeneralLedger).toHaveBeenCalledWith(expect.objectContaining({
      vendor: 'Dodavatel A', client: 'Klient B', item: 'Služba C',
    }))
    expect(m.replace).toHaveBeenCalledWith({ query: expect.objectContaining({
      vendor: 'Dodavatel A', client: 'Klient B', item: 'Služba C',
    }) })
    wrapper.unmount()
  })

  it('zachová vypnutý rozpad analytik v URL', async () => {
    routeQuery.analytics = '0'
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    expect(m.getGeneralLedger).toHaveBeenCalledWith(expect.objectContaining({ analytics: undefined }))
    expect(m.replace).toHaveBeenCalledWith({ query: expect.objectContaining({ analytics: '0' }) })
    wrapper.unmount()
  })
})
