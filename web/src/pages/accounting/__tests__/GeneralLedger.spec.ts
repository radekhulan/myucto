import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  getGeneralLedger: vi.fn(),
  replace: vi.fn(),
  exportReport: vi.fn(),
  toastError: vi.fn(),
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
    exportReport: m.exportReport,
    getAccountStatement: vi.fn(),
    listAccounts: vi.fn().mockResolvedValue([
      { id: 50, account_code: '501', name: 'Spotřeba materiálu', is_active: true, parent_id: null },
      { id: 51, account_code: '501.100', name: 'Materiál kancelář', is_active: true, parent_id: 50 },
      { id: 60, account_code: '602', name: 'Tržby z prodeje služeb', is_active: true, parent_id: null },
    ]),
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

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: { download: 'M0 0' }, btnOutline: () => '', btnOutlineSm: () => '', btnFilled: () => '' }))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { props: ['title'], template: '<div role="dialog"><h2>{{ title }}</h2><slot /><slot name="footer" /></div>' } }))
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

import { accountingApi } from '@/api/accounting'
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
  it('exportuje celý zvolený rozsah účtů s doklady a zachová ostatní filtry', async () => {
    m.exportReport.mockResolvedValue({ data: new Blob(['test']) });
    URL.createObjectURL = vi.fn(() => 'blob:test');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    const wrapper = mount(GeneralLedger, { global: { stubs: {
      ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
      DensityToggle: true, EmptyState: true,
    } } });
    await flushPromises();
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    // Rozsah účtů je filtr knihy: použije se po potvrzení pole a export ho převezme z obrazovky.
    const range = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]');
    expect(range).toHaveLength(2);
    // setValue odešle input i change, tedy potvrzené pole.
    await range[0].setValue('5');
    await range[1].setValue('5');
    await flushPromises();
    expect(m.getGeneralLedger).toHaveBeenLastCalledWith(expect.objectContaining({ account_from: '5', account_to: '5' }));
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({ account_from: '5', account_to: '5' }) });
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    expect(wrapper.get('[role="dialog"]').text()).toContain('accounting.general_ledger.export_accounts');
    const label = wrapper.findAll('label').find(l => l.text().includes('accounting.general_ledger.export_detail'))!;
    await label.get('input').setValue(true);
    const button = wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_submit')!;
    await button.trigger('click');
    await flushPromises();
    expect(m.exportReport).toHaveBeenCalledWith('/accounting/reports/general-ledger/export', expect.objectContaining({
      period_id: 3, analytics: 1, include_documents: 1, account_from: '5', account_to: '5', format: 'pdf',
    }));
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    wrapper.unmount();
    vi.restoreAllMocks();
  });
  it('souhrnný XLSX exportuje zobrazenou knihu, ne rozepsaný filtr ani rozepsaný rozsah účtů', async () => {
    m.exportReport.mockResolvedValue({ data: new Blob(['test']) });
    URL.createObjectURL = vi.fn(() => 'blob:test');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    // Dodavatel rozepsaný bez Enteru na obrazovce neplatí, a tak nesmí platit ani v exportu.
    await wrapper.get('input[placeholder="accounting.general_ledger.filter_vendor_placeholder"]').setValue('Rozepsaný dodavatel');
    // Rozepsaný rozsah účtů (bez potvrzení pole, tedy bez `change`) knihu nenačte a do exportu nepatří.
    const typedRange = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]')[0];
    (typedRange.element as HTMLInputElement).value = '6';
    await typedRange.trigger('input');
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.text()).toContain('2026-01-01');
    expect(dialog.text()).toContain('accounting.general_ledger.filter_analytics');
    expect(dialog.text()).not.toContain('Rozepsaný dodavatel');
    expect(dialog.find('input[type="checkbox"]').exists()).toBe(false);
    expect(dialog.text()).not.toContain('accounting.general_ledger.export_accounts');
    await wrapper.get('input[value="xlsx"]').setValue(true);
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_submit')!.trigger('click');
    await flushPromises();
    const params = m.exportReport.mock.calls.at(-1)![1];
    expect(params).toEqual(expect.objectContaining({ analytics: 1, include_documents: 0, format: 'xlsx' }));
    expect(params.account_from).toBeUndefined();
    expect(params.vendor).toBeUndefined();
    wrapper.unmount();
    vi.restoreAllMocks();
  });
  it('dokud se kniha s novým rozsahem načítá, export z dialogu nejde spustit', async () => {
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    // Pojistka: export z otevřeného dialogu nesmí běžet, dokud se načítá nová kniha (jinak by vzal starou).
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    let finish: (v: unknown) => void = () => {};
    m.getGeneralLedger.mockReturnValueOnce(new Promise(resolve => { finish = resolve }));
    await wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]')[0].setValue('6');
    const submit = () => wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_submit')!;
    expect(submit().attributes('disabled')).toBeDefined();
    finish(report(3));
    await flushPromises();
    expect(submit().attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });
  it('pomalejší starší načtení nepřepíše novější knihu ani adresu', async () => {
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    const pending: Array<(v: unknown) => void> = [];
    m.getGeneralLedger.mockImplementation(() => new Promise(resolve => { pending.push(resolve) }));
    const range = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]');
    await range[0].setValue('5');
    await range[0].setValue('6');
    expect(pending).toHaveLength(2);
    m.replace.mockClear();
    pending[1]({ ...report(3), accounts: [] });
    await flushPromises();
    pending[0](report(3));
    await flushPromises();
    // Platí druhé (novější) načtení: prázdná kniha a adresa s rozsahem od 6.
    expect(wrapper.text()).not.toContain('accounting.general_ledger.totals');
    expect(m.replace).toHaveBeenCalledTimes(1);
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({ account_from: '6' }) });
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    expect(wrapper.get('[role="dialog"]').text()).toContain('accounting.general_ledger.export_accounts');
    wrapper.unmount();
  });
  it('pozdní chyba staršího načtení nesmaže novější knihu ani nevypne načítání novějšího', async () => {
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    const pending: Array<{ resolve: (v: unknown) => void, reject: (e: unknown) => void }> = [];
    m.getGeneralLedger.mockImplementation(() => new Promise((resolve, reject) => { pending.push({ resolve, reject }) }));
    const range = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]');
    await range[0].setValue('5');
    await range[0].setValue('6');
    // Starší požadavek selže, zatímco novější ještě běží: tlačítko exportu musí zůstat zakázané.
    pending[0].reject({ response: { data: { error: { message: 'Stará chyba' } } } });
    await flushPromises();
    expect(m.toastError).not.toHaveBeenCalled();
    const exportButton = () => wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!;
    expect(exportButton().attributes('disabled')).toBeDefined();
    pending[1].resolve(report(3));
    await flushPromises();
    expect(wrapper.text()).toContain('accounting.general_ledger.totals');
    expect(exportButton().attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });
  it('rozpis s doklady nabízí jen PDF', async () => {
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    await wrapper.get('input[value="xlsx"]').setValue(true);
    expect((wrapper.get('input[value="xlsx"]').element as HTMLInputElement).disabled).toBe(false);
    await wrapper.findAll('label').find(l => l.text().includes('accounting.general_ledger.export_detail'))!.get('input').setValue(true);
    expect((wrapper.get('input[value="xlsx"]').element as HTMLInputElement).disabled).toBe(true);
    expect((wrapper.get('input[value="pdf"]').element as HTMLInputElement).checked).toBe(true);
    expect(wrapper.text()).toContain('accounting.general_ledger.export_detail_pdf_only');
    wrapper.unmount();
  });
  it('během exportu ukáže, že se soubor připravuje', async () => {
    let finish: (v: unknown) => void = () => {};
    m.exportReport.mockReturnValue(new Promise(resolve => { finish = resolve }));
    URL.createObjectURL = vi.fn(() => 'blob:test');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_submit')!.trigger('click');
    const busy = wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_preparing')!;
    expect(busy.attributes('disabled')).toBeDefined();
    expect(busy.find('svg.animate-spin').exists()).toBe(true);
    expect(wrapper.get('[role="status"]').text()).toBe('accounting.general_ledger.export_preparing_hint');
    finish({ data: new Blob(['test']) });
    await flushPromises();
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    wrapper.unmount();
    vi.restoreAllMocks();
  });
  it('ukáže hlášku serveru i z chybové odpovědi stahování (Blob)', async () => {
    m.exportReport.mockRejectedValue({ response: { status: 422, data: new Blob([JSON.stringify({ error: { code: 'export_too_large', message: 'Rozpis je příliš velký.' } })], { type: 'application/json' }) } });
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_submit')!.trigger('click');
    await flushPromises();
    expect(m.toastError).toHaveBeenCalledWith('Rozpis je příliš velký.');
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    wrapper.unmount();
  });
  it('upozorní, že analytická mez bez rozpadu na obrazovce vybere celou syntetiku', async () => {
    for (const analytics of ['1', '0']) {
      routeQuery.analytics = analytics
      const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
      await flushPromises();
      expect(wrapper.text()).not.toContain('accounting.general_ledger.range_analytics_hint')
      const from = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]')[0]
      // Rozepsaná mez bez potvrzení knihu nenačte, upozornění proto ještě neplatí.
      ;(from.element as HTMLInputElement).value = '501100'
      await from.trigger('input')
      expect(wrapper.text()).not.toContain('accounting.general_ledger.range_analytics_hint')
      await from.setValue('501100')
      await flushPromises()
      expect(wrapper.text().includes('accounting.general_ledger.range_analytics_hint')).toBe(analytics === '0')
      await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.export_open')!.trigger('click');
      expect(wrapper.get('[role="dialog"]').text().includes('accounting.general_ledger.export_without_analytics')).toBe(analytics === '0')
      wrapper.unmount();
    }
  });
  it('dokud se osnova načítá, mez rozsahu nehlásí, že na ni žádný účet nezačíná', async () => {
    let finish: (v: unknown) => void = () => {}
    vi.mocked(accountingApi.listAccounts).mockReturnValueOnce(new Promise(resolve => { finish = resolve }) as never)
    Object.assign(routeQuery, { account_from: '501' })
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    expect(wrapper.text()).not.toContain('accounting.template.account_prefix_none')
    finish([{ id: 60, account_code: '602', name: 'Tržby z prodeje služeb', is_active: true, parent_id: null }])
    await flushPromises();
    expect(wrapper.text()).toContain('accounting.template.account_prefix_none')
    wrapper.unmount();
  });
  it('převezme rozsah účtů z adresy a smaže ho s ostatními filtry', async () => {
    Object.assign(routeQuery, { account_from: '501', account_to: '602' })
    const wrapper = mount(GeneralLedger, { global: { stubs: { ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true } } });
    await flushPromises();
    expect(m.getGeneralLedger).toHaveBeenLastCalledWith(expect.objectContaining({ account_from: '501', account_to: '602' }));
    const range = wrapper.findAll('input[placeholder="accounting.general_ledger.filter_account_placeholder"]');
    expect((range[0].element as HTMLInputElement).value).toBe('501');
    expect(wrapper.text()).toContain('Spotřeba materiálu');
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.reset_filters')!.trigger('click');
    await flushPromises();
    const params = m.getGeneralLedger.mock.calls.at(-1)![0];
    expect(params.account_from).toBeUndefined();
    expect(params.account_to).toBeUndefined();
    wrapper.unmount();
  });
  beforeEach(() => {
    for (const key of Object.keys(routeQuery)) delete routeQuery[key]
    Object.assign(routeQuery, defaultRouteQuery)
    m.listPeriods.mockReset()
    m.getGeneralLedger.mockReset()
    m.replace.mockReset()
    m.exportReport.mockReset()
    m.toastError.mockReset()
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

  it('rozbalený měsíc načte řádky se stejnými filtry a ve dnech rozsahu knihy', async () => {
    Object.assign(routeQuery, {
      from: '2026-09-10', to: '2026-12-31', dimension_value_id: '9', vendor: 'Dodavatel A', after_closing: '1',
    })
    m.getGeneralLedger.mockImplementation(() => Promise.resolve({
      ...report(3),
      from: '2026-09-10',
      months: ['2026-09'],
      accounts: [{ ...report(3).accounts[0], months: { '2026-09': { md: 100, d: 0 } } }],
    }))
    const statement = vi.mocked(accountingApi.getAccountStatement)
    statement.mockReset()
    statement.mockResolvedValue({ items: [], total: 0 } as never)
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    const month = wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2026-09')).at(-1)!
    await month.trigger('click')
    await flushPromises()
    expect(statement).toHaveBeenCalledWith(3138611, expect.objectContaining({
      from: '2026-09-10', to: '2026-09-30', page: 1, opening_date: '2026-09-10', include_analytics: 0,
      dimension_value_id: '9', dimension_descendants: 1, vendor: 'Dodavatel A', after_closing: 1,
    }))
    // Odkaz „Opis účtu" v hlavičce měsíce nese totéž zúžení, jinak by opis ukázal víc řádků.
    const link = wrapper.findAllComponents({ name: 'RouterLink' })
      .find(l => l.text() === 'accounting.account_statement.title')!
    expect(link.props('to')).toMatchObject({
      name: 'accounting-account-statement',
      query: {
        from: '2026-09-10', to: '2026-09-30', opening_date: '2026-09-10', include_analytics: '0',
        dimension_value_id: '9', dimension_descendants: '1', vendor: 'Dodavatel A', after_closing: '1',
      },
    })
    expect(link.props('to').query).not.toHaveProperty('client')
    wrapper.unmount()
  })

  it('další řádky měsíce načte se stejnými filtry, ne v opisu bez nich', async () => {
    Object.assign(routeQuery, { to: '2026-09-20', dimension_value_id: '9' })
    m.getGeneralLedger.mockImplementation(() => Promise.resolve({
      ...report(3),
      to: '2026-09-20',
      months: ['2026-09'],
      accounts: [{ ...report(3).accounts[0], months: { '2026-09': { md: 100, d: 0 } } }],
    }))
    const line = (id: number) => ({ entry_id: id, entry_date: '2026-09-01', side: 'debit', amount: 1, account_code: '221.400' })
    const statement = vi.mocked(accountingApi.getAccountStatement)
    statement.mockReset()
    statement.mockResolvedValueOnce({ items: Array.from({ length: 200 }, (_, i) => line(i + 1)), total: 201 } as never)
    statement.mockResolvedValueOnce({ items: [line(201)], total: 201 } as never)
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    await wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2026-09')).at(-1)!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'accounting.general_ledger.month_load_more')!.trigger('click')
    await flushPromises()
    expect(statement).toHaveBeenLastCalledWith(3138611, expect.objectContaining({
      from: '2026-09-01', to: '2026-09-20', page: 2, dimension_value_id: '9',
    }))
    expect(wrapper.findAll('button').some(b => b.text() === 'accounting.general_ledger.month_load_more')).toBe(false)
    wrapper.unmount()
  })

  it('jedno období před uzavřením i všechna období po uzavření posílají den PS = začátek knihy', async () => {
    const statement = vi.mocked(accountingApi.getAccountStatement)
    for (const [query, from] of [
      [{}, '2026-01-01'],
      [{ period_id: undefined, all_periods: '1', after_closing: '1' }, '2026-01-01'],
    ] as const) {
      for (const key of Object.keys(routeQuery)) delete routeQuery[key]
      Object.assign(routeQuery, defaultRouteQuery, query)
      if (!('period_id' in query) || query.period_id === undefined) delete routeQuery.period_id
      if (!('all_periods' in query)) routeQuery.period_id = '3'
      m.getGeneralLedger.mockImplementation((params: { all_periods?: number }) => Promise.resolve({
        ...(params.all_periods ? allPeriodsReport() : report(3)),
        months: ['2026-05'],
        accounts: [{ ...report(3).accounts[0], months: { '2026-05': { md: 100, d: 0 } } }],
      }))
      statement.mockReset()
      statement.mockResolvedValue({ items: [], total: 0 } as never)
      const wrapper = mount(GeneralLedger, {
        global: { stubs: {
          ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
          DensityToggle: true, EmptyState: true,
        } },
      })
      await flushPromises()
      await wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2026-05')).at(-1)!.trigger('click')
      await flushPromises()
      expect(statement.mock.calls.at(-1)?.[1]).toMatchObject({ opening_date: from, from: '2026-05-01', to: '2026-05-31' })
      wrapper.unmount()
    }
  })

  it('pozdní odpověď měsíce po změně účtu se nezobrazí', async () => {
    m.getGeneralLedger.mockImplementation(() => Promise.resolve({
      ...report(3),
      months: ['2026-05'],
      accounts: [
        { ...report(3).accounts[0], months: { '2026-05': { md: 100, d: 0 } } },
        { ...report(3).accounts[0], account_id: 3138612, account_code: '221.500', months: { '2026-05': { md: 50, d: 0 } } },
      ],
      totals: report(3).totals,
    }))
    let resolveLate: (v: unknown) => void = () => {}
    const statement = vi.mocked(accountingApi.getAccountStatement)
    statement.mockReset()
    statement.mockImplementationOnce(() => new Promise(r => { resolveLate = r }) as never)
    statement.mockResolvedValue({ items: [], total: 0 } as never)
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    await wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2026-05')).at(-1)!.trigger('click')
    // Týž měsíc otevřený pod jiným účtem nesmí převzít pozdní řádky prvního účtu.
    await wrapper.get('#gl-account-3138612').trigger('click')
    await flushPromises()
    await wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2026-05')).at(-1)!.trigger('click')
    await flushPromises()
    expect(statement).toHaveBeenLastCalledWith(3138612, expect.anything())
    resolveLate({ items: [{ entry_id: 1, entry_date: '2026-05-02', side: 'debit', amount: 100, account_code: '221.400', description: 'Pozdní řádek' }], total: 1 })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Pozdní řádek')
    wrapper.unmount()
  })

  it('všechna období před uzavřením nechají vyřadit všechny otevírací zápisy', async () => {
    delete routeQuery.period_id
    routeQuery.all_periods = '1'
    routeQuery.analytics = '0'
    m.getGeneralLedger.mockImplementation(() => Promise.resolve({
      ...allPeriodsReport(),
      months: ['2027-01'],
      accounts: [{ ...report(4).accounts[0], months: { '2027-01': { md: 100, d: 0 } } }],
    }))
    const statement = vi.mocked(accountingApi.getAccountStatement)
    statement.mockReset()
    statement.mockResolvedValue({ items: [], total: 0 } as never)
    const wrapper = mount(GeneralLedger, {
      global: { stubs: {
        ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        DensityToggle: true, EmptyState: true,
      } },
    })
    await flushPromises()
    await wrapper.findAll('tr').filter(r => r.findAll('td.font-mono').some(td => td.text() === '2027-01')).at(-1)!.trigger('click')
    await flushPromises()
    const params = statement.mock.calls.at(-1)?.[1]
    expect(params).toMatchObject({ from: '2027-01-01', to: '2027-01-31' })
    expect(params?.opening_date).toBeUndefined()
    expect(params?.include_analytics).toBeUndefined()
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
