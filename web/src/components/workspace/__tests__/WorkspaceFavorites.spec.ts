import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import WorkspaceFavorites from '@/components/workspace/WorkspaceFavorites.vue'
import { useFavoritesStore } from '@/stores/favorites'
import { useSupplierStore } from '@/stores/supplier'
import { useWorkspaceStore } from '@/stores/workspace'
import type { Favorite } from '@/api/favorites'

const mocks = vi.hoisted(() => ({
  navigate: vi.fn(),
  switchCompany: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
  create: vi.fn(),
  remove: vi.fn(),
  list: vi.fn(),
  rename: vi.fn(),
}))

vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ navigate: mocks.navigate }) }))
vi.mock('@/workspace/windowRegistry', () => ({ getWindowManager: () => ({ switchCompany: mocks.switchCompany }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ warning: mocks.warning, error: mocks.error }) }))
vi.mock('@/utils/bodyScrollLock', () => ({ lockBodyScroll: vi.fn(), unlockBodyScroll: vi.fn() }))
vi.mock('@/api/favorites', () => ({
  favoritesApi: { list: mocks.list, create: mocks.create, remove: mocks.remove, rename: mocks.rename },
}))

const i18n = createI18n({
  legacy: false,
  locale: 'cs',
  messages: {
    cs: {
      common: { close: 'Zavřít' },
      workspace: { homepage: 'Homepage' },
      favorites: {
        title: 'Oblíbené', add_current: 'Přidat aktuální stránku', remove_current: 'Odebrat aktuální stránku',
        limit_reached: 'Limit {max}', empty: 'Nic', company_unavailable: 'Firma nedostupná',
        company_hint: 'Klik otevře stránku v uložené firmě, Shift+klik v aktuální firmě.', keyboard_hint: 'Klávesy',
        rename: 'Přejmenovat', rename_named: 'Přejmenovat {title}', remove: 'Odebrat', remove_named: 'Odebrat {title}',
        save_failed: 'Chyba', record_in_other_company: 'Nejbližší seznam',
      },
    },
  },
})

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/invoices', component: { template: '<div />' } },
    { path: '/invoices/:id', component: { template: '<div />' } },
  ],
})

function favorite(id: number, path: string, supplierId: number | null, title = `Položka ${id}`): Favorite {
  return { id, path, title, supplier_id: supplierId, position: id, created_at: '2026-10-24 10:00:00' }
}

let wrapper: VueWrapper | null = null

async function mountDialog(items: Favorite[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  mocks.list.mockResolvedValue(items)
  const supplier = useSupplierStore()
  supplier.setAvailable([{ id: 1, company_name: 'Alfa s.r.o.' }, { id: 2, company_name: 'Beta s.r.o.' }] as never, 1)
  const workspace = useWorkspaceStore()
  workspace.resetLayout(1, '/invoices/7')
  workspace.updatePane('primary', { fullPath: '/invoices/7', title: 'Faktura 7' })
  const favorites = useFavoritesStore()
  await favorites.load()
  wrapper = mount(WorkspaceFavorites, { global: { plugins: [pinia, i18n, router] } })
  favorites.open = true
  await nextTick()
  await nextTick()
  return { favorites, workspace, supplier }
}

function click(selector: string, init: MouseEventInit = {}): void {
  document.body.querySelector<HTMLElement>(selector)!.dispatchEvent(new MouseEvent('click', { bubbles: true, ...init }))
}

describe('WorkspaceFavorites', () => {
  beforeEach(() => {
    Object.values(mocks).forEach(mock => mock.mockReset())
    mocks.navigate.mockResolvedValue(undefined)
    mocks.switchCompany.mockResolvedValue(undefined)
    Object.defineProperty(Element.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.replaceChildren()
  })

  it('zobrazí firmu položky a nápovědu k Shift+kliku', async () => {
    await mountDialog([favorite(1, '/invoices', 2)])
    expect(document.body.textContent).toContain('Beta s.r.o.')
    expect(document.body.textContent).toContain('Klik otevře stránku v uložené firmě, Shift+klik v aktuální firmě.')
  })

  it('běžný klik přepne na uloženou firmu a otevře stránku', async () => {
    await mountDialog([favorite(1, '/invoices?status=paid', 2)])
    click('[role="option"]')
    await flushPromises()
    expect(mocks.switchCompany).toHaveBeenCalledWith(2, '/invoices?status=paid')
    expect(mocks.navigate).not.toHaveBeenCalled()
  })

  it('klik ve stejné firmě jen naviguje v panelu', async () => {
    await mountDialog([favorite(1, '/invoices', 1)])
    click('[role="option"]')
    await flushPromises()
    expect(mocks.navigate).toHaveBeenCalledWith('/invoices')
    expect(mocks.switchCompany).not.toHaveBeenCalled()
  })

  it('Shift+klik nemění firmu a cestu se záznamem nahradí seznamem s upozorněním', async () => {
    await mountDialog([favorite(1, '/invoices/123', 2)])
    click('[role="option"]', { shiftKey: true })
    await flushPromises()
    expect(mocks.switchCompany).not.toHaveBeenCalled()
    expect(mocks.navigate).toHaveBeenCalledWith('/invoices')
    expect(mocks.warning).toHaveBeenCalledOnce()
  })

  it('Shift+klik na cestu bez záznamu neupozorňuje', async () => {
    await mountDialog([favorite(1, '/invoices?status=paid', 2)])
    click('[role="option"]', { shiftKey: true })
    await flushPromises()
    expect(mocks.navigate).toHaveBeenCalledWith('/invoices?status=paid')
    expect(mocks.warning).not.toHaveBeenCalled()
  })

  it('nedostupná firma se nepřepíná, běžný klik otevře stránku v aktuální firmě', async () => {
    await mountDialog([favorite(1, '/invoices', 99)])
    expect(document.body.textContent).toContain('Firma nedostupná')
    click('[role="option"]')
    await flushPromises()
    expect(mocks.switchCompany).not.toHaveBeenCalled()
    expect(mocks.navigate).toHaveBeenCalledWith('/invoices')
  })

  it('tlačítko přidá aktuální stránku a poté ji odebere', async () => {
    const { favorites } = await mountDialog([])
    mocks.create.mockResolvedValue(favorite(5, '/invoices/7', 1, 'Faktura 7'))
    click('[data-favorite-toggle]')
    await flushPromises()
    expect(mocks.create).toHaveBeenCalledWith({ path: '/invoices/7', title: 'Faktura 7' })
    expect(favorites.items).toHaveLength(1)
    expect(document.body.querySelector('[data-favorite-toggle]')!.textContent).toContain('Odebrat aktuální stránku')

    mocks.remove.mockResolvedValue({ deleted: true })
    click('[data-favorite-toggle]')
    await flushPromises()
    expect(mocks.remove).toHaveBeenCalledWith(5)
    expect(favorites.items).toHaveLength(0)
  })

  it('křížek odebere položku bez jejího otevření', async () => {
    const { favorites } = await mountDialog([favorite(1, '/invoices', 1), favorite(2, '/', 1)])
    mocks.remove.mockResolvedValue({ deleted: true })
    click('[data-remove-favorite]')
    await flushPromises()
    expect(mocks.remove).toHaveBeenCalledWith(1)
    expect(favorites.items.map(item => item.id)).toEqual([2])
    expect(mocks.navigate).not.toHaveBeenCalled()
  })

  it('Escape zavře panel', async () => {
    const { favorites } = await mountDialog([favorite(1, '/invoices', 1)])
    document.body.querySelector('[role="dialog"]')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
    await nextTick()
    expect(favorites.open).toBe(false)
  })
})
