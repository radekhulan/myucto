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
})
