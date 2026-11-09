import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value),
  formatDate: (value: string) => value,
  formatDateTime: (value: string) => value,
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))

const line = (no: number, code: string, side: 'debit' | 'credit', amount: number, pair = 1) =>
  ({ account_code: code, account_name: `N${code}`, side, amount, cost_center: null, line_no: no, pair_no: pair })

const history = {
  entry_id: 1,
  activity: [],
  versions: [
    {
      version: 1, is_current: false, valid_from: '2026-11-01 10:00:00', valid_to: '2026-11-02 10:00:00',
      header: {}, header_changes: null, line_changes: null,
      changed_by: { action: 'create', user_id: 1, user_name: 'Autor', created_at: '' },
      lines: [line(0, '501', 'debit', 100), line(1, '321', 'credit', 100)],
    },
    {
      version: 2, is_current: true, valid_from: '2026-11-02 10:00:00', valid_to: null,
      header: {}, header_changes: {},
      line_changes: [{ type: 'changed', line_no: 0, before: line(0, '501', 'debit', 100), after: line(0, '518', 'debit', 100) }],
      changed_by: { action: 'repost', user_id: 2, user_name: 'Účetní', created_at: '' },
      lines: [line(0, '518', 'debit', 100), line(1, '321', 'credit', 100)],
    },
  ],
}

vi.mock('@/api/accounting', () => ({ accountingApi: { getJournalHistory: vi.fn(async () => history) } }))

import JournalEntryHistory from '../JournalEntryHistory.vue'

describe('JournalEntryHistory', () => {
  it('každá verze ukáže celý zápis a rozdíl zvýrazní s původním účtem', async () => {
    const w = mount(JournalEntryHistory, { props: { entryId: 1 } })
    await w.find('button').trigger('click')
    await flushPromises()

    const tables = w.findAll('[data-testid="history-lines"]')
    expect(tables).toHaveLength(2)
    expect(tables[0].findAll('tbody tr')).toHaveLength(2)
    expect(tables[0].text()).toContain('501')
    expect(tables[0].text()).toContain('321')
    expect(w.text()).toContain('accounting.journal.history.created')

    const rows = tables[1].findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].attributes('data-state')).toBe('changed')
    expect(rows[0].text()).toContain('518')
    expect(rows[0].text()).toContain('501')
    expect(rows[1].attributes('data-state')).toBe('same')
    expect(w.text()).toContain('Autor')
    expect(w.text()).toContain('Účetní')
    w.unmount()
  })
})
