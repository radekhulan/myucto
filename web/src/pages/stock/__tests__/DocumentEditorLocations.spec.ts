import { flushPromises, mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import DocumentEditor from '../DocumentEditor.vue'

const mocks = vi.hoisted(() => {
  const draft = {
    id: 8, status: 'draft', doc_type: 'transfer', warehouse_id: 1, warehouse_to_id: 1,
    doc_date: '2026-11-09', description: 'Přesun',
    lines: [{ stock_item_id: 7, qty: '2', sku: 'K-7', name: 'Karta', unit: 'ks', tracking_mode: 'none', location_id: 11, location_to_id: null }],
  }
  return {
    getDocument: vi.fn(async () => draft),
    updateDocument: vi.fn(async () => draft),
  }
})
vi.mock('@/api/codebooks', () => ({ codebooksApi: { units: vi.fn(async () => []) } }))
vi.mock('@/api/stock', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/stock')>(),
  stockApi: {
    listWarehouses: vi.fn(async () => [{ id: 1, name: 'Hlavní' }, { id: 2, name: 'Vedlejší' }]),
    listLocations: vi.fn(async () => [
      { id: 11, warehouse_id: 1, code: 'A', name: 'Spodní', is_active: true },
      { id: 12, warehouse_id: 1, code: 'B', name: 'Horní', is_active: true },
      { id: 13, warehouse_id: 1, code: 'D', name: 'Uzavřená', is_active: false },
      { id: 21, warehouse_id: 2, code: 'C', name: 'Příjem', is_active: true },
    ]),
    availability: vi.fn(async () => ({ 7: '10' })),
    getDocument: mocks.getDocument,
    updateDocument: mocks.updateDocument,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

it('při uložení přesunu v témže skladu zachová lokaci konceptu a ověří rozdílné pozice', async () => {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/stock/documents/:id', component: { render: () => null } }] })
  await router.push('/stock/documents/8')
  const wrapper = mount(DocumentEditor, { global: { plugins: [router] } })
  try {
    await flushPromises()
    const locationSelect = (inputId: string) => wrapper.findAllComponents({ name: 'SearchableSelect' }).find(select => select.props('inputId') === inputId)!
    const source = locationSelect('stock-line-0-location')
    const target = locationSelect('stock-line-0-location-to')
    expect(source.props('modelValue')).toBe(11)
    expect(source.props('options')).toMatchObject([{ value: 11 }, { value: 12 }, { value: 13 }])
    expect(target.props('options')).toMatchObject([{ value: 11 }, { value: 12 }])
    expect(wrapper.findAll('select')[1]!.find('option[value="1"]').attributes('disabled')).toBeUndefined()

    const save = () => wrapper.findAll('button').find(button => button.text() === 'stock.documents.save_draft')!.trigger('click')
    target.vm.$emit('update:modelValue', 11)
    await save()
    expect(wrapper.text()).toContain('stock.documents.location_transfer_distinct')
    expect(mocks.updateDocument).not.toHaveBeenCalled()

    target.vm.$emit('update:modelValue', 12)
    await save()
    await flushPromises()
    expect(mocks.updateDocument).toHaveBeenCalledExactlyOnceWith(8, expect.objectContaining({
      warehouse_id: 1, warehouse_to_id: 1,
      lines: [expect.objectContaining({ stock_item_id: 7, location_id: 11, location_to_id: 12 })],
    }))

    await wrapper.findAll('select')[0]!.setValue('2')
    expect(locationSelect('stock-line-0-location').props('modelValue')).toBeNull()
    expect(locationSelect('stock-line-0-location').props('options')).toMatchObject([{ value: 21 }])
  } finally { wrapper.unmount() }
})
