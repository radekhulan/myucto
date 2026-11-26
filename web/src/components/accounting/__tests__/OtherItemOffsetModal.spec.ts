import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  offsetContext: vi.fn(),
  offsetLines: vi.fn(),
  offset: vi.fn(),
  attachOffset: vi.fn(),
  listAccounts: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { offsetContext: m.offsetContext, offsetLines: m.offsetLines, offset: m.offset, attachOffset: m.attachOffset },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => key.startsWith('other_items.error.'),
  }),
}))

import OtherItemOffsetModal from '../OtherItemOffsetModal.vue'

const stubs = {
  Modal: { template: '<div><slot /><slot name="footer" /></div>' },
  DateInput: { props: ['modelValue'], template: '<input data-test="date" :value="modelValue" />' },
  ChartAccountSelect: {
    name: 'ChartAccountSelect',
    props: ['modelValue', 'accounts'],
    emits: ['update:modelValue'],
    template: '<input data-test="account" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
}

const item = { id: 7, side: 'receivable', currency: 'CZK', document_no: 'OP2099001', remaining_amount: 1000 }
const context = {
  supported: true,
  account: { id: 31, code: '315' },
  side: 'credit',
  remaining: 1000,
  suggested_accounts: [
    { account_id: 395, account_code: '395', name: 'Vnitřní zúčtování' },
    { account_id: 321, account_code: '321', name: 'Dodavatelé' },
  ],
}
const accounts = [
  { id: 395, account_code: '395', parent_id: null, is_active: true, account_type: 'asset' },
  { id: 321, account_code: '321', parent_id: null, is_active: true, account_type: 'liability' },
  { id: 31, account_code: '315', parent_id: null, is_active: true, account_type: 'asset' },
  { id: 343, account_code: '343', parent_id: null, is_active: true, account_type: 'liability' },
  { id: 518, account_code: '518', parent_id: null, is_active: true, account_type: 'expense' },
  { id: 751, account_code: '751', parent_id: null, is_active: true, account_type: 'offbalance' },
]

function mountModal(props: Record<string, unknown> = {}) {
  return mount(OtherItemOffsetModal, {
    props: { item, canPost: true, canAttach: true, ...props } as any,
    global: { stubs },
  })
}

describe('OtherItemOffsetModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.offsetContext.mockResolvedValue(context)
    m.listAccounts.mockResolvedValue(accounts)
    m.offsetLines.mockResolvedValue([])
  })

  it('zaúčtuje zápočet s předvoleným protiúčtem, zbývající částkou a náhledem kontace', async () => {
    m.offset.mockResolvedValue({ ...item, remaining_amount: 0 })
    const wrapper = mountModal()
    await flushPromises()

    expect(wrapper.findAll('[role="tab"]').map(tab => tab.text())).toEqual(['other_items.offset.tab_post', 'other_items.offset.tab_attach'])
    expect(wrapper.get('[data-test="offset-posting-hint"]').text()).toContain('395 MD / 315 D')
    // Protiúčet jen rozvahový, bez DPH 343 a účtu položky.
    expect(wrapper.findComponent({ name: 'ChartAccountSelect' }).props('accounts').map((a: { account_code: string }) => a.account_code))
      .toEqual(['395', '321'])
    await wrapper.findAll('[data-test="offset-suggested"] button')[1].trigger('click')
    expect(wrapper.get('[data-test="offset-posting-hint"]').text()).toContain('321 MD / 315 D')

    await wrapper.get('[data-test="offset-submit"]').trigger('click')
    await flushPromises()
    expect(m.offset).toHaveBeenCalledWith(7, expect.objectContaining({ account_id: 321, amount: 1000, note: null }))
    expect(wrapper.emitted('done')?.[0]?.[0]).toMatchObject({ remaining_amount: 0 })
  })

  it('závazek účtuje účet položky na MD', async () => {
    m.offsetContext.mockResolvedValue({ ...context, account: { id: 32, code: '325' }, side: 'debit' })
    const wrapper = mountModal({ item: { ...item, side: 'payable' } })
    await flushPromises()
    expect(wrapper.get('[data-test="offset-posting-hint"]').text()).toContain('325 MD / 395 D')
  })

  it('přiřadí vybraný řádek deníku nejvýš do volné částky', async () => {
    m.offsetLines.mockResolvedValue([{
      line_id: 55, entry_id: 9, line_no: 2, account_code: '315', side: 'credit', entry_date: '2099-02-12',
      document_no: 'ID2099001', description: 'Zápočet', line_amount: 700, free_amount: 400,
    }])
    m.attachOffset.mockResolvedValue({ ...item, remaining_amount: 600 })
    const wrapper = mountModal({ canPost: false })
    await flushPromises()

    expect(wrapper.findAll('[role="tab"]')).toHaveLength(0)
    expect(m.listAccounts).not.toHaveBeenCalled()
    expect((wrapper.get('[data-test="offset-submit"]').element as HTMLButtonElement).disabled).toBe(true)
    await wrapper.get('[data-test="offset-line"]').trigger('click')
    await wrapper.get('[data-test="offset-submit"]').trigger('click')
    await flushPromises()
    expect(m.attachOffset).toHaveBeenCalledWith(7, { line_id: 55, amount: 400 })
  })

  it('přeložená chyba ukáže podrobnost ze serveru', async () => {
    m.offset.mockRejectedValue({ response: { data: { error: {
      code: 'other_items.error.overpayment', message: 'Částka převyšuje zbývající dluh 1 000,00 Kč.', details: { remaining: 1000 },
    } } } })
    const wrapper = mountModal()
    await flushPromises()
    await wrapper.get('[data-test="offset-submit"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="offset-error"]').text()).toBe('other_items.error.overpayment:{"remaining":"1000","free":"","item_date":"","document_no":"","account":""}')
  })

  it('nepodporovanou položku nenabídne k vyrovnání', async () => {
    m.offsetContext.mockResolvedValue({ ...context, supported: false })
    const wrapper = mountModal()
    await flushPromises()
    expect(wrapper.get('[data-test="offset-error"]').text()).toBe('other_items.offset.unsupported')
    expect((wrapper.get('[data-test="offset-submit"]').element as HTMLButtonElement).disabled).toBe(true)
  })
})
