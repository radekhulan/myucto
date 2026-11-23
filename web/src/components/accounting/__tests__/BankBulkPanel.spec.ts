import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  status: vi.fn(), count: vi.fn(), start: vi.fn(), job: vi.fn(), items: vi.fn(), rollback: vi.fn(), cancel: vi.fn(),
  download: vi.fn(),
}))

vi.mock('@/api/bankBulk', () => ({
  BANK_BULK_PER_PAGE: 50,
  bankBulkApi: { status: m.status, count: m.count, start: m.start, job: m.job, items: m.items, rollback: m.rollback, cancel: m.cancel },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: () => Promise.resolve([]) } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { get: vi.fn() } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { get: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'err' }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string) => d, formatMoney: (n: number) => String(n) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: unknown) => (p ? `${key}${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/utils/bulkJobCsv', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/utils/bulkJobCsv')>()),
  downloadCsv: m.download,
}))

import BankBulkPanel from '../BankBulkPanel.vue'

const stubs = {
  ActionBar: {
    props: ['actions'],
    template: `<div><template v-for="a in actions" :key="a.key"><button v-if="a.show" :data-test="'act-' + a.key"
      :disabled="a.disabled" @click="a.run()">{{ a.label }}</button></template></div>`,
  },
  ConfirmDialog: { props: ['message'], emits: ['confirm'], template: '<button data-test="confirm" @click="$emit(\'confirm\')">{{ message }}</button>' },
  DateInput: true,
  ChartAccountSelect: true,
  DocumentLinkPicker: true,
  PaginationBar: true,
}

function job(patch: Record<string, unknown> = {}) {
  return {
    id: 7, kind: 'dry_run', status: 'completed', total_items: 3, processed: 3, changed_count: 2, skipped_count: 1,
    failed_count: 0, current_step: null, last_error: null, created_at: '2026-11-01T10:00:00Z', finished_at: null,
    params: { action: 'post', date_from: '2026-01-01', date_to: '2026-12-31', counter_account: '331000' },
    dry_run_job_id: null, rollback_of_job_id: null, rolled_back_by_job_id: null, applied_by_job_id: null,
    can_apply: true, can_rollback: false, ...patch,
  }
}

function item(id: number, sourceId: number) {
  return {
    id, entry_id: null, source_type: 'bank', source_id: sourceId, document_no: String(100 + id), entry_date: '2026-03-01',
    description: 'Novák · mzda', amount: -1000, status: 'would_change', reason_code: null, message: null,
    after: [{ account_code: '331000', side: 'debit', amount: 1000 }], result_entry_id: null,
  }
}

async function mountWith(status: Record<string, unknown>) {
  m.status.mockResolvedValue({ can_run: true, can_match: false, limit: 500, match_limit: 100, accounts: [], jobs: [], ...status })
  const wrapper = mount(BankBulkPanel, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('BankBulkPanel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('bez práva párovat nenabídne párování a bez protiúčtu nepustí nanečisto', async () => {
    const wrapper = await mountWith({})
    expect(wrapper.find('[data-test="bank-bulk-action-match"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="act-dry"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="act-apply"]').text()).toBe('accounting.bank_bulk.action_apply_post')
  })

  it('provedení pošle id ručně vyřazených pohybů', async () => {
    m.items.mockResolvedValue({ items: [item(1, 501), item(2, 502)], total: 2, counts: { would_change: 2, skipped: 1 } })
    m.start.mockResolvedValue({ job_id: 8, status: 'queued' })
    m.job.mockReturnValue(new Promise(() => {}))
    const wrapper = await mountWith({ jobs: [job()] })

    const boxes = wrapper.get('[data-test="bank-bulk-result"] table').findAll('tbody input[type="checkbox"]')
    expect(boxes).toHaveLength(2)
    await boxes[1].trigger('change')
    expect(wrapper.get('[data-test="bank-bulk-excluded"]').text()).toContain('"n":1')

    await wrapper.get('[data-test="act-apply"]').trigger('click')
    expect(wrapper.get('[data-test="confirm"]').text()).toContain('"n":1')
    await wrapper.get('[data-test="confirm"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith({ dry_run: false, dry_run_job_id: 7, excluded_ids: [502] })
  })

  it('export CSV stáhne všechny stránky položek bez filtru stavu', async () => {
    m.items.mockImplementation((_id: number, status: string | null, page: number) => Promise.resolve(status === null
      ? { items: page === 1 ? [item(1, 501)] : [item(2, 502)], total: 2, counts: {} }
      : { items: [item(1, 501)], total: 1, counts: { would_change: 1 } }))
    const wrapper = await mountWith({ jobs: [job()] })

    await wrapper.get('[data-test="bank-bulk-export"]').trigger('click')
    await flushPromises()
    expect(m.items).toHaveBeenCalledWith(7, null, 1, 200)
    expect(m.items).toHaveBeenCalledWith(7, null, 2, 200)
    expect(m.download).toHaveBeenCalledTimes(1)
    const [name, content] = m.download.mock.calls[0]
    expect(name).toBe('bankovni-pohyby-7.csv')
    expect(content.startsWith('﻿accounting.bank_bulk.col_vs;')).toBe(true)
    expect(content.split('\r\n').filter(Boolean)).toHaveLength(3)
  })
})
