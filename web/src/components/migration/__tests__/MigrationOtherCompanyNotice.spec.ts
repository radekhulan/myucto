import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

type Brief = { id: number; company_name: string; ic: string | null }
const store = vi.hoisted(() => ({ list: [] as Brief[], currentId: 1, hasMultiple: true }))
const switchTo = vi.hoisted(() => vi.fn())
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get availableSuppliers() { return store.list },
    get currentSupplierId() { return store.currentId },
    get currentSupplier() { return store.list.find(s => s.id === store.currentId) ?? null },
    get hasMultiple() { return store.hasMultiple },
  }),
}))
vi.mock('@/composables/useSupplierSwitch', async () => {
  const { ref } = await import('vue')
  return { useSupplierSwitch: () => ({ switching: ref(false), switchTo }) }
})
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => p ? `${key} ${JSON.stringify(p)}` : key }) }))

import MigrationOtherCompanyNotice from '@/components/migration/MigrationOtherCompanyNotice.vue'

const OWN = { id: 1, company_name: 'Alfa s.r.o.', ic: '12345678' }
const OTHER = { id: 2, company_name: 'Beta s.r.o.', ic: '87654321' }

function render(agendas: Array<{ ico: string; company?: string }>, supplierIco = OWN.ic, list: Brief[] = [OWN, OTHER]) {
  store.list = list
  store.currentId = 1
  switchTo.mockClear()
  return mount(MigrationOtherCompanyNotice, {
    props: { agendas, supplierIco, testid: 'x' },
    slots: { default: 'FALLBACK' },
  })
}

describe('MigrationOtherCompanyNotice', () => {
  it('záloha jiné firmy, která v MyÚčtu je, nabídne přepnutí do ní', async () => {
    const wrapper = render([{ ico: '87654321', company: 'Beta s.r.o.' }, { ico: '87654321', company: 'Beta s.r.o.' }])
    expect(wrapper.text()).not.toContain('FALLBACK')
    expect(wrapper.text()).toContain('migration_other_company.switch_hint')
    expect(wrapper.text()).toContain('"current":"Alfa s.r.o."')
    const buttons = wrapper.findAll('[data-testid="x-switch-87654321"]')
    expect(buttons).toHaveLength(1)
    await buttons[0].trigger('click')
    expect(switchTo).toHaveBeenCalledWith(2)
  })

  it('firma s IČO zálohy v MyÚčtu chybí: odkáže na založení firmy', () => {
    const wrapper = render([{ ico: '87654321', company: 'Beta s.r.o.' }], OWN.ic, [OWN])
    expect(wrapper.text()).toContain('migration_other_company.missing_hint')
    expect(wrapper.find('[data-testid="x-create-87654321"]').attributes('data-to')).toContain('admin-suppliers')
  })

  it('bez IČO firmy nebo bez agend nechá hlášku průvodce', () => {
    expect(render([{ ico: '87654321' }], '').text()).toBe('FALLBACK')
    expect(render([]).text()).toBe('FALLBACK')
  })

  it('u uzamčené domény přepnutí nenabídne', () => {
    store.hasMultiple = false
    const wrapper = render([{ ico: '87654321', company: 'Beta s.r.o.' }])
    store.hasMultiple = true
    expect(wrapper.text()).toContain('migration_other_company.switch_hint')
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
