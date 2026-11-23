import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  activity: vi.fn(),
  rowPreview: vi.fn(),
  postRow: vi.fn(),
  terminationPreview: vi.fn(),
  terminate: vi.fn(),
  permissions: new Set<string>(),
  push: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return {
    ...actual,
    accrualsApi: {
      get: m.get, activity: m.activity, rowPreview: m.rowPreview, postRow: m.postRow,
      terminationPreview: m.terminationPreview, terminate: m.terminate, list: vi.fn(), bookUrl: actual.accrualsApi.bookUrl,
    },
  }
})
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (key: string) => m.permissions.has(key), canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (v: string) => v,
  formatMoney: (v: number) => `${v} Kč`,
  statusBadgeClass: (status: string) => `badge badge-${status}`,
}))
vi.mock('@/composables/useListNavigation', () => ({
  useListNavigation: () => ({ active: ref(false), hasPrevious: ref(false), hasNext: ref(false), busy: ref(false), listPath: ref(null), previous: vi.fn(), next: vi.fn() }),
}))
vi.mock('@/utils/date', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/utils/date')>()),
  appIsoDate: () => '2099-06-15',
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '5' } }),
  useRouter: () => ({ push: m.push }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: unknown) => {
    if (key === 'accruals.error.before_last_release') return `BLR:${(params as Record<string, unknown>).last_release_to}`
    return key.startsWith('accruals.error.') ? key : (params !== undefined && typeof params !== 'number' ? `${key}:${JSON.stringify(params)}` : key)
  } }),
}))

import AccrualDetail from '../AccrualDetail.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

const ModalStub = { template: '<div><slot /><slot name="footer" /></div>' }
const DateInputStub = { name: 'DateInput', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }

const CAN_ALL = { edit: true, activate: false, terminate: true, cancel_termination: false, cancel: true, delete: false }
function row(id: number, due_on: string, status = 'planned', changes: Record<string, unknown> = {}) {
  return { id, kind: 'release', due_on, from_on: null, to_on: null, days: 30, amount_planned: 100, status, journal_entry_id: null,
    entry_date: null, entry_document_no: null, entry_source_type: null, posted_at: null, ...changes }
}
function detail(changes: Record<string, unknown> = {}) {
  return {
    id: 5, item_no: 'CR-2099-0001', title: 'Syntetické pojištění', contract_ref: null, note: null, kind: 'prepaid_expense',
    accrual_account_code: '381', counter_account_code: '548', termination_account_code: null, partner_id: null, partner_name: null,
    source_type: 'manual', source_id: null, source_item_id: null, source_document_no: null, managed_by: 'agenda', basis_amount: 1200,
    opening_balance: null, opening_as_of: null, period_from: '2099-01-01', period_to: '2099-12-31', method: 'monthly_equal',
    status: 'active', terminated_on: null, termination_entry_id: null, termination_reason: null, cost_center: null, project_id: null,
    row_version: 1, created_at: '', updated_at: '', as_of: '2099-06-15', balance: 700, released: 500, balance_after_all: 0,
    covered_through: '2099-05-31', next_release: row(11, '2099-06-01'),
    schedule: [row(10, '2099-05-01', 'posted', { journal_entry_id: 90, entry_source_type: 'accrual_release' }), row(11, '2099-06-01'), row(12, '2099-07-01')],
    links: [], source: null, can: CAN_ALL, ...changes,
  }
}
function actions(wrapper: ReturnType<typeof shallowMount>): ActionItem[] {
  return wrapper.findComponent(ActionBar).props('actions') as ActionItem[]
}
function action(wrapper: ReturnType<typeof shallowMount>, key: string): ActionItem {
  return actions(wrapper).find(a => a.key === key)!
}
function mountDetail() {
  return shallowMount(AccrualDetail, { global: { stubs: { Modal: ModalStub, DateInput: DateInputStub } } })
}

describe('AccrualDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    m.permissions = new Set(['accounting.accruals.write', 'accounting.journal.post'])
    m.get.mockResolvedValue(detail())
    m.activity.mockResolvedValue([])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })
  afterEach(() => { vi.useRealTimers() })

  it('u aktivní položky se splatným řádkem je hlavní akcí Zaúčtovat rozpuštění', async () => {
    const wrapper = mountDetail()
    await flushPromises()
    expect(action(wrapper, 'post_release')).toMatchObject({ tier: 'primary', show: true })
    expect(action(wrapper, 'terminate')).toMatchObject({ variant: 'warning', show: true })
    expect(action(wrapper, 'edit').show).toBe(true)
    expect(action(wrapper, 'activate').show).toBe(false)
    expect(action(wrapper, 'cancel_termination').show).toBe(false)
    expect(wrapper.findAll('[data-test="row-post"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="row-unpost"]')).toHaveLength(1)
  })

  it('koncept nabídne Aktivovat jako hlavní akci a nesplatný řádek zaúčtovat nejde', async () => {
    m.get.mockResolvedValue(detail({ status: 'draft', next_release: null, schedule: [], can: { ...CAN_ALL, activate: true, terminate: false, delete: true } }))
    const wrapper = mountDetail()
    await flushPromises()
    expect(action(wrapper, 'activate')).toMatchObject({ tier: 'primary', show: true })
    expect(action(wrapper, 'post_release').show).toBe(false)
    expect(action(wrapper, 'delete').show).toBe(true)
  })

  it('bez práva účtovat do deníku zaúčtování ani ukončení nenabídne', async () => {
    m.permissions = new Set(['accounting.accruals.write'])
    const wrapper = mountDetail()
    await flushPromises()
    expect(action(wrapper, 'post_release').show).toBe(false)
    expect(action(wrapper, 'terminate').show).toBe(false)
    expect(action(wrapper, 'edit').show).toBe(true)
    expect(wrapper.find('[data-test="row-post"]').exists()).toBe(false)
  })

  it('ukončená položka nabídne zrušení ukončení v nabídce', async () => {
    m.get.mockResolvedValue(detail({ status: 'terminated', next_release: null, can: { ...CAN_ALL, edit: false, terminate: false, cancel: false, cancel_termination: true } }))
    const wrapper = mountDetail()
    await flushPromises()
    expect(action(wrapper, 'cancel_termination')).toMatchObject({ tier: 'overflow', variant: 'warning', show: true })
    expect(action(wrapper, 'edit').show).toBe(false)
  })

  it('řádek se sloučeným rozpuštěním za uzavřené období upozorní v plánu i v náhledu zaúčtování', async () => {
    const merged = row(11, '2099-06-01', 'planned', { from_on: '2098-07-01', to_on: '2099-06-01', amount_planned: 700, closed_period_amount: 600 })
    m.get.mockResolvedValue(detail({ next_release: merged, schedule: [merged, row(12, '2099-07-01', 'planned', { closed_period_amount: 0 })] }))
    m.rowPreview.mockResolvedValue({ row: merged, entry_date: '2099-06-01',
      lines: [{ account_code: '548', side: 'debit', amount: 700 }, { account_code: '381', side: 'credit', amount: 700 }],
      lock: { postable: true, closed: false, closing: false, date_locked: false, period_status: 'open' } })
    const wrapper = mountDetail()
    await flushPromises()
    const notes = wrapper.findAll('[data-test="row-closed-part"]')
    expect(notes).toHaveLength(1)
    expect(notes[0].text()).toContain('accruals.detail.closed_period_part')
    expect(notes[0].text()).toContain('600 Kč')
    action(wrapper, 'post_release').run!()
    await flushPromises()
    expect(wrapper.get('[data-test="row-preview-closed-part"]').text()).toContain('600 Kč')
  })

  it('zaúčtování ukáže náhled zápisu a v zamčeném období ho nedovolí', async () => {
    m.rowPreview.mockResolvedValue({ row: row(11, '2099-06-01'), entry_date: '2099-06-01',
      lines: [{ account_code: '548', side: 'debit', amount: 100 }, { account_code: '381', side: 'credit', amount: 100 }],
      lock: { postable: false, closed: false, closing: false, date_locked: true, period_status: 'open' } })
    const wrapper = mountDetail()
    await flushPromises()
    action(wrapper, 'post_release').run!()
    await flushPromises()
    expect(m.rowPreview).toHaveBeenCalledWith(5, 11)
    expect(wrapper.find('[data-test="row-lock"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="row-post-confirm"]').attributes('disabled')).toBeDefined()
  })

  it('ukončení: protiúčet předvyplní, budoucí převzaté řádky vyžadují storno i potvrzení', async () => {
    m.terminationPreview.mockResolvedValue({
      item_id: 5, date: '2099-06-15', covered_through: '2099-05-31', balance: 700, release_amount: 50, remainder_amount: 650, total: 700,
      counter_account_code: '548', termination_account_code: '548',
      lines: [{ account_code: '548', side: 'debit', amount: 700 }, { account_code: '381', side: 'credit', amount: 700 }],
      future_rows: [{ id: 12, due_on: '2099-07-01', amount: 100, status: 'linked', journal_entry_id: 91, entry_date: '2099-07-01', document_no: 'ID-9', imported: true }],
      requires_linked_confirmation: true,
      lock: { postable: true, closed: false, closing: false, date_locked: false, period_status: 'open' },
    })
    m.terminate.mockResolvedValue(detail({ status: 'terminated' }))
    const wrapper = mountDetail()
    await flushPromises()
    action(wrapper, 'terminate').run!()
    await flushPromises()
    expect(m.terminationPreview).toHaveBeenCalledWith(5, '2099-06-15', '548')
    expect(wrapper.findAll('[data-test="terminate-line"]')).toHaveLength(2)
    const confirm = () => wrapper.get('[data-test="terminate-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="terminate-reverse-future"]').setValue(true)
    expect(confirm().attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="terminate-confirm-linked"]').setValue(true)
    expect(confirm().attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="terminate-reason"]').setValue('Výpověď smlouvy')
    await wrapper.get('form#accrual-terminate').trigger('submit')
    await flushPromises()
    expect(m.terminate).toHaveBeenCalledWith(5, {
      date: '2099-06-15', termination_account_code: '548', reason: 'Výpověď smlouvy', reverse_future: true, confirm_linked: true,
    })
  })

  it('ukončení v uzavřeném období zakáže a chybu před posledním rozpuštěním vysvětlí', async () => {
    m.terminationPreview.mockResolvedValueOnce({
      item_id: 5, date: '2099-06-15', covered_through: null, balance: 700, release_amount: 0, remainder_amount: 700, total: 700,
      counter_account_code: '548', termination_account_code: '548', lines: [], future_rows: [], requires_linked_confirmation: false,
      lock: { postable: false, closed: true, closing: false, date_locked: false, period_status: 'closed' },
    })
    const wrapper = mountDetail()
    await flushPromises()
    action(wrapper, 'terminate').run!()
    await flushPromises()
    expect(wrapper.get('[data-test="terminate-lock"]').text()).toBe('accruals.detail.locked_closed')
    expect(wrapper.get('[data-test="terminate-confirm"]').attributes('disabled')).toBeDefined()

    m.terminationPreview.mockRejectedValueOnce({ response: { status: 409, data: { error: {
      code: 'accruals.error.before_last_release', message: 'Server', last_release_to: '2099-05-31' } } } })
    wrapper.findAllComponents({ name: 'DateInput' }).at(-1)!.vm.$emit('update:modelValue', '2099-04-30')
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(wrapper.get('[data-test="terminate-error"]').text()).toBe('BLR:2099-05-31')
  })

  it('změna data ukončení hned zahodí starý náhled a zamkne potvrzení', async () => {
    const preview = {
      item_id: 5, date: '2099-06-15', covered_through: '2099-05-31', balance: 700, release_amount: 50, remainder_amount: 650, total: 700,
      counter_account_code: '548', termination_account_code: '548', lines: [{ account_code: '548', side: 'debit', amount: 700 }, { account_code: '381', side: 'credit', amount: 700 }],
      future_rows: [], requires_linked_confirmation: false,
      lock: { postable: true, closed: false, closing: false, date_locked: false, period_status: 'open' },
    }
    m.terminationPreview.mockResolvedValue(preview)
    const wrapper = mountDetail()
    await flushPromises()
    action(wrapper, 'terminate').run!()
    await flushPromises()
    expect(wrapper.get('[data-test="terminate-confirm"]').attributes('disabled')).toBeUndefined()
    wrapper.findAllComponents({ name: 'DateInput' }).at(-1)!.vm.$emit('update:modelValue', '2099-04-30')
    await wrapper.vm.$nextTick()
    // Během zpoždění náhledu nejde potvrdit ukončení k novému datu nad starými čísly.
    expect(wrapper.get('[data-test="terminate-confirm"]').attributes('disabled')).toBeDefined()
    m.terminationPreview.mockResolvedValue({ ...preview, date: '2099-04-30' })
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(wrapper.get('[data-test="terminate-confirm"]').attributes('disabled')).toBeUndefined()
  })
})
