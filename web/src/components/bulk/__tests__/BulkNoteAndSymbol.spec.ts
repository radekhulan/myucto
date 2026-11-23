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
  useDimensions: () => ({ types: { value: [] }, load: () => Promise.resolve() }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

import BulkActionsDialog from '../BulkActionsDialog.vue'
import type { BulkJob, BulkOperation, BulkTarget } from '@/api/bulkActions'
import { buildDryRunRequest, bulkReasonText, emptyBulkForm, itemChanges, type BulkActionForm } from '../bulkActionsForm'
import { isValidVariableSymbol, isValidVsPattern } from '../noteSymbolForm'

const t = (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key)

const stubs = {
  Modal: { template: '<div><slot /><slot name="footer" /></div>' },
  ConfirmDialog: { emits: ['confirm', 'cancel'], template: '<div data-test="confirm"><button data-test="confirm-ok" @click="$emit(\'confirm\')">ok</button></div>' },
  ChartAccountSelect: true,
  DimensionPicker: true,
  PostingRuleSelect: true,
  PaginationBar: true,
}

function job(operation: BulkOperation, target: BulkTarget, patch: Partial<BulkJob> = {}): BulkJob {
  return {
    id: 7, kind: 'dry_run', status: 'completed', operation, target, selection_count: 2,
    total_items: 2, processed: 2, changed_count: 2, skipped_count: 0, failed_count: 0,
    current_step: null, last_error: null, created_at: null, finished_at: null, params: {},
    dry_run_job_id: null, rollback_of_job_id: null, applied_by_job_id: null, rolled_back_by_job_id: null,
    can_apply: true, can_rollback: false, rollback_supported: true, ...patch,
  }
}

function bankItem(id: number, patch: Record<string, unknown> = {}) {
  return {
    id, entry_id: null, source_type: 'bank', source_id: id, document_no: '111', entry_date: '2026-03-01',
    description: 'Odběratel', amount: 0, status: 'would_change', strategy: null, target_date: null, date_shifted: false,
    reason_code: null, message: null, result_entry_id: null, items_synced: 0,
    before: { variable_symbol: '111', match_status: 'unmatched' }, after: { variable_symbol: '2026001', match_status: 'auto_exact' },
    ...patch,
  }
}

const INVOICE_OPS = [
  { key: 'set_dimensions', available: true, reason: null, rollback: true },
  { key: 'set_note', available: true, reason: null, rollback: true },
  { key: 'delete', available: true, reason: null, rollback: false },
]
const BANK_OPS = [{ key: 'set_variable_symbol', available: true, reason: null, rollback: true }]

function mountDialog(target: BulkTarget) {
  return mount(BulkActionsDialog, {
    props: { target, selection: { ids: [1, 2] }, selectionCount: 2 },
    global: { stubs },
  })
}

function form(patch: Partial<BulkActionForm>): BulkActionForm {
  return { ...emptyBulkForm(), ...patch }
}

describe('hromadná poznámka a VS: formulář', () => {
  it('poznámka: text je povinný mimo vymazání, nejvýš 2000 znaků, include_issued jde vždy', () => {
    const note = (p: Partial<BulkActionForm['note']>) => form({ operation: 'set_note', note: { ...emptyBulkForm().note, ...p } })
    expect(buildDryRunRequest('invoice', { ids: [1] }, note({ text: '   ' }))).toBeNull()
    expect(buildDryRunRequest('invoice', { ids: [1] }, note({ text: 'x'.repeat(2001) }))).toBeNull()
    expect(buildDryRunRequest('purchase_invoice', { ids: [1] }, note({ field: 'note_above_items', mode: 'append', text: ' Děkujeme ' }))?.params)
      .toEqual({ note_field: 'note_above_items', note_mode: 'append', note_text: 'Děkujeme', include_issued: false })
    expect(buildDryRunRequest('invoice', { ids: [1] }, note({ mode: 'clear', includeIssued: true }))?.params)
      .toEqual({ note_field: 'note_below_items', note_mode: 'clear', include_issued: true })
  })

  it('VS: stálá hodnota jen 1 až 10 číslic', () => {
    expect(isValidVariableSymbol('0123456789')).toBe(true)
    for (const bad of ['', '12345678901', '12a', '-1', '1 2']) expect(isValidVariableSymbol(bad)).toBe(false)
    const vs = (value: string) => form({ operation: 'set_variable_symbol', symbol: { mode: 'constant', value, pattern: '' } })
    expect(buildDryRunRequest('bank_transaction', { ids: [1] }, vs('12a'))).toBeNull()
    expect(buildDryRunRequest('bank_transaction', { ids: [1] }, vs(' 2026001 '))?.params).toEqual({ vs_mode: 'constant', vs_value: '2026001' })
  })

  it('VS: vzor jen z číslic a značek, aspoň jedna značka, nejvýš 40 znaků', () => {
    expect(isValidVsPattern('{zprava}')).toBe(true)
    expect(isValidVsPattern('26{ss}{ks}')).toBe(true)
    for (const bad of ['', '2026', '{xx}', 'A{vs}', '{vs}-1', '1'.repeat(37) + '{vs}']) expect(isValidVsPattern(bad)).toBe(false)
    const vs = (pattern: string) => form({ operation: 'set_variable_symbol', symbol: { mode: 'pattern', value: '', pattern } })
    expect(buildDryRunRequest('bank_transaction', { ids: [1] }, vs('2026'))).toBeNull()
    expect(buildDryRunRequest('bank_transaction', { list_query: { scope: 'all' } }, vs('9{zprava}'))).toEqual({
      dry_run: true, operation: 'set_variable_symbol', target: 'bank_transaction', selection: { list_query: { scope: 'all' } },
      params: { vs_mode: 'pattern', vs_pattern: '9{zprava}' },
    })
  })

  it('před a po: poznámka (vystavený doklad zvlášť), VS a stav párování', () => {
    expect(itemChanges('set_note', { before: { note_below_items: null }, after: { note_below_items: 'Nový\ntext' }, strategy: 'issued' }, undefined, t))
      .toEqual([{ label: 'invoice.note_below (bulk_actions.note.issued_doc)', from: '—', to: 'Nový ↵ text' }])
    expect(itemChanges('set_variable_symbol', bankItem(1), undefined, t)).toEqual([
      { label: 'bulk_actions.vs.change_label', from: '111', to: '2026001' },
      { label: 'bulk_actions.vs.match_label', from: 'bank.match_status.unmatched', to: 'bank.match_status.auto_exact' },
    ])
  })

  it('důvody: vlastní text, selhané přepárování s detailem, kód úpravy faktury zprávou serveru', () => {
    expect(bulkReasonText({ reason_code: 'already_matched', message: 'x' }, t)).toBe('bulk_actions.ns_reason.already_matched')
    expect(bulkReasonText({ reason_code: 'rematch_failed', message: 'Výpis je zamčený' }, t)).toBe('bulk_actions.ns_reason.rematch_failed: Výpis je zamčený')
    expect(bulkReasonText({ reason_code: 'force_mode_required', message: 'Doklad je odeslaný' }, t)).toBe('Doklad je odeslaný')
    expect(bulkReasonText({ reason_code: 'changed_after_dry_run', message: null }, t)).toBe('bulk_actions.reason.changed_after_dry_run')
  })
})

describe('BulkActionsDialog: poznámka a VS', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
  })

  it('faktury nabídnou poznámku vedle ostatních operací a pošlou její parametry', async () => {
    m.status.mockResolvedValue({ can_run: true, limit: 500, operations: INVOICE_OPS, jobs: [] })
    const wrapper = mountDialog('invoice')
    await flushPromises()
    expect(wrapper.findAll('[data-test^="bulk-op-"]').map(w => w.attributes('data-test')).filter(a => a !== 'bulk-op-reason'))
      .toEqual(['bulk-op-set_dimensions', 'bulk-op-set_note', 'bulk-op-delete'])
    await wrapper.get('[data-test="bulk-op-set_note"] input').setValue(true)
    expect(wrapper.find('[data-test="bulk-params-note"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="bulk-dry-run"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="bulk-note-text"]').setValue('x'.repeat(2001))
    expect(wrapper.find('[data-test="bulk-note-error"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="bulk-dry-run"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="bulk-note-text"]').setValue('Splatnost 14 dní')
    await wrapper.get('[data-test="bulk-note-field-note_above_items"]').setValue(true)
    await wrapper.get('[data-test="bulk-note-include-issued"]').setValue(true)
    expect(wrapper.find('[data-test="bulk-note-error"]').exists()).toBe(false)
    m.start.mockResolvedValueOnce({ job_id: 7 })
    m.job.mockResolvedValue(job('set_note', 'invoice'))
    m.items.mockResolvedValue({ items: [], total: 0, counts: {} })
    await wrapper.get('[data-test="bulk-dry-run"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith({
      dry_run: true, operation: 'set_note', target: 'invoice', selection: { ids: [1, 2] },
      params: { note_field: 'note_above_items', note_mode: 'replace', note_text: 'Splatnost 14 dní', include_issued: true },
    })
  })

  it('bankovní pohyby mají jen VS; špatná hodnota i vzor zablokují běh, nápověda ukáže značky', async () => {
    m.status.mockResolvedValue({ can_run: true, limit: 500, operations: BANK_OPS, jobs: [] })
    const wrapper = mountDialog('bank_transaction')
    await flushPromises()
    expect(wrapper.findAll('[data-test^="bulk-op-set"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="bulk-params-vs"]').exists()).toBe(true)

    await wrapper.get('[data-test="bulk-vs-value"]').setValue('12a')
    expect(wrapper.find('[data-test="bulk-vs-error"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="bulk-dry-run"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="bulk-vs-mode-pattern"]').setValue(true)
    expect(wrapper.get('[data-test="bulk-vs-tokens"]').text()).toContain('{zprava}')
    await wrapper.get('[data-test="bulk-vs-pattern"]').setValue('20{x}')
    expect(wrapper.find('[data-test="bulk-vs-error"]').exists()).toBe(true)
    await wrapper.get('[data-test="bulk-vs-pattern"]').setValue('20{zprava}')
    expect(wrapper.find('[data-test="bulk-vs-error"]').exists()).toBe(false)

    m.start.mockResolvedValueOnce({ job_id: 7 })
    m.job.mockResolvedValue(job('set_variable_symbol', 'bank_transaction', { kind: 'apply', can_apply: false }))
    m.status.mockResolvedValue({ can_run: true, limit: 500, operations: BANK_OPS, jobs: [] })
    m.items.mockResolvedValue({
      items: [bankItem(31, { status: 'applied', reason_code: 'rematch_failed', message: 'Párování selhalo' })],
      total: 1, counts: { applied: 1 },
    })
    await wrapper.get('[data-test="bulk-dry-run"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith({
      dry_run: true, operation: 'set_variable_symbol', target: 'bank_transaction', selection: { ids: [1, 2] },
      params: { vs_mode: 'pattern', vs_pattern: '20{zprava}' },
    })
    const changes = wrapper.findAll('[data-test="bulk-item-change"]').map(w => w.text())
    expect(changes).toEqual([
      'bulk_actions.vs.change_label:111 → 2026001',
      'bulk_actions.vs.match_label:bank.match_status.unmatched → bank.match_status.auto_exact',
    ])
    expect(wrapper.get('[data-test="bulk-item-reason"]').text()).toBe('bulk_actions.ns_reason.rematch_failed: Párování selhalo')
  })
})
