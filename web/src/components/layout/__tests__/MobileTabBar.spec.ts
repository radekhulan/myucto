import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MobileTabBar, { type MobileTab } from '../MobileTabBar.vue'

const tabs: MobileTab[] = [
  { key: 'home', label: 'Přehled', icon: 'M0 0', current: true, open: false },
  { key: 'sales', label: 'Prodej', icon: 'M0 0', current: false, open: true },
  { key: 'purchase', label: 'Nákup', icon: 'M0 0', current: false, open: false, badge: 3 },
  { key: 'menu', label: 'Menu', icon: 'M0 0', current: false, open: false },
]

describe('spodní lišta telefonu', () => {
  it('vykreslí záložky se stavem aktuální agendy a otevřené nabídky', () => {
    const w = mount(MobileTabBar, { props: { tabs, label: 'Hlavní menu' } })
    const buttons = w.findAll('button')
    expect(buttons.map(b => b.text())).toEqual(['Přehled', 'Prodej', '3Nákup', 'Menu'])
    expect(buttons[0].attributes('aria-current')).toBe('page')
    expect(buttons[0].attributes('aria-expanded')).toBeUndefined()
    expect(buttons[1].attributes('aria-expanded')).toBe('true')
    expect(buttons[3].attributes('aria-expanded')).toBe('false')
    expect(w.get('nav').attributes('aria-label')).toBe('Hlavní menu')
  })

  it('klepnutí jen ohlásí záložku, otevírání řídí layout', async () => {
    const w = mount(MobileTabBar, { props: { tabs, label: 'Hlavní menu' } })
    await w.get('[data-tab="purchase"]').trigger('click')
    await w.get('[data-tab="menu"]').trigger('click')
    expect(w.emitted('select')).toEqual([['purchase'], ['menu']])
  })
})
