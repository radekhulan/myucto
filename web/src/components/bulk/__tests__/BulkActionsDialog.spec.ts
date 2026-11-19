import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  status: vi.fn(),
  start: vi.fn(),
  job: vi.fn(),
  items: vi.fn(),
  rollback: vi.fn(),
  cancel: vi.fn(),
}))

vi.mock('@/api/bulkActions', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/bulkActions')>(),
  bulkActionsApi: { status: m.status, start: m.start, job: m.job, items: m.items, rollback: m.rollback, cancel: m.cancel },
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: vi.fn().mockResolvedValue([]), listPostingRules: vi.fn().mockResolvedValue({}) },
}))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    types: { value: [{ id: 1, name: 'Středisko', is_active: true }, { id: 2, name: 'Zakázka', is_active: true }] },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

import BulkActionsDialog from '../BulkActionsDialog.vue'
import type { BulkJob, BulkOperation } from '@/api/bulkActions'

const stubs = {
  Modal: { template: '<div><slot /><slot name="footer" /></div>' },
  ConfirmDialog: { emits: ['confirm', 'cancel'], template: '<div data-test="confirm"><button data-test="confirm-ok" @click="$emit(\'confirm\')">ok</button></div>' },
  ChartAccountSelect: true,
  DimensionPicker: true,
  PostingRuleSelect: true,
  PaginationBar: true,
}

function job(operation: BulkOperation, patch: Partial<BulkJob> = {}): BulkJob {
  return {
    id: 7, kind: 'dry_run', status: 'completed', operation, target: 'invoice', selection_count: 2,
    total_items: 2, processed: 2, changed_count: 2, skipped_count: 0, failed_count: 0,
    current_step: null, last_error: null, created_at: null, finished_at: null, params: {},
    dry_run_job_id: null, rollback_of_job_id: null, applied_by_job_id: null, rolled_back_by_job_id: null,
    can_apply: true, can_rollback: false, rollback_supported: operation !== 'delete', ...patch,
  }
}

function item(id: number) {
  return {
    id, entry_id: null, source_type: 'invoice', source_id: id, document_no: `FV${id}`, entry_date: '2026-03-01',
    description: null, amount: 100, status: 'would_change', strategy: null, target_date: null, date_shifted: false,
    reason_code: null, message: null, result_entry_id: null, items_synced: 0,
    before: { status: 'issued', posted: false }, after: { action: 'delete' },
  }
}

const OPERATIONS = [
  { key: 'rebook_account', available: false, reason: 'Chybí právo přeúčtovat', rollback: true },
  { key: 'set_dimensions', available: true, reason: null, rollback: true },
  { key: 'delete', available: true, reason: null, rollback: false },
]

function mountDialog() {
  return mount(BulkActionsDialog, {
    props: { target: 'invoice', selection: { ids: [1, 2] }, selectionCount: 2 },
    global: { stubs },
  })
}

async function runDryRun(wrapper: ReturnType<typeof mountDialog>, operation: BulkOperation) {
  m.start.mockResolvedValueOnce({ job_id: 7 })
  m.job.mockResolvedValue(job(operation))
  m.status.mockResolvedValue({ can_run: true, limit: 500, operations: OPERATIONS, jobs: [job(operation)] })
  await wrapper.get('[data-test="bulk-dry-run"]').trigger('click')
  await flushPromises()
}

describe('BulkActionsDialog', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
    m.status.mockResolvedValue({ can_run: true, limit: 500, operations: OPERATIONS, jobs: [] })
    m.items.mockResolvedValue({ items: [item(101), item(102)], total: 2, counts: { would_change: 2 } })
  })

  it('nabídne jen dostupné operace s důvodem, nanečisto pošle dimenze a ostře vyřazené položky', async () => {
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="bulk-op-rebook_account"] input').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="bulk-op-rebook_account"] [data-test="bulk-op-reason"]').text()).toContain('Chybí právo přeúčtovat')
    expect(wrapper.find('[data-test="bulk-params-dimensions"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="bulk-dimension-scope"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="bulk-account-prefixes"]').exists()).toBe(false)

    await wrapper.findAll('[data-test="bulk-dimension-mode"]')[1]!.setValue('clear')
    await runDryRun(wrapper, 'set_dimensions')

    expect(m.start).toHaveBeenCalledWith({
      dry_run: true, operation: 'set_dimensions', target: 'invoice', selection: { ids: [1, 2] },
      params: { dimensions: [{ type_id: 2, mode: 'clear' }], scope: 'document' },
    })
    expect(wrapper.findAll('[data-test="bulk-item"]')).toHaveLength(2)

    await wrapper.findAll('[data-test="bulk-item-include"]')[1]!.trigger('change')
    m.start.mockResolvedValueOnce({ job_id: 8 })
    await wrapper.get('[data-test="bulk-apply"]').trigger('click')
    await wrapper.get('[data-test="confirm-ok"]').trigger('click')
    await flushPromises()

    expect(m.start).toHaveBeenLastCalledWith({ dry_run: false, dry_run_job_id: 7, excluded_item_ids: [102] })
  })

  it('smazání jde provést až po opsání počtu a ten se pošle jako confirm_count', async () => {
    const wrapper = mountDialog()
    await flushPromises()
    await wrapper.get('[data-test="bulk-op-delete"] input').setValue(true)
    expect(wrapper.find('[data-test="bulk-params-delete"]').text()).toContain('bulk_actions.delete_warning')
    await runDryRun(wrapper, 'delete')

    expect(m.start).toHaveBeenCalledWith(expect.objectContaining({ operation: 'delete', params: {} }))
    const apply = wrapper.get('[data-test="bulk-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="bulk-delete-confirm"]').setValue('1')
    expect(wrapper.get('[data-test="bulk-apply"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="bulk-delete-confirm"]').setValue('2')
    expect(wrapper.get('[data-test="bulk-apply"]').attributes('disabled')).toBeUndefined()

    m.start.mockResolvedValueOnce({ job_id: 8 })
    await wrapper.get('[data-test="bulk-apply"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenLastCalledWith({ dry_run: false, dry_run_job_id: 7, excluded_item_ids: [], confirm_count: 2 })
  })

  it('vrácení z historie jde přes potvrzení', async () => {
    m.status.mockResolvedValue({
      can_run: true, limit: 500, operations: OPERATIONS,
      jobs: [job('set_dimensions', { id: 9, kind: 'apply', can_apply: false, can_rollback: true })],
    })
    m.rollback.mockResolvedValue({ job_id: 10 })
    m.job.mockResolvedValue(job('set_dimensions', { id: 10, kind: 'rollback', can_apply: false }))
    const wrapper = mountDialog()
    await flushPromises()
    await wrapper.get('[data-test="bulk-rollback"]').trigger('click')
    await wrapper.get('[data-test="confirm-ok"]').trigger('click')
    await flushPromises()
    expect(m.rollback).toHaveBeenCalledWith(9)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('výběr nad limit nejde spustit nanečisto', async () => {
    const wrapper = mount(BulkActionsDialog, {
      props: { target: 'journal_entry', selection: { list_query: { q: 'x' } }, selectionCount: 900 },
      global: { stubs },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="bulk-over-limit"]').exists()).toBe(true)
    await wrapper.findAll('[data-test="bulk-dimension-mode"]')[0]!.setValue('clear')
    expect(wrapper.find('[data-test="bulk-account-prefixes"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="bulk-dimension-scope"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="bulk-dry-run"]').attributes('disabled')).toBeDefined()
  })
})
