import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { SaldoReport } from '@/api/accounting'

// ── Mockovaný stav (hoisted) ─────────────────────────────────────────────────
const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  getSaldo: vi.fn(),
  exportReport: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    getSaldo: m.getSaldo,
    exportReport: m.exportReport,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { download: 'M0 0', doc: 'M0 0', user: 'M0 0', link: 'M0 0', check: 'M0 0', plus: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
  btnOutlineSm: () => 'btn-outline-sm',
}))

// Práva uživatele: bez zápisu do deníku stránka akce vyrovnání nenabízí.
const authState = vi.hoisted(() => ({ write: [] as string[] }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (key: string) => authState.write.includes(key),
    isCompanyAdminRole: false,
  }),
}))

// Dialogy vyrovnání mají vlastní testy; tady jen to, s čím je stránka otevře.
vi.mock('@/components/accounting/SaldoSettleDialog.vue', () => ({
  __esModule: true,
  default: { name: 'SaldoSettleDialog', props: ['items', 'asOf'], template: '<div data-testid="settle-stub" />' },
}))
vi.mock('@/components/accounting/SaldoAdvanceSettleDialog.vue', () => ({
  __esModule: true,
  default: { name: 'SaldoAdvanceSettleDialog', props: ['items', 'asOf'], template: '<div data-testid="advance-settle-stub" />' },
}))
vi.mock('@/components/accounting/SaldoResidualDialog.vue', () => ({
  __esModule: true,
  default: { name: 'SaldoResidualDialog', props: ['accountCode', 'accountName', 'asOf'], template: '<div data-testid="residual-stub" />' },
}))
vi.mock('@/components/accounting/SaldoSuggestionsDialog.vue', () => ({
  __esModule: true,
  default: { name: 'SaldoSuggestionsDialog', props: ['periodId', 'asOf', 'account'], template: '<div data-testid="suggest-stub" />' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

// Uložené volby stránky (členění PDF podle doby po splatnosti) — per test nastavitelné.
const prefOptions = vi.hoisted(() => ({ value: {} as Record<string, unknown>, set: vi.fn() }))

vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => ({
    option: (key: string, fallback: unknown) => prefOptions.value[key] ?? fallback,
    setOption: prefOptions.set,
  }),
}))

// `route.query` pohání deep-link `?period_id=&as_of=&account=&view=…`; testy
// jedou bez query, takže vychází výchozí chování stránky.
const routeQuery = vi.hoisted(() => ({ value: {} as Record<string, string> }))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: routeQuery.value }),
}))

import Saldokonto from '@/pages/accounting/Saldokonto.vue'

function makeReport(overrides: Partial<SaldoReport> = {}): SaldoReport {
  const period = { id: 6, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open' }
  return {
    as_of: '2099-06-30',
    entity: { name: 'Test s.r.o.', ico: '123', address: 'Praha', prepared_at: '2099-06-30 10:00' },
    period,
    as_of_period: period,
    accounts: [
      {
        account: { id: 1, code: '311', name: 'Odběratelé', normal_side: 'debit' as any },
        gl_balance: 1500,
        open_items_total: 1500,
        open_items_count: 2,
        difference: 0,
        matches: true,
        partners_pagination: { page: 1, per_page: 2, total: 2, pages: 1 },
        partners: [
          {
            partner_id: 10,
            partner_name: 'Alfa s.r.o.',
            total_remaining: 1000,
            items: [
              { doc_type: 'invoice', doc_id: 101, doc_no: 'F-101', issue_date: '2099-01-10', due_date: '2099-01-24',
                currency_code: 'CZK', amount_foreign: 0, booked_czk: 1000, paid_czk: 0, remaining_czk: 1000, days_overdue: 20, settleable: true },
            ],
          },
          {
            partner_id: 11,
            partner_name: 'Beta a.s.',
            total_remaining: 500,
            items: [
              { doc_type: 'invoice', doc_id: 102, doc_no: 'F-102', issue_date: '2099-05-01', due_date: '2099-05-15',
                currency_code: 'CZK', amount_foreign: 0, booked_czk: 500, paid_czk: 0, remaining_czk: 500, days_overdue: 0, settleable: true },
            ],
          },
        ],
      },
    ],
    ...overrides,
  }
}

/**
 * Server vrací účet, o který si stránka řekne; „všechny účty“ stránka skládá
 * ze souběžných požadavků po jednotlivých účtech.
 */
function serveSaldo(report: SaldoReport) {
  m.getSaldo.mockImplementation(async (params: { account?: string }) => ({
    ...report,
    accounts: !params.account || params.account === 'all'
      ? report.accounts
      : report.accounts.filter(b => b.account.code === params.account),
  }))
}

describe('Saldokonto.vue', () => {
  beforeEach(() => {
    m.listPeriods.mockReset()
    m.getSaldo.mockReset()
    m.exportReport.mockReset()
    m.toastError.mockReset()
    prefOptions.value = {}
    prefOptions.set.mockReset()
    authState.write = []
    m.listPeriods.mockResolvedValue([
      { id: 6, supplier_id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open', closed_at: null, created_at: '' },
    ])
  })

  it('ukáže vedle čísla dokladu druhé číslo přijaté faktury, jen když ho položka má', async () => {
    const report = makeReport()
    report.accounts[0].partners[0].items[0] = {
      ...report.accounts[0].partners[0].items[0],
      doc_type: 'purchase_invoice',
      doc_no: 'PF2605001',
      other_doc_no: 'FA-2026-001',
    }
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const hints = wrapper.findAll('[data-testid="saldo-other-doc-no"]')
    expect(hints).toHaveLength(1)
    expect(hints[0].text()).toContain('FA-2026-001')
    expect(wrapper.text()).toContain('PF2605001')
  })

  it('(a) výchozí pohled je plochý seznam, seřazený dle splatnosti vzestupně napříč partnery', async () => {
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    // Alfa (splatnost 24.1.) musí být před Beta (15.5.).
    expect(rows[0].text()).toContain('F-101')
    expect(rows[0].text()).toContain('Alfa s.r.o.')
    expect(rows[1].text()).toContain('F-102')
  })

  it('PDF bez zapnutého členění zůstává inventarizačním protokolem', async () => {
    serveSaldo(makeReport())
    m.exportReport.mockResolvedValue({ data: new Blob() })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const wrapper = mount(Saldokonto)
    await flushPromises()

    await wrapper.findAll('button').find(b => b.text().includes('accounting.saldo.export_pdf'))!.trigger('click')
    await flushPromises()

    expect(m.exportReport.mock.calls[0][1]).not.toHaveProperty('aging')
  })

  it('uložená volba členění se načte a pošle do exportu PDF, změna hranic se uloží', async () => {
    prefOptions.value = { pdf_aging: { enabled: true, boundaries: [30, 90], detail: false } }
    serveSaldo(makeReport())
    m.exportReport.mockResolvedValue({ data: new Blob() })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const input = wrapper.find('[data-testid="saldo-pdf-aging-boundaries"]')
    expect((input.element as HTMLInputElement).value).toBe('30, 90')

    await wrapper.findAll('button').find(b => b.text().includes('accounting.saldo.export_pdf'))!.trigger('click')
    await flushPromises()
    expect(m.exportReport.mock.calls[0][1]).toMatchObject({ format: 'pdf', aging: '30,90', detail: '0' })

    await input.setValue('120, 30, 60')
    await flushPromises()
    expect(prefOptions.set).toHaveBeenLastCalledWith('pdf_aging', { enabled: true, boundaries: [30, 60, 120], detail: false })

    await input.setValue('30, abc')
    await wrapper.findAll('button').find(b => b.text().includes('accounting.saldo.export_pdf'))!.trigger('click')
    await flushPromises()
    expect(m.exportReport).toHaveBeenCalledTimes(1)
    expect(m.toastError).toHaveBeenCalledWith('accounting.saldo.pdf_aging_invalid')
  })

  it('otevře ostatní pohledávku v její agendě', async () => {
    const report = makeReport()
    report.accounts[0].account.code = '315'
    report.accounts[0].partners[0].items[0] = {
      doc_type: 'other_item', doc_id: 301, doc_no: 'OP-301',
      issue_date: '2099-01-10', due_date: '2099-01-24', currency_code: 'CZK',
      amount_foreign: 0, booked_czk: 1000, paid_czk: 0, remaining_czk: 1000, days_overdue: 20,
    }
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const link = wrapper.findAllComponents({ name: 'RouterLink' }).find(c => c.text() === 'OP-301')
    expect(link?.props('to')).toEqual({ name: 'other-item-detail', params: { id: 301 } })
  })

  it('(b) přepnutí na "podle partnera" vykreslí seskupenou tabulku se dvěma partnery', async () => {
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const partnerBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.saldo.view_by_partner')
    expect(partnerBtn).toBeTruthy()
    await partnerBtn!.trigger('click')
    await flushPromises()

    // Grouped řádky per partner (bez rozbalení položek).
    expect(wrapper.text()).toContain('Alfa s.r.o.')
    expect(wrapper.text()).toContain('Beta a.s.')
    expect(wrapper.text()).not.toContain('F-101') // dokud se partner nerozbalí
  })

  it('(c) filtr partnera zúží plochý seznam na jeho doklady', async () => {
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const partnerSelect = wrapper.findAll('select').find(s => s.findAll('option').some(o => o.text() === 'Alfa s.r.o.'))
    expect(partnerSelect).toBeTruthy()
    await partnerSelect!.setValue('10')
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('F-101')
  })

  it('(d) filtr "po splatnosti (dní, min.)" zúží na doklady po splatnosti', async () => {
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const overdueInput = wrapper.find('input[type="number"]')
    expect(overdueInput.exists()).toBe(true)
    await overdueInput.setValue(1)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('F-101') // jediný s days_overdue > 0
  })

  it('(e) upozorní, když as_of_period neodpovídá vybranému období', async () => {
    const report = makeReport({
      as_of_period: { id: 5, fiscal_year: 2098, starts_on: '2098-01-01', ends_on: '2098-12-31', status: 'closed' },
    })
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(wrapper.text()).toContain('accounting.saldo.period_mismatch_hint')
    const switchBtn = wrapper.findAll('button').find(b => b.text().includes('period_mismatch_switch'))
    expect(switchBtn).toBeTruthy()
  })

  it('(f) konfrontace se zůstatkem hlavní knihy je i v plochém pohledu', async () => {
    serveSaldo(makeReport({
      accounts: [{ ...makeReport().accounts[0], open_items_total: 1200, difference: 300, matches: false }],
    }))
    const wrapper = mount(Saldokonto)
    await flushPromises()

    // Výchozí pohled je plochý; bez konfrontace by uživatel četl seznam dokladů
    // jako úplný, i kdyby se účet lišil o statisíce.
    expect(wrapper.text()).toContain('accounting.saldo.gl_balance')
    expect(wrapper.text()).toContain('accounting.saldo.open_items_total')
    expect(wrapper.text()).toContain('accounting.saldo.difference_hint')
  })

  it('(g) deep-link z adresy předvyplní období, rozvahový den, účet i pohled', async () => {
    routeQuery.value = { period_id: '6', as_of: '2099-03-31', account: '321', view: 'partner' }
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(m.getSaldo).toHaveBeenCalledWith({ period_id: 6, as_of: '2099-03-31', account: '321' })
    // Pohled z query, ne výchozí plochý.
    const partnerBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.saldo.view_by_partner')
    expect(partnerBtn!.classes()).toContain('btn-filled')
    routeQuery.value = {}
  })

  it('(i) pohledávky a závazky jsou dvě oddělené tabulky, ne jeden seznam dle splatnosti', async () => {
    // Přijatá faktura je splatná mezi vydanými — v jednom seznamu řazeném dle
    // splatnosti seděla mezi nimi a od pohledávek ji odlišoval jen kód účtu.
    const base = makeReport()
    serveSaldo(makeReport({
      accounts: [
        base.accounts[0],
        {
          account: { id: 2, code: '321', name: 'Dodavatelé', normal_side: 'credit' as any },
          gl_balance: 300,
          open_items_total: 300,
          open_items_count: 1,
          difference: 0,
          matches: true,
          partners_pagination: { page: 1, per_page: 1, total: 1, pages: 1 },
          partners: [
            {
              partner_id: 20,
              partner_name: 'Vodafone Czech Republic a.s.',
              total_remaining: 300,
              items: [
                { doc_type: 'purchase_invoice', doc_id: 201, doc_no: 'PF-201', issue_date: '2099-02-01', due_date: '2099-02-20',
                  currency_code: 'CZK', amount_foreign: 0, booked_czk: 300, paid_czk: 0, remaining_czk: 300, days_overdue: 0 },
              ],
            },
          ],
        },
      ],
    }))
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const tables = wrapper.findAll('table')
    expect(tables).toHaveLength(2)
    const receivables = tables[0].text()
    const payables = tables[1].text()
    expect(receivables).toContain('F-101')
    expect(receivables).toContain('F-102')
    expect(receivables).not.toContain('PF-201')
    expect(payables).toContain('PF-201')
    expect(payables).not.toContain('F-101')
    expect(wrapper.text()).toContain('accounting.saldo.side_receivable')
    expect(wrapper.text()).toContain('accounting.saldo.side_payable')
  })

  it('(h) neexistující období z adresy nezhodí načtení, spadne na výchozí rok', async () => {
    routeQuery.value = { period_id: '999' }
    serveSaldo(makeReport())
    mount(Saldokonto)
    await flushPromises()

    expect(m.getSaldo).toHaveBeenCalledWith({ period_id: 6, as_of: undefined, account: '311' })
    routeQuery.value = {}
  })

  it('(j) všechny účty načte souběžně po účtech a vynechá účty bez zůstatku i položek', async () => {
    const base = makeReport()
    const empty = { ...base.accounts[0], account: { id: 3, code: '314', name: 'Zálohy', normal_side: 'debit' as any },
      gl_balance: 0, open_items_total: 0, open_items_count: 0, partners: [] }
    serveSaldo(makeReport({ accounts: [base.accounts[0], empty] }))
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(m.getSaldo.mock.calls.map(c => c[0].account)).toEqual(['311', '321', '314', '324', '315', '325'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('314 - Zálohy')
  })

  it('(k) bez práva zápisu do deníku stránka vyrovnání nenabízí', async () => {
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(wrapper.find('[data-testid="saldo-suggest"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(b => b.text().includes('accounting.saldo_settle.settle'))).toBe(false)
    expect(wrapper.findAll('tbody input[type="checkbox"]')).toHaveLength(0)
  })

  it('(l) Vyrovnat otevře dialog s položkou, účtem a partnerem', async () => {
    authState.write = ['accounting.journal.write']
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const settle = wrapper.findAll('tbody button').filter(b => b.text().includes('accounting.saldo_settle.settle'))
    expect(settle).toHaveLength(2)
    await settle[0].trigger('click')
    await flushPromises()

    const dialog = wrapper.findComponent({ name: 'SaldoSettleDialog' })
    expect(dialog.exists()).toBe(true)
    expect(dialog.props('asOf')).toBe('2099-06-30')
    expect(dialog.props('items')).toEqual([{
      doc_type: 'invoice', doc_id: 101, doc_no: 'F-101', partner_id: 10, partner_name: 'Alfa s.r.o.',
      account_code: '311', currency_code: 'CZK', remaining_czk: 1000,
    }])
  })

  it('(m) hromadné vyrovnání jen pro doklady jednoho partnera a účtu', async () => {
    authState.write = ['accounting.journal.write']
    const report = makeReport()
    report.accounts[0].partners[0].items.push({
      doc_type: 'invoice', doc_id: 103, doc_no: 'F-103', issue_date: '2099-02-01', due_date: '2099-02-15',
      currency_code: 'CZK', amount_foreign: 0, booked_czk: 200, paid_czk: 0, remaining_czk: 200, days_overdue: 0, settleable: true,
    })
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    expect(boxes).toHaveLength(3)
    // Řazení podle splatnosti: F-101 (Alfa), F-103 (Alfa), F-102 (Beta).
    await boxes[0].setValue(true)
    await boxes[2].setValue(true)
    const bulk = () => wrapper.findAll('button').find(b => b.text().includes('accounting.saldo_settle.settle_selected'))!
    expect(bulk().attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('accounting.saldo_settle.selected_mixed')

    await boxes[2].setValue(false)
    await boxes[1].setValue(true)
    expect(bulk().attributes('disabled')).toBeUndefined()
    await bulk().trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'SaldoSettleDialog' }).props('items').map((i: { doc_id: number }) => i.doc_id)).toEqual([101, 103])
  })

  it('(n) cizoměnový doklad a ostatní položku rozpadem zápisu vyrovnat nelze', async () => {
    authState.write = ['accounting.journal.write']
    const report = makeReport()
    report.accounts[0].partners[0].items[0].currency_code = 'EUR'
    report.accounts[0].partners[1].items[0] = {
      doc_type: 'other_item', doc_id: 301, doc_no: 'OP-301', issue_date: '2099-01-10', due_date: '2099-01-24',
      currency_code: 'CZK', amount_foreign: 0, booked_czk: 500, paid_czk: 0, remaining_czk: 500, days_overdue: 0,
    }
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(wrapper.findAll('tbody button').some(b => b.text().includes('accounting.saldo_settle.settle'))).toBe(false)
  })

  it('(o) rozdíl k hlavní knize nabídne položku ze zůstatku jen s právem k ostatním položkám', async () => {
    authState.write = ['accounting.journal.write']
    const report = makeReport()
    report.accounts[0].gl_balance = 2200
    report.accounts[0].difference = 700
    report.accounts[0].matches = false
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()
    expect(wrapper.findAll('button').some(b => b.text().includes('accounting.saldo_settle.residual_open'))).toBe(false)

    authState.write = ['accounting.journal.write', 'other_items']
    const allowed = mount(Saldokonto)
    await flushPromises()
    await allowed.findAll('button').find(b => b.text().includes('accounting.saldo_settle.residual_open'))!.trigger('click')
    await flushPromises()
    const dialog = allowed.findComponent({ name: 'SaldoResidualDialog' })
    expect(dialog.props()).toMatchObject({ accountCode: '311', accountName: 'Odběratelé', asOf: '2099-06-30' })
  })

  it('(p) Navrhnout párování otevře návrh pro období a účet sestavy', async () => {
    authState.write = ['accounting.journal.write']
    serveSaldo(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    await wrapper.find('[data-testid="saldo-suggest"]').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'SaldoSuggestionsDialog' }).props()).toMatchObject({ periodId: 6, asOf: '2099-06-30', account: 'all' })
  })

  it('(q) záloha na 314 se vyrovná čerpáním konečnou fakturou, ne rozpadem zápisu', async () => {
    authState.write = ['accounting.journal.write']
    const report = makeReport({
      accounts: [{
        account: { id: 3, code: '314', name: 'Poskytnuté zálohy', normal_side: 'debit' as any },
        gl_balance: 0,
        open_items_total: 1000,
        open_items_count: 2,
        difference: -1000,
        matches: false,
        partners_pagination: { page: 1, per_page: 2, total: 1, pages: 1 },
        partners: [{
          partner_id: 20,
          partner_name: 'Gama s.r.o.',
          total_remaining: 1000,
          items: [
            { doc_type: 'purchase_invoice', doc_id: 201, doc_no: 'ZF-201', issue_date: '2099-01-10', due_date: '2099-01-24',
              currency_code: 'CZK', amount_foreign: 0, booked_czk: 1000, paid_czk: 0, remaining_czk: 1000, days_overdue: 0, settleable: true },
            { doc_type: 'purchase_invoice', doc_id: 202, doc_no: 'ZF-202', issue_date: '2099-02-10', due_date: '2099-02-24',
              currency_code: 'EUR', amount_foreign: 40, booked_czk: 1000, paid_czk: 0, remaining_czk: 1000, days_overdue: 0, settleable: true },
          ],
        }],
      }],
    })
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const settle = wrapper.findAll('tbody button').filter(b => b.text().includes('accounting.saldo_settle.settle'))
    expect(settle).toHaveLength(1)
    await settle[0].trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="saldo-advance-issues"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'SaldoSettleDialog' }).exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'SaldoAdvanceSettleDialog' }).props('items')).toEqual([{
      doc_type: 'purchase_invoice', doc_id: 201, doc_no: 'ZF-201', partner_id: 20, partner_name: 'Gama s.r.o.',
      account_code: '314', currency_code: 'CZK', remaining_czk: 1000,
    }])
  })

  it('(s) pohledávka i závazek jednoho partnera nabídnou vzájemný zápočet vyrovnaný na menší stranu', async () => {
    const report = makeReport()
    report.accounts.push({
      account: { id: 2, code: '321', name: 'Dodavatelé', normal_side: 'credit' as any },
      gl_balance: 400, open_items_total: 400, open_items_count: 1, difference: 0, matches: true,
      partners_pagination: { page: 1, per_page: 2, total: 1, pages: 1 },
      partners: [{
        partner_id: 10, partner_name: 'Alfa s.r.o.', total_remaining: 400,
        items: [{ doc_type: 'purchase_invoice', doc_id: 201, doc_no: 'PF-201', issue_date: '2099-01-12', due_date: '2099-01-26',
          currency_code: 'CZK', amount_foreign: 0, booked_czk: 400, paid_czk: 0, remaining_czk: 400, days_overdue: 0, settleable: true }],
      }],
    })
    serveSaldo(report)

    // Bez práva k zápočtům (ani k deníku) se výběr nenabízí.
    const denied = mount(Saldokonto)
    await flushPromises()
    expect(denied.findAll('tbody input[type="checkbox"]')).toHaveLength(0)

    authState.write = ['accounting.offsets']
    const wrapper = mount(Saldokonto)
    await flushPromises()
    const pick = (doc: string) => wrapper.find(`input[aria-label*="${doc}"]`)
    await pick('F-101').setValue(true)
    expect(wrapper.find('[data-testid="saldo-mutual-offset"]').exists()).toBe(false)
    await pick('PF-201').setValue(true)
    const link = wrapper.findAllComponents({ name: 'RouterLink' }).find(l => (l.props('to') as { name?: string })?.name === 'accounting-offsets')
    expect(link?.props('to')).toEqual({
      name: 'accounting-offsets',
      query: { partner_id: '10', docs: 'invoice:101:400.00,purchase_invoice:201:400.00' },
    })
    // Rozpad zápisu bez práva k deníku není.
    expect(wrapper.findAll('button').some(b => b.text().includes('accounting.saldo_settle.settle_selected'))).toBe(false)
  })

  it('(r) přečerpané konečné faktury zálohového účtu ukáže varování', async () => {
    const report = makeReport({
      accounts: [{
        account: { id: 3, code: '314', name: 'Poskytnuté zálohy', normal_side: 'debit' as any },
        gl_balance: 0, open_items_total: 0, open_items_count: 0, difference: 0, matches: true,
        partners_pagination: { page: 1, per_page: 2, total: 0, pages: 1 },
        partners: [],
        advance_deduction_issues: [{ doc_id: 77, doc_no: 'FA-77', deductible: 600, deducted: 1000, linked_share: 0, excess: 400 }],
      }],
    })
    serveSaldo(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const banner = wrapper.find('[data-testid="saldo-advance-issues"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain('FA-77')
    expect(banner.text()).toContain('accounting.saldo_settle.advance_issue_excess')
  })
})
