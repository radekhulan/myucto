import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { BackfillReport } from '@/api/activation'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (date: string) => date,
  formatDateTime: (date: string) => date,
  formatMoney: (value: number, currency: string) => `${value} ${currency}`,
}))

import BackfillReportView from '../BackfillReportView.vue'

const routerLinkStub = { props: ['to'], template: '<a data-stub="router-link" :data-to="JSON.stringify(to)"><slot /></a>' }

function report(overrides: Partial<BackfillReport> = {}): BackfillReport {
  return {
    starts_on: '2026-01-01',
    kind: 'execute',
    phases: {
      documents: { invoice: { posted: 0, updated: 14, skipped: 93, failed: 1 }, purchase_invoice: { posted: 0, updated: 17, skipped: 91, failed: 0 } },
      account_settlements: { candidates: 1, posted: 0, failed: 1, errors: ['Zápočet #7: období je uzavřené'] },
    },
    skip_reasons: { date_locked: 184, already_posted: 32, ambiguous_supplier: 1, rules_disabled: 2 },
    document_issues: [
      { source_type: 'invoice', source_id: 11, document_no: '2601002', entry_date: '2026-01-01', severity: 'skipped', error_code: 'date_locked', message: 'zamčeno', had_posting: true },
      { source_type: 'purchase_invoice', source_id: 12, document_no: 'DOD-1', entry_date: '2026-02-01', severity: 'skipped', error_code: 'date_locked', message: 'zamčeno', had_posting: false },
      { source_type: 'invoice', source_id: 13, document_no: '2601003', entry_date: '2026-10-01', severity: 'failed', error_code: 'ddkp_reverse_charge_unsupported', message: 'DDKP s přenesenou povinností nejde zaúčtovat' },
      { source_type: 'cash', source_id: 14, document_no: 'PPD-1', entry_date: '2026-10-02', severity: 'failed', error_code: 'processing_error', message: 'Pokladna nemá účet' },
    ],
    bank_issues: [
      { tx_id: 501, statement_id: 40, posted_at: '2026-10-03', amount: -1210, currency: 'CZK', counterparty_name: 'Dodavatel', variable_symbol: '123', reason: 'ambiguous_supplier' },
      { tx_id: 502, statement_id: 40, posted_at: '2026-10-04', amount: 500, currency: 'CZK', reason: 'rules_disabled' },
    ],
    balance: { debit_cents: 100, credit_cents: 100, balanced: true },
    document_coverage: {
      invoice: { expected: 0, posted_or_updated: 14, skipped: 93, failed: 1, handled: 0, already_posted: 108, missing: 0, unexpected: 0, complete: true },
      purchase_invoice: { expected: 2, posted_or_updated: 17, skipped: 91, failed: 0, handled: 3, missing: 0, unexpected: 1, complete: false },
    },
    failed_total: 3,
    ...overrides,
  }
}

function render(value: BackfillReport) {
  return mount(BackfillReportView, { props: { report: value }, global: { stubs: { RouterLink: routerLinkStub } } })
}

describe('BackfillReportView', () => {
  it('lists the concrete items to fix with links and readable causes', () => {
    const failed = render(report()).get('[data-test="failed-items"]')

    expect(failed.text()).toContain('DDKP s přenesenou povinností nejde zaúčtovat')
    expect(failed.text()).toContain('Pokladna nemá účet')
    expect(failed.text()).toContain('Zápočet #7: období je uzavřené')
    expect(failed.text()).not.toContain('2601002')
    const links = failed.findAll('[data-stub="router-link"]')
    expect(links).toHaveLength(1)
    expect(JSON.parse(links[0].attributes('data-to')!)).toEqual({ name: 'invoice-detail', params: { id: 13 } })
  })

  it('names skipped bank transactions with a link to the statement and a specific reason', () => {
    const bank = render(report()).get('[data-test="bank-items"]')

    expect(bank.text()).toContain('activation.skip.ambiguous_supplier')
    expect(bank.text()).toContain('activation.skip.rules_disabled')
    expect(bank.text()).not.toContain('activation.skip.processing_error')
    const link = JSON.parse(bank.find('[data-stub="router-link"]').attributes('data-to')!)
    expect(link).toEqual({ name: 'bank-detail', params: { id: 40 }, query: { tx: '501', detail: '1' } })
  })

  it('shows locked-period skips as warnings that tell whether the entry stayed', () => {
    const skipped = render(report()).get('[data-test="skipped-items"]')

    expect(skipped.text()).toContain('activation.issue_kept_posting')
    expect(skipped.text()).toContain('activation.issue_left_unposted')
    expect(skipped.text()).toContain('activation.skip.date_locked')
  })

  it('shows extra documents and already posted ones in the coverage row', () => {
    const coverage = render(report()).get('[data-test="coverage"]')

    expect(coverage.classes()).toContain('bg-danger-50')
    expect(coverage.text()).toContain('"unexpected":1')
    expect(coverage.text()).toContain('activation.coverage_already_posted {"n":108}')
  })

  it('marks a forced completion with its reason', () => {
    const wrapper = render(report({ forced: { by: 1, at: '2026-11-04 10:00:00', reason: 'Opravíme po převodu', failed_total: 3 } }))

    expect(wrapper.get('[data-test="forced-banner"]').text()).toContain('Opravíme po převodu')
    expect(render(report()).find('[data-test="forced-banner"]').exists()).toBe(false)
  })
})
