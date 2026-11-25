import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { StatementOverrideOverview, StatementOverrideSuggestions } from '@/api/accounting'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  getStatementOverrides: vi.fn(),
  saveStatementOverrides: vi.fn(),
  previewStatementOverrides: vi.fn(),
  suggestStatementOverrides: vi.fn(),
  suggestStatementOverridesFromXml: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  routeQuery: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    getStatementOverrides: m.getStatementOverrides,
    saveStatementOverrides: m.saveStatementOverrides,
    previewStatementOverrides: m.previewStatementOverrides,
    suggestStatementOverrides: m.suggestStatementOverrides,
    suggestStatementOverridesFromXml: m.suggestStatementOverridesFromXml,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, success: m.toastSuccess }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.routeQuery() ?? {} }),
}))

import StatementMapping from '@/pages/accounting/StatementMapping.vue'

function overview(overrides: Partial<StatementOverrideOverview> = {}): StatementOverrideOverview {
  return {
    statement_type: 'balance_sheet',
    version: { id: 1, statement_type: 'balance_sheet', version_code: 'vyhl500-2002/2024' },
    period: { id: 6, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
    as_of: '2099-12-31',
    rows: [
      { row_code: 'P.C.I.9.1.', display_code: 'C.I.9.1.', parent_row_code: 'P.C.I.9.', section: 'liabilities', label: 'Závazky ke společníkům', level: 4, row_type: 'detail', value: 0 },
      { row_code: 'P.C.II.8.1.', display_code: 'C.II.8.1.', parent_row_code: 'P.C.II.8.', section: 'liabilities', label: 'Závazky ke společníkům', level: 4, row_type: 'detail', value: 530000 },
    ],
    overrides: [],
    accounts: [
      { account_code: '365.100', name: 'Půjčka od společníka', account_type: 'liability', is_synthetic: false, parent_code: '365', balance: -500000,
        mappings: [{ row_code: 'P.C.II.8.1.', target: 'gross', balance_condition: 'any', source: 'global' }] },
      { account_code: '365.200', name: 'Ostatní závazky', account_type: 'liability', is_synthetic: false, parent_code: '365', balance: -30000,
        mappings: [{ row_code: 'P.C.II.8.1.', target: 'gross', balance_condition: 'any', source: 'global' }] },
    ],
    filed_return: null,
    ...overrides,
  }
}

describe('StatementMapping.vue', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
    m.listPeriods.mockResolvedValue([
      { id: 6, supplier_id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open', closed_at: null, created_at: '' },
    ])
    m.saveStatementOverrides.mockResolvedValue({ overrides: [] })
  })

  it('otevře účelovou výsledovku v období z odkazu uzávěrky', async () => {
    m.routeQuery.mockReturnValue({ period_id: '6', statement_type: 'income_statement_purpose' })
    m.getStatementOverrides.mockResolvedValue(overview())
    const wrapper = mount(StatementMapping)
    await flushPromises()

    expect(m.getStatementOverrides).toHaveBeenCalledWith(6, 'income_statement_purpose')
    expect(wrapper.findAll('[data-test="type-tab"]')).toHaveLength(3)
  })

  it('(a) ukáže účty s řádkem, kam dnes jdou, a zdroj zařazení', async () => {
    m.getStatementOverrides.mockResolvedValue(overview())
    const wrapper = mount(StatementMapping)
    await flushPromises()

    expect(m.getStatementOverrides).toHaveBeenCalledWith(6, 'balance_sheet')
    const row = wrapper.find('[data-test="account-365.100"]')
    expect(row.text()).toContain('C.II.8.1. Závazky ke společníkům')
    expect(row.text()).toContain('accounting.statements.mapping.source_global')
    expect(wrapper.find('[data-test="save-bar"]').exists()).toBe(false)
  })

  it('(b) změna řádku zapne jedno společné Uložit a uloží celou sadu', async () => {
    m.getStatementOverrides.mockResolvedValue(overview())
    const wrapper = mount(StatementMapping)
    await flushPromises()

    await wrapper.find('[data-test="account-365.100"] [data-test="row-select"]').setValue('P.C.I.9.1.')
    expect(wrapper.find('[data-test="save-bar"]').exists()).toBe(true)

    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [
      { account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: null, valid_to_year: null },
    ])
    expect(m.toastSuccess).toHaveBeenCalled()
  })

  it('(c) zrušení uložené výjimky se uloží jako prázdná sada', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      overrides: [{ id: 3, version_id: 1, account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: 'splatnost 3 roky' }],
    }))
    const wrapper = mount(StatementMapping)
    await flushPromises()

    await wrapper.find('[data-test="account-365.100"] [data-test="remove"]').trigger('click')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [])
  })

  it('(d) náhled dopadu posílá neuloženou sadu a ukáže změněné řádky', async () => {
    m.getStatementOverrides.mockResolvedValue(overview())
    m.previewStatementOverrides.mockResolvedValue({
      statement_type: 'balance_sheet', version_id: 1, balanced_before: true, balanced_after: true,
      rows: [
        { row_code: 'P.C.I.9.1.', display_code: 'C.I.9.1.', label: 'Závazky ke společníkům', level: 4, section: 'liabilities', before: 0, after: 500000, delta: 500000 },
        { row_code: 'P.C.II.8.1.', display_code: 'C.II.8.1.', label: 'Závazky ke společníkům', level: 4, section: 'liabilities', before: 530000, after: 30000, delta: -500000 },
      ],
    })
    const wrapper = mount(StatementMapping)
    await flushPromises()

    await wrapper.find('[data-test="account-365.100"] [data-test="row-select"]').setValue('P.C.I.9.1.')
    const previewBtn = wrapper.findAll('button').find(b => b.text().includes('accounting.statements.mapping.action_preview'))
    expect(previewBtn).toBeTruthy()
    await previewBtn!.trigger('click')
    await flushPromises()

    expect(m.previewStatementOverrides).toHaveBeenCalledWith(6, 'balance_sheet', [
      { account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: null, valid_to_year: null },
    ])
    expect(wrapper.findAll('[data-test="preview-row"]')).toHaveLength(2)
    expect(m.saveStatementOverrides).not.toHaveBeenCalled()
  })

  it('(e) návrh z podaného přiznání se po převzetí dostane do úprav a uloží', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      filed_return: { submission_id: 9, year: 2099, status: 'submitted', submitted_at: '2100-03-31 10:00:00', form_variant: 'B' },
    }))
    const result: StatementOverrideSuggestions = {
      period_id: 6, year: 2099, source: { type: 'filed_return', submission_id: 9 },
      differences: [{ sentence: 'VetaUD', c_radku: 43, row_code: 'P.C.I.9.1.', app: 0, filed: 500, diff: -500 }],
      suggestions: [{
        statement_type: 'balance_sheet', version_id: 1, account_code: '365.100', account_name: 'Půjčka od společníka',
        amount: 500000, amount_thousands: 500, current_row_code: 'P.C.II.8.1.', from_row_code: 'P.C.II.8.1.', from_label: 'Závazky ke společníkům',
        to_row_code: 'P.C.I.9.1.', to_label: 'Závazky ke společníkům', to_is_subtotal: false, balance_condition: 'any', target: 'gross',
        sign: 1, reason: 'Účet 365.100 …', ambiguous: false, confidence: 'exact', accounts: ['365.100'],
        overrides: [{ account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', note: null }],
      }],
    }
    m.suggestStatementOverrides.mockResolvedValue(result)
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const suggestBtn = wrapper.findAll('button').find(b => b.text().includes('accounting.statements.mapping.action_suggest'))
    await suggestBtn!.trigger('click')
    await flushPromises()
    expect(m.suggestStatementOverrides).toHaveBeenCalledWith(6)
    expect(wrapper.findAll('[data-test="suggestion"]')).toHaveLength(1)

    await wrapper.find('[data-test="apply-suggestions"]').trigger('click')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [expect.objectContaining({
      account_prefix: '365.100', row_code: 'P.C.I.9.1.', balance_condition: 'any',
    })])
  })

  it('(g) výjimka pro skupinu účtů je vidět u každého účtu skupiny i s poznámkou a jde upravit dole', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      overrides: [{ id: 5, version_id: 1, account_prefix: '365.', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: 'Návrh z podaného přiznání' }],
      accounts: overview().accounts.map(a => ({ ...a, mappings: [{ row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', source: 'override' }] })),
    }))
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const row = wrapper.find('[data-test="account-365.200"]')
    const select = row.find('[data-test="row-select"]')
    expect((select.element as HTMLSelectElement).value).toBe('')
    expect(select.find('option').text()).toBe('accounting.statements.mapping.row_inherited:{"prefix":"365."}')
    expect(row.find('[data-test="inherited-note"]').text()).toContain('Návrh z podaného přiznání')
    expect(row.find('[data-test="remove"]').exists()).toBe(false)

    const group = wrapper.find('[data-test="group-365."]')
    expect(group.text()).toContain('accounting.statements.mapping.orphans_accounts:{"count":2}')
    await group.find('input[type="text"]').setValue('dlouhodobá půjčka')
    await group.find('input[type="text"]').trigger('change')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [
      { account_prefix: '365.', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: 'dlouhodobá půjčka', valid_from_year: null, valid_to_year: null },
    ])
  })

  it('(h) vlastní výjimka účtu má přednost před výjimkou skupiny', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      overrides: [
        { id: 5, version_id: 1, account_prefix: '365.', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null },
        { id: 6, version_id: 1, account_prefix: '365.100', row_code: 'P.C.II.8.1.', target: 'gross', balance_condition: 'any', sign: 1, note: 'krátkodobá' },
      ],
    }))
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const own = wrapper.find('[data-test="account-365.100"] [data-test="row-select"]')
    expect((own.element as HTMLSelectElement).value).toBe('P.C.II.8.1.')
    expect(own.find('option').text()).toBe('accounting.statements.mapping.row_global')
    expect(wrapper.find('[data-test="account-365.100"] [data-test="inherited-note"]').exists()).toBe(false)
  })

  it('(i) výjimka jiného roku platnosti je v samostatném přehledu a nový řádek platí od roku editoru', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      overrides: [{ id: 7, version_id: 1, account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: null, valid_to_year: 2098 }],
    }))
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const select = wrapper.find('[data-test="account-365.100"] [data-test="row-select"]')
    // Výjimka do roku 2098 v roce 2099 neplatí.
    expect((select.element as HTMLSelectElement).value).toBe('')
    expect(wrapper.find('[data-test="other-years"]').text()).toContain('365.100')

    await select.setValue('P.C.II.8.1.')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [
      { account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: null, valid_to_year: 2098 },
      { account_prefix: '365.100', row_code: 'P.C.II.8.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: 2099, valid_to_year: null },
    ])
  })

  it('(j) korekce jde navázat na účet pohledávky a vazba se uloží', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      rows: [
        ...overview().rows,
        { row_code: 'C.II.1.5.4.', display_code: 'C.II.1.5.4.', parent_row_code: 'C.II.1.5.', section: 'assets', label: 'Jiné pohledávky', level: 4, row_type: 'detail', value: 0 },
      ],
      overrides: [{ id: 8, version_id: 1, account_prefix: '365.100', row_code: 'C.II.1.5.4.', target: 'correction', balance_condition: 'any', sign: 1, note: null }],
    }))
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const follows = wrapper.find('[data-test="account-365.100"] [data-test="follows"]')
    await follows.setValue('351.100')
    await follows.trigger('change')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [
      { account_prefix: '365.100', row_code: 'C.II.1.5.4.', target: 'correction', balance_condition: 'any', sign: 1, note: null, valid_from_year: null, valid_to_year: null, follows_prefix: '351.100' },
    ])
  })

  it('(k) návrh pro minulé období rozdělí stávající výjimku podle let platnosti', async () => {
    m.getStatementOverrides.mockResolvedValue(overview({
      filed_return: { submission_id: 9, year: 2099, status: 'submitted', submitted_at: '2100-03-31 10:00:00', form_variant: 'B' },
      overrides: [{ id: 3, version_id: 1, account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null }],
    }))
    const prior = {
      statement_type: 'balance_sheet' as const, version_id: 1, account_code: '365.100', account_name: 'Půjčka od společníka',
      amount: 500000, amount_thousands: 500, current_row_code: 'P.C.I.9.1.', from_row_code: 'P.C.I.9.1.', from_label: 'Závazky ke společníkům',
      to_row_code: 'P.C.II.8.1.', to_label: 'Závazky ke společníkům', to_is_subtotal: false, balance_condition: 'any' as const, target: 'gross' as const,
      sign: 1, reason: 'minulé období', ambiguous: false, confidence: 'exact' as const, accounts: ['365.100'],
      overrides: [{ account_prefix: '365.100', row_code: 'P.C.II.8.1.', target: 'gross' as const, balance_condition: 'any' as const, note: null, valid_to_year: 2098 }],
    }
    m.suggestStatementOverrides.mockResolvedValue({
      period_id: 6, year: 2099, source: { type: 'filed_return', submission_id: 9 }, differences: [], suggestions: [],
      prior_period: { period_id: 5, year: 2098, suggestions: [prior], differences: [] },
    })
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const suggestBtn = wrapper.findAll('button').find(b => b.text().includes('accounting.statements.mapping.action_suggest'))
    await suggestBtn!.trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-test="prior-suggestion"]')).toHaveLength(1)

    await wrapper.find('[data-test="apply-suggestions"]').trigger('click')
    await wrapper.find('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(m.saveStatementOverrides).toHaveBeenCalledWith(1, [
      { account_prefix: '365.100', row_code: 'P.C.I.9.1.', target: 'gross', balance_condition: 'any', sign: 1, note: null, valid_from_year: 2099, valid_to_year: null },
      expect.objectContaining({ account_prefix: '365.100', row_code: 'P.C.II.8.1.', valid_from_year: null, valid_to_year: 2098 }),
    ])
  })

  it('(f) bez podaného přiznání je návrh zašedlý s vysvětlením', async () => {
    m.getStatementOverrides.mockResolvedValue(overview())
    const wrapper = mount(StatementMapping)
    await flushPromises()

    const suggestBtn = wrapper.findAll('button').find(b => b.text().includes('accounting.statements.mapping.action_suggest'))
    expect(suggestBtn!.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('accounting.statements.mapping.suggest_disabled')
  })
})
