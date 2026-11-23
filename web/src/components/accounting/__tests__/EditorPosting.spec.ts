import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ draftPostingPreview: vi.fn() }))

vi.mock('@/api/accounting', () => ({
  accountingApi: { draftPostingPreview: m.draftPostingPreview },
  postingErrorMessage: (_t: unknown, e: any) => e?.response?.data?.error?.message ?? 'error',
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (amount: number) => amount.toFixed(2) }))

import ItemPostingFields from '../ItemPostingFields.vue'
import DraftPostingPreviewPanel from '../DraftPostingPreviewPanel.vue'

const ChartAccountSelect = {
  props: ['modelValue', 'accounts', 'placeholder'],
  emits: ['update:modelValue'],
  template: '<input class="account" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
}

const rule = {
  id: 1, supplier_id: null, rule_key: 'test.material', description: 'Materiál', debit_account_code: '501',
  credit_account_code: '325', priority: 0, is_active: true, line_purchase: true, line_sale: true,
}

describe('ItemPostingFields', () => {
  it('předkontace doplní prázdný protiúčet a ohlásí se rodiči', async () => {
    const wrapper = mount(ItemPostingFields, {
      props: { direction: 'purchase', ruleKey: null, counterAccount: null, rules: [rule], accounts: [] } as any,
      global: { stubs: { ChartAccountSelect } },
    })

    await wrapper.find('[data-test="item-posting-rule"] select').setValue('test.material')

    expect(wrapper.emitted('update:ruleKey')?.[0]).toEqual(['test.material'])
    expect(wrapper.emitted('update:counterAccount')?.[0]).toEqual(['325'])
    expect(wrapper.emitted('ruleApplied')?.[0]).toEqual([rule])
  })

  it('vyplněný protiúčet předkontace nepřepíše; vydaná faktura bere stranu MD', async () => {
    const wrapper = mount(ItemPostingFields, {
      props: { direction: 'sale', ruleKey: null, counterAccount: '311.100', rules: [{ ...rule, debit_account_code: '311', credit_account_code: '602' }], accounts: [] } as any,
      global: { stubs: { ChartAccountSelect } },
    })

    await wrapper.find('[data-test="item-posting-rule"] select').setValue('test.material')

    expect(wrapper.emitted('update:counterAccount')).toBeUndefined()
  })
})

describe('ItemPostingFields: předkontace podle typu dokladu', () => {
  const sale = { ...rule, rule_key: 'test.sale', debit_account_code: '311', credit_account_code: '602', line_purchase: false, line_sale: true }
  const purchase = { ...rule, rule_key: 'test.purchase', debit_account_code: '518', credit_account_code: '321', line_purchase: true, line_sale: false }
  const neither = { ...rule, rule_key: 'test.accrual', debit_account_code: '518', credit_account_code: '321', line_purchase: false, line_sale: false }
  function keys(direction: 'purchase' | 'sale'): string[] {
    const wrapper = mount(ItemPostingFields, {
      props: { direction, ruleKey: null, counterAccount: null, rules: [sale, purchase, neither], accounts: [] } as any,
      global: { stubs: { ChartAccountSelect } },
    })
    return wrapper.findAll('[data-test="item-posting-rule"] option').map(o => (o.element as HTMLOptionElement).value).filter(Boolean)
  }

  it('přijatá faktura nenabízí předkontaci vydané faktury', () => {
    expect(keys('purchase')).toEqual(['test.purchase'])
  })

  it('předkontace bez příznaku použitelnosti na řádku se nenabízí vůbec', () => {
    expect(keys('purchase')).not.toContain('test.accrual')
    expect(keys('sale')).not.toContain('test.accrual')
  })

  it('vydaná faktura nenabízí předkontaci přijaté faktury', () => {
    expect(keys('sale')).toEqual(['test.sale'])
  })
})

describe('DraftPostingPreviewPanel', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
  })
  afterEach(() => vi.useRealTimers())

  it('bez dokladu k uložení se server neptá', async () => {
    mount(DraftPostingPreviewPanel, { props: { source: 'purchase-invoices', docId: null, payload: null } })
    await vi.advanceTimersByTimeAsync(1000)

    expect(m.draftPostingPreview).not.toHaveBeenCalled()
  })

  it('po prodlevě zobrazí řádky návrhu a jejich vyrovnanost', async () => {
    m.draftPostingPreview.mockResolvedValue({
      source_type: 'purchase_invoice', doc_id: null, entry_date: '2026-03-02', balanced: true, already_posted: null,
      lines: [
        { account_code: '518', account_name: 'Služby', side: 'debit', amount: 100 },
        { account_code: '343.100', account_name: 'DPH', side: 'debit', amount: 21 },
        { account_code: '321', account_name: 'Dodavatelé', side: 'credit', amount: 121 },
      ],
    })
    const wrapper = mount(DraftPostingPreviewPanel, { props: { source: 'purchase-invoices', docId: 7, payload: { items: [1] } } })

    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()

    expect(m.draftPostingPreview).toHaveBeenCalledTimes(1)
    expect(m.draftPostingPreview.mock.calls[0].slice(0, 3)).toEqual(['purchase-invoices', 7, { items: [1] }])
    expect(wrapper.text()).toContain('343.100')
    expect(wrapper.find('[data-test="draft-posting-balanced"]').text()).toBe('accounting.editor_posting.balanced')
  })

  it('chybu zaúčtování ukáže místo řádků', async () => {
    m.draftPostingPreview.mockRejectedValue({ response: { data: { error: { code: 'x', message: 'Období je uzavřené.' } } } })
    const wrapper = mount(DraftPostingPreviewPanel, { props: { source: 'invoices', docId: null, payload: { items: [1] } } })

    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()

    expect(wrapper.find('[data-test="draft-posting-error"]').text()).toBe('Období je uzavřené.')
  })

  it('validační chybu 4xx ukáže jako neutrální hlášku, ne jako chybu', async () => {
    m.draftPostingPreview.mockRejectedValue({ response: { status: 400, data: { error: { code: 'validation', message: 'Validace selhala' } } } })
    const wrapper = mount(DraftPostingPreviewPanel, { props: { source: 'invoices', docId: null, payload: { items: [1] } } })

    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()

    expect(wrapper.find('[data-test="draft-posting-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="draft-posting-invalid"]').exists()).toBe(true)
  })

  it('chybu zaúčtování ze serveru (422, stage posting) ukáže v boxu s textem serveru', async () => {
    m.draftPostingPreview.mockRejectedValue({ response: { status: 422, data: { error: {
      code: 'mixed_counter_accounts', stage: 'posting', message: 'Řádky dokladu mají různé protiúčty (325, 321).',
    } } } })
    const wrapper = mount(DraftPostingPreviewPanel, { props: { source: 'purchase-invoices', docId: null, payload: { items: [1] } } })

    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()

    expect(wrapper.find('[data-test="draft-posting-error"]').text()).toBe('Řádky dokladu mají různé protiúčty (325, 321).')
    expect(wrapper.find('[data-test="draft-posting-invalid"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('accounting.editor_posting.incomplete')
  })
})
