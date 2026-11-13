import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { nextTick, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RowAction } from '@/components/ui/RowActionsMenu.vue'

// Telefon: karta položky má místo ▲ ▼ × jednu nabídku „…", mimo telefon zůstanou
// původní tlačítka.

const phoneRef = ref(true)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
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
  form: { items: Array<Record<string, unknown>> }
  itemRowMenuActions: (index: number) => RowAction[]
}

async function mountEditor() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }],
  })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  // Číselníky se v testu nenačtou; formulář vykreslíme rovnou.
  ;(wrapper.vm as unknown as { loaded: boolean }).loaded = true
  vm.form.items = [
    { description: 'A', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 0, order_index: 0 },
    { description: 'B', quantity: 1, unit: 'ks', unit_price_without_vat: 200, vat_rate_id: 0, order_index: 1 },
  ]
  await nextTick()
  return { wrapper, vm }
}

const arrowButtons = (wrapper: ReturnType<typeof shallowMount>) =>
  wrapper.findAll('button').filter(b => b.text() === '▲' || b.text() === '▼')

describe('InvoiceEditor položky na telefonu', () => {
  beforeEach(() => { phoneRef.value = true })

  it('na telefonu nahradí ▲ ▼ × nabídkou „…" s posunem a smazáním', async () => {
    const { wrapper, vm } = await mountEditor()
    expect(wrapper.findAll('[data-test="item-row-menu-phone"]')).toHaveLength(2)
    // Desktopová tabulka (container query) nese své ▲ ▼ dál; na kartách už nejsou.
    const cardArrows = arrowButtons(wrapper).filter(b => b.classes().includes('w-8'))
    expect(cardArrows).toHaveLength(0)

    const first = vm.itemRowMenuActions(0)
    expect(first.map(a => a.key)).toEqual(['move-up', 'move-down', 'remove'])
    expect(first[0].disabled).toBe(true)
    first[1].run!()
    expect(vm.form.items.map(i => i.description)).toEqual(['B', 'A'])
    vm.itemRowMenuActions(1)[2].run!()
    expect(vm.form.items.map(i => i.description)).toEqual(['B'])
    wrapper.unmount()
  })

  it('mimo telefon zůstanou na kartách tlačítka ▲ ▼ × bez nabídky', async () => {
    phoneRef.value = false
    const { wrapper } = await mountEditor()
    expect(wrapper.find('[data-test="item-row-menu-phone"]').exists()).toBe(false)
    expect(arrowButtons(wrapper).filter(b => b.classes().includes('w-8'))).toHaveLength(4)
    wrapper.unmount()
  })

  it('spodní lišta: Zpět jen mimo telefon, tlačítko uložení na telefonu přes šířku', async () => {
    const { wrapper } = await mountEditor()
    expect(wrapper.find('[data-test="editor-bar-back"]').classes()).toContain('max-md:hidden')
    expect(wrapper.find('[data-test="editor-bar-submit"]').classes()).toEqual(expect.arrayContaining(['h-10', 'max-md:flex-1']))
    wrapper.unmount()
  })
})
