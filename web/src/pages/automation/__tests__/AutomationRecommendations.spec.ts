import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(), queue: vi.fn(), getJob: vi.fn(), startJob: vi.fn(), push: vi.fn(), refresh: vi.fn(), clearPermissions: vi.fn(), canWrite: vi.fn(),
  supplier: { currentSupplierId: 1, setSupplier: vi.fn() },
}))
vi.mock('@/api/automationRecommendations', () => ({ automationRecommendationsApi: { list: mocks.list, refresh: mocks.queue, getJob: mocks.getJob, startJob: mocks.startJob } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: mocks.push }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => mocks.supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ refresh: mocks.refresh, clearPermissions: mocks.clearPermissions, canWrite: mocks.canWrite }) }))
import AutomationRecommendations from '../AutomationRecommendations.vue'

const row = {
  id: 'purchase:1:1', type: 'classify_purchase', supplier_id: 2, supplier_name: 'Synthetic company',
  document_id: 1, item_id: 1, statement_id: null, transaction_id: null, date: '2099-01-01',
  document_no: 'TEST-1', description: 'Synthetic service', counterparty: 'Synthetic vendor',
  amount: 100, currency: 'CZK', booked: true, period_closed: false, current_account_code: '518',
  suggested_account_code: '518.100', expense_kind: 'service', confidence: 0.95, reason: 'Repeated service', source: 'catalog', lines: [],
  action: 'create_expense_rule', vendor_id: 12, occurrence_count: 3,
  rule_payload: { name: 'Synthetic service', vendor_client_id: 12, description_contains: 'hosting', expense_kind: 'service', target_account_code: '518.100', application_mode: 'suggest' },
  samples: [{ description: 'Synthetic evidence', document_id: 1, date: '2099-01-01' }],
}
const response = { items: [row], total: 1, page: 1, per_page: 30, summary: { sales: 0, purchases: 1, bank: 0 }, snapshots: [{ supplier_id: 2, generated_at: '2099-01-01 12:00:00' as string | null, refresh_pending: false }] }
enableAutoUnmount(afterEach)
afterEach(() => { vi.useRealTimers() })
function render(suppliers = [2]) {
  return mount(AutomationRecommendations, { props: { suppliers }, global: { stubs: { PaginationBar: true, ExpenseRules: true, RuleFormModal: true, PostingPreviewModal: true, Teleport: true } } })
}

describe('automation recommendations', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    mocks.supplier.currentSupplierId = 2
    mocks.supplier.setSupplier.mockImplementation((id: number) => { mocks.supplier.currentSupplierId = id })
    mocks.list.mockResolvedValue(response)
    mocks.refresh.mockResolvedValue(true)
    mocks.queue.mockResolvedValue({ queued: 1 })
    mocks.startJob.mockResolvedValue({ id: 1, status: 'queued', total_items: null, processed: 0, current_step: 'queued', created_count: 0, last_error: null, created_at: '', finished_at: null })
    mocks.getJob.mockResolvedValueOnce(null).mockResolvedValue({ id: 1, status: 'running', total_items: null, processed: 1, current_step: 'invoices', created_count: 0, last_error: null, created_at: '', finished_at: null })
    mocks.canWrite.mockReturnValue(true)
  })

  it('shows grouped evidence and a concrete rule action without changing documents', async () => {
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('518.100')
    expect(wrapper.text()).toContain('Repeated service')
    expect(wrapper.text()).toContain('Synthetic evidence')
    expect(wrapper.text()).toContain('hosting')
    expect(wrapper.text()).toContain('automation.recommendations.action_create_expense_rule')
    expect(wrapper.text()).toContain('automation.recommendations.new_expense_rule')
    expect(wrapper.text()).toContain('automation.recommendations.match_text')
    expect(wrapper.text()).not.toContain('automation.recommendations.existing_match_text')
    expect(wrapper.text()).not.toContain('automation.recommendations.assistant')
    expect(mocks.list).toHaveBeenCalledWith(expect.objectContaining({ suppliers: '2', page: 1 }))
    expect(mocks.push).not.toHaveBeenCalled()
    expect(mocks.refresh).not.toHaveBeenCalled()
  })

  it('starts an on-demand job for one company and restores its progress endpoint', async () => {
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.refresh')!.trigger('click')
    await flushPromises()
    expect(mocks.startJob).toHaveBeenCalledWith(2)
    expect(mocks.getJob).toHaveBeenCalledWith(2)
    expect(wrapper.findComponent({ name: 'ImportJobProgress' }).exists()).toBe(true)
  })

  it('opens the source in the current company without changing the global context', async () => {
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.open')!.trigger('click')
    await flushPromises()
    expect(mocks.supplier.setSupplier).not.toHaveBeenCalled()
    expect(mocks.refresh).not.toHaveBeenCalled()
    expect(mocks.push).toHaveBeenCalledWith({ name: 'purchase-invoice-detail', params: { id: 1 } })
  })

  it('refuses stale company actions instead of switching the current company', async () => {
    mocks.supplier.currentSupplierId = 1
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.open')!.trigger('click')
    await flushPromises()
    expect(mocks.push).not.toHaveBeenCalled()
    expect(mocks.supplier.setSupplier).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('automation.recommendations.action_create_expense_rule')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('opens the original prefilled expense form and resolves only after a successful save, even during refresh', async () => {
    mocks.list.mockResolvedValue({ ...response, snapshots: [{ ...response.snapshots[0], refresh_pending: true }] })
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.action_create_expense_rule')!.trigger('click')
    const form = wrapper.findComponent({ name: 'ExpenseRules' })
    expect(form.props('initialDraft')).toEqual({ vendorId: 12, vendorName: 'Synthetic vendor', rule: row.rule_payload })
    expect(mocks.queue).not.toHaveBeenCalled()
    expect(mocks.push).not.toHaveBeenCalled()
    form.vm.$emit('saved')
    await flushPromises()
    expect(mocks.startJob).toHaveBeenCalledWith(2)
    expect(wrapper.findAll('article')).toHaveLength(0)
    expect(wrapper.text()).toContain('automation.recommendations.rule_created')
  })

  it('opens the original bank rule form with matching criteria, range and accounts', async () => {
    const rule = { name: 'Synthetic bank rule', direction: 'outgoing', message_contains: 'hosting', amount_min: 10, amount_max: 100, priority: 100, applies_currency: 'CZK', debit_account_code: '518.100', credit_account_code: '221.100', mode: 'suggest' }
    mocks.list.mockResolvedValue({ ...response, items: [{ ...row, type: 'bank_rule', action: 'create_bank_rule', rule_payload: rule }] })
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.action_create_bank_rule')!.trigger('click')
    expect(wrapper.findComponent({ name: 'RuleFormModal' }).props('prefill')).toEqual(rule)
    expect(mocks.queue).not.toHaveBeenCalled()
    wrapper.findComponent({ name: 'RuleFormModal' }).vm.$emit('close')
    await flushPromises()
    expect(wrapper.findAll('article')).toHaveLength(1)
  })

  it('opens an existing suggested expense rule for editing rather than creating a duplicate', async () => {
    mocks.list.mockResolvedValue({ ...response, items: [{ ...row, action: 'edit_expense_rule', rule_id: 42, reason: 'review_expense_rule' }] })
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('automation.recommendations.existing_expense_rule')
    expect(wrapper.text()).toContain('automation.recommendations.existing_match_text')
    expect(wrapper.text()).toContain('automation.recommendations.existing_account')
    expect(wrapper.text()).toContain('automation.recommendations.existing_evidence')
    expect(wrapper.text()).toContain('automation.recommendations.existing_occurrences')
    expect(wrapper.text()).not.toContain('automation.recommendations.match_text')
    expect(wrapper.text()).not.toContain('automation.recommendations.account')
    expect(wrapper.text()).not.toContain('automation.recommendations.evidence')
    expect(wrapper.text()).not.toContain('automation.recommendations.action_create_expense_rule')
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.action_edit_expense_rule')!.trigger('click')
    expect(wrapper.findComponent({ name: 'ExpenseRules' }).props('initialDraft')).toMatchObject({ ruleId: 42, rule: row.rule_payload })
    expect(mocks.push).not.toHaveBeenCalled()
    wrapper.findComponent({ name: 'ExpenseRules' }).vm.$emit('saved')
    await flushPromises()
    expect(wrapper.text()).toContain('automation.recommendations.rule_updated')
    expect(mocks.queue).toHaveBeenCalledWith('2')
  })

  it('opens an exact live posting preview and waits for confirmation', async () => {
    mocks.list.mockResolvedValue({ ...response, items: [{ ...row, type: 'post_invoice', action: 'post_document', rule_payload: undefined, booked: false }] })
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'automation.recommendations.action_post_document')!.trigger('click')
    const modal = wrapper.findComponent({ name: 'PostingPreviewModal' })
    expect(modal.props()).toMatchObject({ source: 'invoices', docId: 1, open: true })
    expect(mocks.queue).not.toHaveBeenCalled()
    modal.vm.$emit('posted')
    await flushPromises()
    expect(mocks.queue).toHaveBeenCalledWith('2')
    expect(wrapper.findAll('article')).toHaveLength(0)
  })

  it.each([{ period_closed: true }, { booked: true }, { preview_error: true }])('does not offer an unsafe posting action: %j', async flags => {
    mocks.list.mockResolvedValue({ ...response, items: [{ ...row, type: 'post_invoice', action: 'post_document', rule_payload: undefined, booked: false, ...flags }] })
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).not.toContain('automation.recommendations.action_post_document')
  })

  it.each(['post_invoice', 'post_purchase'])('uses journal posting permission for %s, independently of document editing', async type => {
    mocks.list.mockResolvedValue({ ...response, items: [{ ...row, type, action: 'post_document', rule_payload: undefined, booked: false }] })
    mocks.canWrite.mockImplementation((permission: string) => permission === 'accounting.journal.post')
    const allowed = render()
    await flushPromises()
    const action = allowed.findAll('button').find(button => button.text() === 'automation.recommendations.action_post_document')
    expect(action).toBeDefined()
    await action!.trigger('click')
    expect(allowed.findComponent({ name: 'PostingPreviewModal' }).exists()).toBe(true)
    allowed.unmount()

    mocks.canWrite.mockImplementation((permission: string) => permission === 'invoices' || permission === 'purchase_invoices')
    const denied = render()
    await flushPromises()
    expect(denied.text()).not.toContain('automation.recommendations.action_post_document')
    expect(denied.findComponent({ name: 'PostingPreviewModal' }).exists()).toBe(false)
  })

  it('respects write permissions', async () => {
    mocks.canWrite.mockReturnValue(false)
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).not.toContain('automation.recommendations.action_create_expense_rule')
  })

  it('does not interpret an empty UI company scope as all companies', async () => {
    render([])
    await flushPromises()
    expect(mocks.list).not.toHaveBeenCalled()
  })

  it('ignores a stale response after switching the company filter', async () => {
    let complete!: (value: typeof response) => void
    mocks.list.mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
    const wrapper = render([1])
    await wrapper.setProps({ suppliers: [2] })
    await flushPromises()
    complete({ ...response, items: [{ ...row, description: 'Stale company data' }] })
    await flushPromises()
    expect(wrapper.text()).toContain('Synthetic service')
    expect(wrapper.text()).not.toContain('Stale company data')
  })

  it('queues a background refresh, keeps previous results and polls until ready', async () => {
    vi.useFakeTimers()
    const wrapper = render()
    await flushPromises()
    mocks.list.mockResolvedValueOnce({ ...response, snapshots: [{ ...response.snapshots[0], refresh_pending: true }] })
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.refresh')!.trigger('click')
    await flushPromises()
    expect(mocks.startJob).toHaveBeenCalledWith(2)
    expect(wrapper.text()).toContain('Synthetic service')
    expect(wrapper.text()).toContain('automation.recommendations.job_step_invoices')
    await vi.advanceTimersByTimeAsync(5000)
    await flushPromises()
    expect(mocks.getJob).toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(5000)
    expect(mocks.getJob.mock.calls.length).toBeGreaterThan(1)
  })

  it('shows an unbuilt snapshot as waiting, not as no recommendations, and cancels polling on unmount', async () => {
    vi.useFakeTimers()
    mocks.list.mockResolvedValue({ ...response, items: [], total: 0, snapshots: [{ supplier_id: 2, generated_at: null, refresh_pending: true }] })
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('automation.recommendations.waiting_first')
    expect(wrapper.text()).not.toContain('automation.recommendations.empty')
    expect(mocks.queue).not.toHaveBeenCalled()
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(5000)
    expect(mocks.list).toHaveBeenCalledOnce()
  })

  it('keeps polling across filter changes, retries connection errors and reloads completed results', async () => {
    vi.useFakeTimers()
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.refresh')!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'ImportJobProgress' }).props('showCancel')).toBe(false)
    await wrapper.find('select').setValue('classify_purchase')
    mocks.getJob.mockRejectedValueOnce(new Error('network'))
    await vi.advanceTimersByTimeAsync(2000)
    expect(wrapper.text()).toContain('automation.recommendations.job_connection_error')
    mocks.getJob.mockResolvedValue({ id: 1, status: 'completed', processed: 6, total_items: 6, created_count: 1, current_step: 'completed', finished_at: '2099-01-01' })
    const priorReads = mocks.list.mock.calls.length
    await vi.advanceTimersByTimeAsync(4000)
    await flushPromises()
    expect(wrapper.text()).toContain('automation.recommendations.job_completed')
    expect(wrapper.text()).not.toContain('automation.recommendations.job_connection_error')
    expect(mocks.list.mock.calls.length).toBeGreaterThan(priorReads)
  })

  it('ignores a start response from a previous company', async () => {
    let finish!: (value: unknown) => void
    mocks.startJob.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.refresh')!.trigger('click')
    mocks.getJob.mockResolvedValue(null)
    await wrapper.setProps({ suppliers: [3] })
    await flushPromises()
    finish({ id: 123, status: 'running', processed: 2, total_items: 6, current_step: 'expense_rules' })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'ImportJobProgress' }).exists()).toBe(false)
  })

  it('shows an immediate start failure and allows retry', async () => {
    mocks.startJob.mockRejectedValueOnce(new Error('network'))
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.refresh')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('automation.recommendations.job_failed')
    await wrapper.findAll('button').find(button => button.text() === 'automation.recommendations.job_retry')!.trigger('click')
    await flushPromises()
    expect(mocks.startJob).toHaveBeenCalledTimes(2)
    expect(wrapper.findComponent({ name: 'ImportJobProgress' }).exists()).toBe(true)
  })
})
