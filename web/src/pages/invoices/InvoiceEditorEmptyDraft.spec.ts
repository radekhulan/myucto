import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))

import InvoiceEditor from './InvoiceEditor.vue'

type Vm = {
  editedStatus: string
  form: { invoice_type: string; currency: string; items: Array<Record<string, unknown>> }
  computed_totals: { amount_to_pay: number }
  hasNonPositiveAmountToPay: boolean
}

async function mountEditor(type = 'invoice') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  Object.assign(vm.form, { invoice_type: type, currency: 'CZK', items: [] })
  await wrapper.vm.$nextTick()
  return { wrapper, vm }
}

const zeroItem = { description: 'Pracovní položka', quantity: 1, unit_price_without_vat: 0, vat_rate_id: 0 }

describe('InvoiceEditor prázdný koncept', () => {
  it.each(['invoice', 'proforma'])('dovolí uložit %s po smazání poslední položky', async (type) => {
    const { wrapper, vm } = await mountEditor(type)
    vm.form.items = [{ ...zeroItem, unit_price_without_vat: 100 }]
    expect(vm.computed_totals.amount_to_pay).toBe(100)
    vm.form.items = []
    expect(vm.computed_totals.amount_to_pay).toBe(0)
    expect(vm.hasNonPositiveAmountToPay).toBe(false)
    wrapper.unmount()
  })

  it('dovolí koncept obsahující pouze textový řádek bez plnění', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.form.items = [{ item_kind: 'text', description: 'Rozpracovaný oddíl' }]
    expect(vm.computed_totals.amount_to_pay).toBe(0)
    expect(vm.hasNonPositiveAmountToPay).toBe(false)
    wrapper.unmount()
  })

  it.each(['invoice', 'proforma'])('stále blokuje neprázdnou %s s nulovou částkou', async (type) => {
    const { wrapper, vm } = await mountEditor(type)
    vm.form.items = [{ ...zeroItem }]
    expect(vm.computed_totals.amount_to_pay).toBe(0)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })

  it('neuděluje výjimku prázdnému vystavenému dokladu', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.editedStatus = 'issued'
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
})
