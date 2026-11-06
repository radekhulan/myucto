import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView, useRoute, type Router } from 'vue-router'
import {
  readListNavContext,
  useListNavigation,
  useListNavigationSource,
  type ListNavPage,
} from '../useListNavigation'

type Nav = ReturnType<typeof useListNavigation>

const wrappers: ReturnType<typeof mount>[] = []
beforeEach(() => sessionStorage.clear())
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  document.body.innerHTML = ''
  sessionStorage.clear()
})

function markFromList(ids: number[], openId: number, opts: { page?: number; pages?: number; scope?: number } = {}) {
  let markOpen: (id: number) => void = () => {}
  const List = defineComponent({
    setup() {
      markOpen = useListNavigationSource('invoices', () => ({
        ids,
        page: opts.page ?? 1,
        pages: opts.pages ?? 1,
        params: { status: 'issued', sort_key: 'number', per_page: 3 },
      }), () => '/invoices?status=issued', () => opts.scope ?? 7).markOpen
      return () => h('div')
    },
  })
  const wrapper = mount(List)
  markOpen(openId)
  wrapper.unmount()
}

async function openDetail(id: number, fetchPage = vi.fn<(params: Record<string, unknown>, page: number) => Promise<ListNavPage>>(), scope = 7) {
  let nav!: Nav
  const Detail = defineComponent({
    setup() {
      const route = useRoute()
      nav = useListNavigation({
        kind: 'invoices',
        currentId: () => Number(route.params.id) || 0,
        detailRoute: target => ({ name: 'invoice-detail', params: { id: target } }),
        fetchPage,
        scope: () => scope,
      })
      return () => h('div', [h('input', { 'data-test': 'field' })])
    },
  })
  const router: Router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/invoices', component: { render: () => null } },
      { path: '/invoices/:id(\\d+)', name: 'invoice-detail', component: Detail },
    ],
  })
  await router.push(`/invoices/${id}`)
  await router.isReady()
  const wrapper = mount(defineComponent({ setup: () => () => h(RouterView) }), {
    global: { plugins: [router] },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  await flushPromises()
  return { nav, router, wrapper, fetchPage }
}

function press(key: string, code: string, target: EventTarget = window) {
  target.dispatchEvent(new KeyboardEvent('keydown', { key, code, bubbles: true, cancelable: true }))
}

describe('useListNavigation', () => {
  it('stores the list context only for a row that is in the list', () => {
    markFromList([10, 20, 30], 99)
    expect(readListNavContext('invoices')).toBeNull()
    markFromList([10, 20, 30], 20)
    expect(readListNavContext('invoices')).toMatchObject({
      ids: [10, 20, 30], openedId: 20, listPath: '/invoices?status=issued', scope: '7',
      params: { status: 'issued', sort_key: 'number', per_page: 3 },
    })
  })

  it('hides the arrows for a direct link', async () => {
    const { nav } = await openDetail(20)
    expect(nav.active.value).toBe(false)
  })

  it('hides the arrows when the list opened a different document or company', async () => {
    markFromList([10, 20, 30], 10)
    expect((await openDetail(20)).nav.active.value).toBe(false)
    markFromList([10, 20, 30], 20, { scope: 8 })
    expect((await openDetail(20)).nav.active.value).toBe(false)
  })

  it('walks the loaded rows in list order and disables the arrow at both ends', async () => {
    markFromList([10, 20, 30], 10)
    const { nav, router } = await openDetail(10)
    expect(nav.active.value).toBe(true)
    expect(nav.hasPrevious.value).toBe(false)
    expect(nav.hasNext.value).toBe(true)
    expect(nav.listPath.value).toBe('/invoices?status=issued')

    await nav.next()
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('20')
    expect(nav.active.value).toBe(true)
    expect(nav.hasPrevious.value).toBe(true)

    await nav.next()
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('30')
    expect(nav.hasNext.value).toBe(false)

    await nav.previous()
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('20')
    expect(readListNavContext('invoices')?.currentId).toBe(20)
  })

  it('loads the next page with the same params at the boundary', async () => {
    markFromList([10, 20, 30], 30, { page: 1, pages: 2 })
    const fetchPage = vi.fn<(params: Record<string, unknown>, page: number) => Promise<ListNavPage>>()
      .mockResolvedValue({ ids: [30, 40, 50], pages: 2 })
    const { nav, router } = await openDetail(30, fetchPage)
    expect(nav.hasNext.value).toBe(true)

    await nav.next()
    await flushPromises()
    expect(fetchPage).toHaveBeenCalledTimes(1)
    expect(fetchPage).toHaveBeenCalledWith({ status: 'issued', sort_key: 'number', per_page: 3 }, 2)
    expect(router.currentRoute.value.params.id).toBe('40')
    expect(readListNavContext('invoices')).toMatchObject({ ids: [10, 20, 30, 40, 50], lastPage: 2, currentId: 40 })

    await nav.next()
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('50')
    expect(nav.hasNext.value).toBe(false)
    expect(fetchPage).toHaveBeenCalledTimes(1)
  })

  it('keeps the arrows after a reload of the same detail', async () => {
    markFromList([10, 20, 30], 20)
    const first = await openDetail(20)
    expect(first.nav.active.value).toBe(true)
    first.wrapper.unmount()
    expect((await openDetail(20)).nav.active.value).toBe(true)
  })

  it('navigates with [ and ] but not while typing into a field', async () => {
    markFromList([10, 20, 30], 20)
    const { router, wrapper } = await openDetail(20)

    press(']', 'BracketRight')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('30')

    const input = wrapper.get('[data-test="field"]').element as HTMLInputElement
    input.focus()
    press('[', 'BracketLeft', input)
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('30')

    input.blur()
    press('ú', 'BracketLeft')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('20')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: ']', code: 'BracketRight', ctrlKey: true }))
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('20')
  })
})
