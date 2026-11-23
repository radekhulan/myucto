import { describe, it, expect } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import MobileNavSheet from '../MobileNavSheet.vue'

// WorkspaceNavLink táhne pracovní plochu a router; tady stačí vědět, kam vede.
const LinkStub = defineComponent({
  name: 'WorkspaceNavLink',
  props: { to: { type: String, required: true }, fresh: Boolean },
  emits: ['click'],
  setup(props, { slots, emit }) {
    return () => h('a', { href: props.to, 'data-fresh': props.fresh ? '1' : '0', onClick: () => emit('click') }, slots.default?.())
  },
})

function mountSheet() {
  return mount(MobileNavSheet, {
    props: {
      title: 'Nákup',
      accent: 'warning',
      closeLabel: 'Zavřít',
      linksLabel: 'Vše v sekci',
      actions: [
        { key: 'capture', capture: true, label: 'Vyfotit doklad', icon: 'M0 0' },
        { key: 'purchase', to: '/purchase-invoices/new', label: 'Přijatá faktura', icon: 'M0 0' },
      ],
      links: [
        { to: '/purchase-invoices', label: 'Přijaté faktury', icon: 'M0 0', active: true },
        { to: '/clients?role=vendors', label: 'Dodavatelé', icon: 'M0 0', active: false, badge: 2 },
      ],
    },
    global: { stubs: { WorkspaceNavLink: LinkStub } },
  })
}

describe('nabídka agendy na telefonu', () => {
  it('nabídne dlaždice kroků i všechny položky sekce', () => {
    const w = mountSheet()
    expect(w.get('[role="dialog"]').attributes('aria-label')).toBe('Nákup')
    expect(w.get('[data-action="purchase"]').attributes('href')).toBe('/purchase-invoices/new')
    expect(w.get('[data-action="purchase"]').attributes('data-fresh')).toBe('1')
    const links = w.findAll('a').filter(a => !a.attributes('data-action'))
    expect(links.map(a => a.attributes('href'))).toEqual(['/purchase-invoices', '/clients?role=vendors'])
    expect(links[1].text()).toContain('2')
  })

  it('dlaždice fotoaparátu otevře zadní kameru a fotku předá dál', async () => {
    const w = mountSheet()
    const input = w.get('[data-action="capture"] input[type="file"]')
    expect(input.attributes('capture')).toBe('environment')
    expect(input.attributes('accept')).toBe('image/*')
    const photo = new File(['synthetic'], 'snapshot.jpg', { type: 'image/jpeg' })
    Object.defineProperty(input.element, 'files', { value: [photo], configurable: true })
    await input.trigger('change')
    expect(w.emitted('capture')).toEqual([[photo]])
  })

  it('volba položky nebo křížek nabídku zavře', async () => {
    const w = mountSheet()
    await w.get('[data-action="purchase"]').trigger('click')
    await w.get('button[aria-label="Zavřít"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(2)
  })
})
