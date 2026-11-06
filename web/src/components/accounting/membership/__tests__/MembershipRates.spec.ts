import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Rates from '../MembershipRates.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import type { MembershipRate } from '@/api/nonprofitMembership'
import { notifyMembershipRatesChanged } from '../membershipRateChanges'

const api = vi.hoisted(() => ({ rates: vi.fn(), rate: vi.fn(), createRate: vi.fn(), updateRate: vi.fn(), classificationOptions: vi.fn() }))
const chart = vi.hoisted(() => ({ listAccounts: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))
vi.mock('@/api/accounting', () => ({ accountingApi: chart }))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', (p.actions as Array<{key: string; label: string; disabled?: boolean; show?: unknown; run: () => void}>).filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))) })
const SearchableSelect = defineComponent({ props: ['modelValue', 'options', 'disabled'], emits: ['update:modelValue'], setup: (p, {emit, attrs}) => () => h('select', { ...attrs, value: p.modelValue ?? '', disabled: p.disabled,
  onChange: (e: Event) => { const option = (p.options as {value: string | number}[]).find(x => String(x.value) === (e.target as HTMLSelectElement).value); emit('update:modelValue', option?.value ?? null) },
}, [h('option', {value: ''}), ...(p.options as {value: string | number; label: string}[]).map(x => h('option', {value: x.value}, x.label))]) })
const rate: MembershipRate = { id: 1, supplier_id: 5, name: 'Synthetic standard', amount: '500.00', receivable_account: '315', revenue_account: '684.020', title_key: 'synthetic', period_months: 12, title_reference: 'Synthetic resolution', evidence: 'Synthetic evidence', classification: null, is_active: true, row_version: 1 }
function mounted(props = {}) { return mount(Rates, { attachTo: document.body, props: {supplier: 5, canManage: true, selectable: true, ...props}, global: { plugins: [createI18n({legacy: false, locale: 'cs', messages: {cs, en}})], stubs: {ActionBar, SearchableSelect} } }) }
beforeEach(() => {
  vi.clearAllMocks()
  api.rates.mockResolvedValue({items: [rate], next_after_id: null})
  api.rate.mockResolvedValue(rate)
  api.classificationOptions.mockResolvedValue({types: [], values: []})
  chart.listAccounts.mockResolvedValue([{id: 1, supplier_id: 5, account_code: '315', name: 'Synthetic receivable', account_type: 'asset', is_active: true, is_synthetic: false}, {id: 2, supplier_id: 5, account_code: '684.020', name: 'Synthetic revenue', account_type: 'revenue', is_active: true, is_synthetic: false}])
})
describe('sazby členských příspěvků', () => {
  it('renders empty search and clear selection labels in English', async () => {
    const w = mount(Rates, {props: {supplier: 5, canManage: true}, global: {
      plugins: [createI18n({legacy: false, locale: 'en', messages: {cs, en}})], stubs: {ActionBar, WorkspaceTeleport: {template: '<div><slot /></div>'}},
    }})
    await flushPromises()
    const input = w.get('[data-field="rate-select"] input')
    await input.trigger('focus'); await input.setValue('synthetic missing search')
    expect(w.text()).toContain('No results')
    expect(w.text()).not.toContain('Žádné výsledky')
    await input.setValue('Synthetic standard')
    await w.get('[role="option"]').trigger('click'); await flushPromises()
    expect(w.find('button[aria-label="Clear selection"]').exists()).toBe(true)
    w.unmount()
  })
  it('reloads a conflicted editor only after acknowledging replacement of its draft', async () => {
    api.updateRate.mockRejectedValueOnce(new Error('Synthetic version conflict'))
    const w = mounted(); await flushPromises()
    await w.get('[data-field="rate-select"]').setValue('1')
    await w.get('[data-action="edit-rate"]').trigger('click'); await flushPromises()
    await w.get('[data-field="rate-name"]').setValue('Synthetic draft')
    await w.get('[data-action="save-rate"]').trigger('click'); await flushPromises()
    api.rate.mockResolvedValue({...rate, name: 'Synthetic current', row_version: 2})
    await w.get('[data-action="reload-rate-editor"]').trigger('click'); await flushPromises()
    expect(w.find('[data-action="cancel-rate"]').exists()).toBe(false)
    expect(document.activeElement).toBe(w.get('[data-action="cancel-rate-reload"]').element)
    await w.get('[data-action="cancel-rate-reload"]').trigger('click'); await flushPromises()
    expect(document.activeElement).toBe(w.get('[data-action="reload-rate-editor"]').element)
    await w.get('[data-action="reload-rate-editor"]').trigger('click'); await flushPromises()
    expect((w.get('[data-field="rate-name"]').element as HTMLInputElement).value).toBe('Synthetic draft')
    await w.get('[data-action="confirm-rate-reload"]').trigger('click'); await flushPromises()
    expect((w.get('[data-field="rate-name"]').element as HTMLInputElement).value).toBe('Synthetic current')
    expect(document.activeElement).toBe(w.get('[data-field="rate-name"]').element)
    api.updateRate.mockResolvedValue({...rate, row_version: 3})
    await w.get('[data-action="save-rate"]').trigger('click'); await flushPromises()
    expect(api.updateRate.mock.calls[1]?.[2].row_version).toBe(2)
    w.unmount()
  })
  it('hides manual entry when the parent requires a rate', async () => {
    const w = mounted({allowManual: false}); await flushPromises()
    expect(w.find('[data-action="manual-rate"]').exists()).toBe(false)
    w.unmount()
  })
  it.each(['create', 'update'])('keeps a successful %s response when its cached panel reactivates during saving', async path => {
    let complete!: (value: MembershipRate) => void
    const pending = new Promise<MembershipRate>(resolve => { complete = resolve })
    if (path === 'create') api.createRate.mockReturnValue(pending)
    else api.updateRate.mockReturnValue(pending)
    const visible = ref(true)
    const host = mount(defineComponent({setup: () => () => h(KeepAlive, () => visible.value ? h(Rates, {supplier: 5, canManage: true}) : null)}), {
      global: {plugins: [createI18n({legacy: false, locale: 'cs', messages: {cs, en}})], stubs: {ActionBar, SearchableSelect}},
    })
    await flushPromises()
    const w = host.findComponent(Rates)
    if (path === 'update') await w.get('[data-field="rate-select"]').setValue('1')
    await w.get(`[data-action="${path === 'create' ? 'new-rate' : 'edit-rate'}"]`).trigger('click'); await flushPromises()
    for (const [key, value] of Object.entries({name: rate.name, amount: rate.amount, receivable: rate.receivable_account,
      revenue: rate.revenue_account, title: rate.title_key, reference: rate.title_reference, evidence: rate.evidence})) {
      await w.get(`[data-field="rate-${key}"]`).setValue(value)
    }
    await w.get('[data-action="save-rate"]').trigger('click')
    visible.value = false; await flushPromises()
    visible.value = true; await flushPromises()
    complete({...rate, id: path === 'create' ? 2 : 1, row_version: path === 'create' ? 1 : 2})
    await flushPromises()
    expect(host.find('[data-section="rate-editor"]').exists()).toBe(false)
    expect(host.text()).toContain(cs.nonprofitMembership.rateSaved)
    expect(path === 'create' ? api.createRate : api.updateRate).toHaveBeenCalledTimes(1)
    host.unmount()
  })

  it('locks selection while refreshing the detail of a selected later-page rate', async () => {
    const later = {...rate, id: 150}
    api.rates.mockResolvedValueOnce({items: [rate], next_after_id: 100}).mockResolvedValueOnce({items: [later], next_after_id: null})
    const w = mounted(); await flushPromises()
    await w.get('[data-action="more-rates"]').trigger('click'); await flushPromises()
    await w.get('[data-field="rate-select"]').setValue('150')
    let complete!: (value: MembershipRate) => void
    api.rate.mockReturnValue(new Promise(resolve => {complete = resolve}))
    notifyMembershipRatesChanged(5); await flushPromises()
    expect(w.get('[data-field="rate-select"]').attributes('disabled')).toBeDefined()
    // Otevřená nabídka sdíleného výběru může ještě emitovat volbu po jeho zablokování.
    w.findComponent(SearchableSelect).vm.$emit('update:modelValue', 1)
    await flushPromises()
    expect(w.findComponent(SearchableSelect).props('modelValue')).toBe(150)
    complete(later); await flushPromises()
    expect((w.get('[data-field="rate-select"]').element as HTMLSelectElement).value).toBe('150')
    w.unmount()
  })

  it('refreshes rates when a cached workspace is activated again', async () => {
    const visible = ref(true)
    const w = mount(defineComponent({setup: () => () => h(KeepAlive, () => visible.value ? h(Rates, {supplier: 5, canManage: true}) : null)}), {
      global: {plugins: [createI18n({legacy: false, locale: 'cs', messages: {cs, en}})], stubs: {ActionBar, SearchableSelect}},
    })
    await flushPromises()
    expect(api.rates).toHaveBeenCalledTimes(1)
    visible.value = false; await flushPromises()
    visible.value = true; await flushPromises()
    expect(api.rates).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('preserves a selected later-page rate on catalog refresh and checks its current status', async () => {
    const later = {...rate, id: 150}
    api.rates.mockResolvedValueOnce({items: [rate], next_after_id: 100}).mockResolvedValueOnce({items: [later], next_after_id: null})
    const w = mounted(); await flushPromises()
    await w.get('[data-action="more-rates"]').trigger('click'); await flushPromises()
    await w.get('[data-field="rate-select"]').setValue('150')
    api.rates.mockResolvedValue({items: [rate], next_after_id: 100})
    api.rate.mockResolvedValue({...later, is_active: false})
    notifyMembershipRatesChanged(5); await flushPromises()
    expect(api.rate).toHaveBeenCalledWith(5, 150)
    expect((w.get('[data-field="rate-select"]').element as HTMLSelectElement).value).toBe('150')
    expect(w.get('[data-action="use-rate"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('retries editor options without losing a draft or hiding the error during catalog refresh', async () => {
    chart.listAccounts.mockRejectedValueOnce(new Error('Synthetic network error'))
    const w = mounted(); await flushPromises()
    await w.get('[data-action="new-rate"]').trigger('click'); await flushPromises()
    await w.get('[data-field="rate-name"]').setValue('Synthetic draft')
    await w.get('[data-action="reload-rates"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="rate-editor-error"]').exists()).toBe(true)
    await w.get('[data-action="reload-rate-options"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="rate-editor-error"]').exists()).toBe(false)
    expect((w.get('[data-field="rate-name"]').element as HTMLInputElement).value).toBe('Synthetic draft')
    expect(w.get('[data-field="rate-revenue"]').text()).toContain('684.020')
    w.unmount()
  })

  it('uses a rate only after explicit confirmation and retains a saved item outside the first page', async () => {
    const w = mounted(); await flushPromises()
    await w.get('[data-field="rate-select"]').setValue('1')
    expect(w.emitted('selected')).toBeUndefined()
    await w.get('[data-action="use-rate"]').trigger('click')
    expect(w.emitted('selected')?.[0]?.[0]).toEqual(rate)
    await w.get('[data-action="edit-rate"]').trigger('click'); await flushPromises()
    expect(w.find('[data-action="new-rate"]').exists()).toBe(false)
    await w.get('[data-field="rate-name"]').setValue('Synthetic updated')
    api.updateRate.mockResolvedValue({...rate, name: 'Synthetic updated', row_version: 2})
    api.rates.mockResolvedValue({items: [], next_after_id: 100})
    await w.get('[data-action="save-rate"]').trigger('click'); await flushPromises()
    expect(api.updateRate).toHaveBeenCalledWith(5, 1, expect.objectContaining({name: 'Synthetic updated', row_version: 1}))
    expect(w.get('[data-field="rate-select"]').text()).toContain('Synthetic updated')
    expect(w.get('[data-action="use-rate"]').attributes('disabled')).toBeUndefined()
    expect(w.emitted('selected')).toHaveLength(1)
    w.unmount()
  })
  it('does not offer catalog writes without permission or apply an inactive rate', async () => {
    api.rates.mockResolvedValue({items: [{...rate, is_active: false}], next_after_id: null})
    const w = mounted({canManage: false}); await flushPromises()
    await w.get('[data-field="rate-select"]').setValue('1')
    expect(w.find('[data-action="new-rate"]').exists()).toBe(false)
    expect(w.find('[data-action="edit-rate"]').exists()).toBe(false)
    expect(w.get('[data-action="use-rate"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })
  it('refreshes catalog changes without applying a rate to the draft', async () => {
    const w = mounted(); await flushPromises()
    notifyMembershipRatesChanged(8); await flushPromises()
    expect(api.rates).toHaveBeenCalledTimes(1)
    notifyMembershipRatesChanged(5); await flushPromises()
    expect(api.rates).toHaveBeenCalledTimes(2)
    expect(w.emitted('selected')).toBeUndefined()
    w.unmount()
  })
  it('does not lose editor options when the catalog is refreshed during their request', async () => {
    let complete!: (value: unknown) => void
    chart.listAccounts.mockReturnValue(new Promise(resolve => { complete = resolve }))
    const w = mounted(); await flushPromises()
    await w.get('[data-action="new-rate"]').trigger('click')
    await w.get('[data-action="reload-rates"]').trigger('click'); await flushPromises()
    complete([{id: 2, supplier_id: 5, account_code: '684.020', name: 'Synthetic revenue', account_type: 'revenue', is_active: true, is_synthetic: false}])
    await flushPromises()
    expect(w.get('[data-field="rate-revenue"]').text()).toContain('684.020')
    w.unmount()
  })
  it('ignores a late editor response after the supplier changes', async () => {
    let complete!: (value: unknown) => void
    chart.listAccounts.mockReturnValue(new Promise(resolve => { complete = resolve }))
    const w = mounted(); await flushPromises()
    await w.get('[data-action="new-rate"]').trigger('click')
    await w.setProps({supplier: 6}); await flushPromises()
    complete([]); await flushPromises()
    expect(w.find('[data-section="rate-editor"]').exists()).toBe(false)
    expect(w.emitted('selected')).toBeUndefined()
    w.unmount()
  })
})
