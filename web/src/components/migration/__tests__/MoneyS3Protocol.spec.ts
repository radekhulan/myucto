import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import type { MoneyS3Run } from '@/api/moneyS3'
import type { PohodaRun } from '@/api/pohoda'

function run(overrides: Partial<NonNullable<MoneyS3Run['protocol']>> = {}, status: MoneyS3Run['status'] = 'completed'): MoneyS3Run {
  return {
    id: 7,
    job_id: 12,
    mode: 'import',
    status,
    agenda_ico: '12345679',
    agenda_name: 'Vzorová účetní s.r.o.',
    money_version: '26.600',
    created_at: '2026-09-10 10:00:00',
    finished_at: '2026-09-10 10:01:00',
    protocol: {
      mode: 'import',
      status,
      failure: null,
      steps: [
        { key: 'journal', status: 'ok', counts: { entries: 13 }, messages: [] },
        { key: 'link', status: 'warning', counts: { orphans: 1 }, messages: [{ level: 'warning', code: 'orphan_documents', text: '1 dokladů nemá zápis.', context: {} }] },
      ],
      reconciliation: [
        {
          year: 2024,
          period_id: 1,
          ok: true,
          checks: [{ key: 'money_journal', ok: true }],
          journal_diffs: [],
          money_report: null,
          documents: [{ key: 'bank', documents: 12150, journal: 12150, ok: true }],
        },
      ],
      closing: [{ year: 2024, status: 'closed' }, { year: 2025, status: 'open' }],
      orphans: [{ type: 'purchase_invoice', year: 2025, document_no: 'FP25099', id: 3 }],
      automation: { during: 'off', restored: true, after: 'full' },
      ...overrides,
    },
  }
}

describe('MoneyS3Protocol', () => {
  it('u daňové evidence ukáže peněžní deník MyÚčta proti Money po letech', () => {
    const wrapper = mount(MoneyS3Protocol, {
      props: {
        run: run({
          reconciliation: [],
          tax_records_reconciliation: [
            { year: 2024, ok: true, money: { income_taxable: 10000, expense_taxable: 900 }, myucto: { income_taxable: 10000, expense_taxable: 900, unclassified: 0 }, differences: 0, missing: 0, extra: 0, non_cash: 0 },
            { year: 2025, ok: false, money: { income_taxable: 500, expense_taxable: 3000 }, myucto: { income_taxable: 500, expense_taxable: 3420, unclassified: 0.5 }, differences: 1, missing: 0, extra: 0, non_cash: 0 },
          ],
        }),
      },
    })
    const table = wrapper.find('[data-testid="tax-records-reconciliation"]')
    expect(table.exists()).toBe(true)
    expect(table.text()).toContain('money_s3.protocol.tax_records_title')
    expect(wrapper.find('[data-testid="tax-records-2024"]').text()).toContain('money_s3.protocol.ok')
    const year2025 = wrapper.find('[data-testid="tax-records-2025"]').text()
    expect(year2025).toContain('money_s3.protocol.not_ok')
    expect(year2025.replace(/\s/g, '')).toContain('3420,00/3000,00')
    expect(wrapper.find('[data-testid="reconciliation-2024"]').exists()).toBe(false)
  })

  it('ukáže kroky, zprávy, rekonciliaci, uzávěrku a osiřelé doklady', () => {
    const wrapper = mount(MoneyS3Protocol, { props: { run: run() } })
    const text = wrapper.text()

    expect(text).toContain('money_s3.steps.journal')
    expect(text).toContain('1 dokladů nemá zápis.')
    expect(wrapper.find('[data-testid="reconciliation-2024"]').text()).toContain('money_s3.protocol.ok')
    expect(text).toContain('money_s3.closing_status.closed')
    expect(text).toContain('FP25099')
    expect(text).toContain('money_s3.protocol.automation_after')
  })

  it('rozdíl po účtech vypíše i s hodnotami MyÚčta a Money', () => {
    const wrapper = mount(MoneyS3Protocol, {
      props: {
        run: run({
          reconciliation: [{
            year: 2024,
            period_id: 1,
            ok: false,
            checks: [{ key: 'money_report', ok: false }],
            journal_diffs: [],
            money_report: { accounts: 12, skipped_lines: 0, diffs: [{ account: '518', myucto: [0, 10300, 10300], money: [0, 10301, 10301] }] },
            documents: [],
          }],
        }, 'failed'),
      },
    })
    const block = wrapper.find('[data-testid="reconciliation-2024"]')

    expect(block.text()).toContain('money_s3.protocol.not_ok')
    expect(block.text()).toContain('518')
    expect(block.text()).toMatch(/10\s?301,00/)
  })

  it('u neúspěšného převodu upozorní, že automatika zůstává vypnutá', () => {
    const wrapper = mount(MoneyS3Protocol, {
      props: { run: run({ failure: 'journal', automation: { during: 'off', restored: false, after: null } }, 'failed') },
    })

    expect(wrapper.text()).toContain('money_s3.protocol.failure')
    expect(wrapper.text()).toContain('money_s3.protocol.automation_left_off')
  })

  it('s prefixem pohoda bere texty z jmenného prostoru POHODY', () => {
    const pohodaRun: PohodaRun = {
      id: 3,
      job_id: 4,
      mode: 'dry_run',
      status: 'completed_with_warnings',
      agenda_ico: '12345678',
      agenda_year: 2026,
      pohoda_version: null,
      created_at: '2026-09-10 10:00:00',
      finished_at: '2026-09-10 10:05:00',
      protocol: {
        mode: 'dry_run',
        status: 'completed_with_warnings',
        failure: null,
        steps: [{ key: 'internal_tax_documents', status: 'ok', counts: { tax_documents_sale: 2 }, messages: [] }],
        reconciliation: [{
          year: 2026,
          period_id: 1,
          ok: false,
          checks: [{ key: 'pohoda_journal', ok: false }],
          journal_diffs: [{ account: '311', myucto: [0, 100, 100], money: [0, 120, 120] }],
          documents: [{ key: 'bank', documents: 50, journal: 50, ok: true, other_accounts: 2 }],
          unmapped_accounts: [{ account: '395100', name: 'Vnitřní zúčtování', balance: 10 }],
        }],
        orphans: [{ type: 'invoice', document_no: 'FV-0001', id: 8 }],
      },
    }
    const wrapper = mount(MoneyS3Protocol, { props: { run: pohodaRun, prefix: 'pohoda' } })
    const text = wrapper.text()

    expect(text).toContain('pohoda.steps.internal_tax_documents')
    expect(text).toContain('pohoda.protocol.col_money')
    expect(text).toContain('12345678 · 2026')
    expect(text).toContain('FV-0001')
    expect(text).not.toContain('undefined')
    expect(text).not.toContain('money_s3.')
    expect(wrapper.find('[data-testid="unmapped-accounts"]').text()).toContain('395100')
  })

  it('mzdové kontrolní úhrny vypíše po měsících a nesedící daň vyznačí', () => {
    const month = { gross: 30000, employee_social: 2130, employee_health: 1350, employer_social: 7440, employer_health: 2700,
      advance_tax: 3810, withholding_tax: 0, deductions: 0, net_payable: 22710, dpfo_refunds: 0 }
    const wrapper = mount(MoneyS3Protocol, {
      props: {
        run: run({
          payroll_totals: [
            { period: '2025-01', ...month, dpfo: 3810, dpfo_remitted: 3810, dpfo_net: 3810, tax_ok: true },
            { period: '2025-02', ...month, dpfo: 3000, dpfo_remitted: 3000, dpfo_net: 3000, tax_ok: false },
            { period: '2025-03', ...month, dpfo: null, dpfo_refunds: null, dpfo_remitted: null, dpfo_net: null, tax_ok: null },
          ],
        }),
      },
    })
    const block = wrapper.find('[data-testid="payroll-totals"]')

    expect(block.text()).toContain('money_s3.protocol.payroll_totals_title')
    expect(block.findAll('tbody tr')).toHaveLength(3)
    expect(block.text()).toMatch(/30\s?000,00/)
    expect(block.findAll('tbody tr')[1].find('.bg-danger-50').exists()).toBe(true)
    expect(block.text()).toContain('money_s3.protocol.tax_missing')
  })

  it('bez mzdových úhrnů sekci nezobrazí', () => {
    const wrapper = mount(MoneyS3Protocol, { props: { run: run() } })
    expect(wrapper.find('[data-testid="payroll-totals"]').exists()).toBe(false)
  })

  it('rozdíl, který je už ve zdrojovém programu, vypíše po dokladech', () => {
    const wrapper = mount(MoneyS3Protocol, {
      props: {
        run: run({
          reconciliation: [{
            year: 2024,
            period_id: 1,
            ok: true,
            checks: [{ key: 'documents_issued_invoices', ok: true }],
            journal_diffs: [],
            money_report: null,
            documents: [{ key: 'issued_invoices', documents: 2420, journal: 3630, ok: true, source_differences: [{ document_no: 'FV-0003', difference: -1210, reason: 'advance_deduction' }] }],
          }],
        }),
      },
    })
    const note = wrapper.find('[data-testid="source-differences"]')

    expect(note.text()).toContain('money_s3.protocol.source_differences')
    expect(note.text()).toContain('FV-0003')
    expect(note.text()).toMatch(/1\s?210,00/)
  })
})
