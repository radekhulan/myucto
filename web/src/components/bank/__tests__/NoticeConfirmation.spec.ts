import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { cs, en } from '../../../../tests/locales'
import type { BankTransaction } from '@/api/bank'
const mocks = vi.hoisted(() => ({ confirm: vi.fn(), batch: vi.fn(), client: false }))
vi.mock('@/api/bank', () => ({ bankApi: { confirmNotice: mocks.confirm, noticeConfirmations: mocks.batch } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, get isClientRole() { return mocks.client } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
import NoticeConfirmationStatus from '../NoticeConfirmationStatus.vue'
import NoticeConfirmationSummary from '../NoticeConfirmationSummary.vue'
import NoticeConfirmationCheck from '../NoticeConfirmationCheck.vue'
const global = () => ({ plugins: [createI18n({ legacy: false, locale: 'en', messages: { cs, en } })], stubs: { Modal: { template: '<div><slot /><slot name="footer" /></div>' }, RouterLink: { props: ['to'], template: '<a><slot /></a>' } } })
const tx = { id: 3, source: 'email_notice', posted_at: '2026-09-29', amount: -100, currency: 'CZK', confirmation_state: 'review', confirmation_reason: 'ambiguous', candidates: [{ id: 8, statement_id: 9, posted_at: '2026-09-30', amount: -100, currency: 'CZK' }] } as BankTransaction
beforeEach(() => { vi.clearAllMocks(); mocks.client = false })
describe('email notice confirmation', () => {
  it('hides manual comparison and batch confirmation for clients with bank matching rights', async () => {
    mocks.client = true
    const status = mount(NoticeConfirmationStatus, { props: { tx }, global: global() })
    const batch = mount(NoticeConfirmationCheck, { props: { statementId: 4 }, global: global() })
    expect(status.findAll('button')).toHaveLength(0)
    expect(status.find('[data-testid="notice-confirmation-status"]').exists()).toBe(false)
    expect(batch.findAll('button')).toHaveLength(0)
    await flushPromises()
    expect(mocks.confirm).not.toHaveBeenCalled()
    expect(mocks.batch).not.toHaveBeenCalled()
  })
  it('shows global counts independently of matching and transaction pages', () => {
    const wrapper = mount(NoticeConfirmationSummary, { props: { summary: { pending: 12, review: 3, confirmed: 47, total: 62 } }, global: global() })
    expect(wrapper.text()).toContain('12 awaiting statement')
    expect(wrapper.text()).toContain('3 need review')
    expect(wrapper.text()).toContain('47 replaced by statement')
  })
  it('requires a candidate and reason before manual confirmation', async () => {
    mocks.confirm.mockResolvedValue({ confirmation_state: 'confirmed' })
    const wrapper = mount(NoticeConfirmationStatus, { props: { tx }, global: global() })
    await wrapper.get('button').trigger('click')
    const button = wrapper.findAll('button').at(-1)!
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('input[type="radio"]').setValue()
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('textarea').setValue('Reviewed matching payment')
    await button.trigger('click'); await flushPromises()
    expect(mocks.confirm).toHaveBeenCalledWith(3, 8, 'Reviewed matching payment')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
  it('shows confirmed history and links with no confirmation action', () => {
    const wrapper = mount(NoticeConfirmationStatus, { props: { tx: { ...tx, confirmation_state: 'confirmed', authoritative_transaction_id: 8, authoritative_statement_ids: [9, 10] } }, global: global() })
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.findAll('a')).toHaveLength(2)
    expect(wrapper.text()).toContain('no longer affects active records')
  })
  it('previews all batches without adding global summaries before apply', async () => {
    const summary = { pending: 1, review: 2, confirmed: 3, total: 6 }
    mocks.batch.mockResolvedValueOnce({ confirmation_summary: summary, processed: 3, next_after_id: 3, has_more: true }).mockResolvedValueOnce({ confirmation_summary: summary, processed: 3, next_after_id: null, has_more: false }).mockResolvedValue({ confirmation_summary: summary, processed: 6, next_after_id: null, has_more: false })
    const wrapper = mount(NoticeConfirmationCheck, { props: { statementId: 4 }, global: global() })
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(mocks.batch.mock.calls[0]).toEqual([4, { dry_run: true, limit: 100, after_id: undefined }])
    expect(mocks.batch.mock.calls[1][1]).toMatchObject({ dry_run: true, after_id: 3 })
    expect(wrapper.text()).toContain('3 replaced by statement')
    expect(wrapper.text()).not.toContain('6 replaced by statement')
    await wrapper.findAll('button').at(-1)!.trigger('click'); await flushPromises()
    expect(mocks.batch.mock.calls[2][1].dry_run).toBe(false)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
  it('adds proposed batch counts while keeping actual global counts separate', async () => {
    const actual = { pending: 3, review: 0, confirmed: 4, total: 7 }
    mocks.batch.mockResolvedValueOnce({
      confirmation_summary: actual, proposed_summary: { pending: 0, review: 1, confirmed: 1, total: 2 },
      processed: 2, next_after_id: 2, has_more: true, items: [{ proposed_reason: 'ready' }],
    }).mockResolvedValueOnce({
      confirmation_summary: actual, proposed_summary: { pending: 0, review: 0, confirmed: 5, total: 5 },
      processed: 5, next_after_id: null, has_more: false, items: [{ proposed_reason: 'ready' }],
    })
    const wrapper = mount(NoticeConfirmationCheck, { props: { statementId: 4 }, global: global() })
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(wrapper.text()).toContain('6 replaced by statement')
    expect(wrapper.text()).toContain('1 need review')
    expect(wrapper.text()).not.toContain('8 replaced by statement')
    expect(mocks.batch).toHaveBeenCalledTimes(2)
  })

})
