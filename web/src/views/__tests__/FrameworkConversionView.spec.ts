import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'

const m = vi.hoisted(() => ({ supplier: null as null | { entity_profile?: string; accounting_framework?: string }, can: vi.fn() }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 7, currentSupplier: m.supplier }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ can: m.can }) }))

import FrameworkConversionView from '../FrameworkConversionView.vue'

const workspaceStub = { name: 'FrameworkConversionWorkspace', props: ['supplier'], template: '<div data-test="workspace" />' }
const routerLinkStub = { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' }

function render() {
  return mount(FrameworkConversionView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: { frameworkConversion: cs.frameworkConversion } } })],
      stubs: { FrameworkConversionWorkspace: workspaceStub, RouterLink: routerLinkStub },
    },
  })
}

beforeEach(() => { m.can.mockReturnValue(true) })

describe('FrameworkConversionView', () => {
  it('SVJ místo formuláře převodu vysvětlí, proč a jak přechází na rámec 504', () => {
    m.supplier = { entity_profile: 'svj', accounting_framework: 'cz_500' }
    const wrapper = render()
    expect(wrapper.find('[data-test="workspace"]').exists()).toBe(false)
    const guide = wrapper.get('[data-section="svj-guide"]')
    expect(guide.text()).toContain(cs.frameworkConversion.svjGuide.title)
    expect(guide.text()).toContain(cs.frameworkConversion.svjGuide.why)
    expect(guide.text()).toContain(cs.frameworkConversion.svj)
    expect(guide.get('[data-test="svj-openings-link"]').attributes('data-to')).toBe('property-openings')
    expect(guide.get('[data-test="svj-settings-link"]').attributes('data-to')).toBe('admin-settings')
  })

  it('nevýdělečné organizaci ukáže formulář řízeného převodu', () => {
    m.supplier = { entity_profile: 'nonprofit', accounting_framework: 'cz_500' }
    const wrapper = render()
    expect(wrapper.find('[data-test="workspace"]').exists()).toBe(true)
    expect(wrapper.find('[data-section="svj-guide"]').exists()).toBe(false)
  })
})
