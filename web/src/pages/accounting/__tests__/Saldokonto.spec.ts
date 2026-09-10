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
  ICONS: { download: 'M0 0', doc: 'M0 0', user: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
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
                currency_code: 'CZK', amount_foreign: 0, booked_czk: 1000, paid_czk: 0, remaining_czk: 1000, days_overdue: 20 },
            ],
          },
          {
            partner_id: 11,
            partner_name: 'Beta a.s.',
            total_remaining: 500,
            items: [
              { doc_type: 'invoice', doc_id: 102, doc_no: 'F-102', issue_date: '2099-05-01', due_date: '2099-05-15',
                currency_code: 'CZK', amount_foreign: 0, booked_czk: 500, paid_czk: 0, remaining_czk: 500, days_overdue: 0 },
            ],
          },
        ],
      },
    ],
    ...overrides,
  }
}

describe('Saldokonto.vue', () => {
  beforeEach(() => {
    m.listPeriods.mockReset()
    m.getSaldo.mockReset()
    m.exportReport.mockReset()
    m.toastError.mockReset()
    m.listPeriods.mockResolvedValue([
      { id: 6, supplier_id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open', closed_at: null, created_at: '' },
    ])
  })

  it('(a) výchozí pohled je plochý seznam, seřazený dle splatnosti vzestupně napříč partnery', async () => {
    m.getSaldo.mockResolvedValue(makeReport())
    const wrapper = mount(Saldokonto)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    // Alfa (splatnost 24.1.) musí být před Beta (15.5.).
    expect(rows[0].text()).toContain('F-101')
    expect(rows[0].text()).toContain('Alfa s.r.o.')
    expect(rows[1].text()).toContain('F-102')
  })

  it('(b) přepnutí na "podle partnera" vykreslí seskupenou tabulku se dvěma partnery', async () => {
    m.getSaldo.mockResolvedValue(makeReport())
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
    m.getSaldo.mockResolvedValue(makeReport())
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
    m.getSaldo.mockResolvedValue(makeReport())
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
    m.getSaldo.mockResolvedValue(report)
    const wrapper = mount(Saldokonto)
    await flushPromises()

    expect(wrapper.text()).toContain('accounting.saldo.period_mismatch_hint')
    const switchBtn = wrapper.findAll('button').find(b => b.text().includes('period_mismatch_switch'))
    expect(switchBtn).toBeTruthy()
  })

  it('(f) konfrontace se zůstatkem hlavní knihy je i v plochém pohledu', async () => {
    m.getSaldo.mockResolvedValue(makeReport({
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
    m.getSaldo.mockResolvedValue(makeReport())
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
    m.getSaldo.mockResolvedValue(makeReport({
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
    m.getSaldo.mockResolvedValue(makeReport())
    mount(Saldokonto)
    await flushPromises()

    expect(m.getSaldo).toHaveBeenCalledWith({ period_id: 6, as_of: undefined, account: 'all' })
    routeQuery.value = {}
  })
})
