import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installFrameNavigation, installShiftClickNavigation, internalAnchorNavigation } from '../frameNavigation'

describe('navigace uvnitř pracovního okna', () => {
  it.each(['https://public.example.test/payroll?plan=addon#order', 'http://docs.example.test/manual', '//support.example.test/help'])('externí odkaz %s připraví pro nativní otevření mimo pracovní iframe', href => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    const anchor = document.createElement('a')
    anchor.href = href
    anchor.rel = 'nofollow noreferrer'
    const label = document.createElement('span')
    anchor.append(label)
    const event = new MouseEvent('click', { button: 0, cancelable: true })
    Object.defineProperty(event, 'target', { value: label })

    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(anchor.target).toBe('_blank')
    expect(Array.from(anchor.relList)).toEqual(['nofollow', 'noreferrer', 'noopener'])
    expect(event.defaultPrevented).toBe(false)
    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(Array.from(anchor.relList)).toEqual(['nofollow', 'noreferrer', 'noopener'])
  })

  it.each(['_self', '_blank', '_parent', '_top', 'named-window', ''])('zachová explicitní cíl externího odkazu %j', target => {
    const router = createRouter({ history: createMemoryHistory(), routes: [] })
    const anchor = document.createElement('a')
    anchor.href = 'https://public.example.test/order'
    anchor.setAttribute('target', target)
    anchor.rel = 'nofollow'
    const event = new MouseEvent('click', { button: 0, cancelable: true })
    Object.defineProperty(event, 'target', { value: anchor })

    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(anchor.getAttribute('target')).toBe(target)
    expect(anchor.rel).toBe('nofollow')
    expect(event.defaultPrevented).toBe(false)
  })

  it.each([{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }, { button: 2 }])('ponechá modifikované externí kliknutí prohlížeči: %j', options => {
    const router = createRouter({ history: createMemoryHistory(), routes: [] })
    const anchor = document.createElement('a')
    anchor.href = 'https://public.example.test/order'
    const event = new MouseEvent('click', { button: 0, cancelable: true, ...options })
    Object.defineProperty(event, 'target', { value: anchor })

    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(anchor.hasAttribute('target')).toBe(false)
    expect(anchor.hasAttribute('rel')).toBe(false)
    expect(event.defaultPrevented).toBe(false)
  })

  it.each(['/invoices/42?view=detail#items', '#details', 'mailto:synthetic@example.test', 'tel:+420123456789', 'blob:https://public.example.test/synthetic-export'])('nemění cíl interních a newebových odkazů: %s', href => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    const anchor = document.createElement('a')
    anchor.href = href
    const event = new MouseEvent('click', { button: 0, cancelable: true })
    Object.defineProperty(event, 'target', { value: anchor })

    internalAnchorNavigation(router, event, true, true)
    expect(anchor.hasAttribute('target')).toBe(false)
    expect(anchor.hasAttribute('rel')).toBe(false)
    expect(event.defaultPrevented).toBe(false)
  })

  it('vynechá stahování a kliknutí zrušené jiným listenerem', () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [] })
    const anchor = document.createElement('a')
    anchor.href = 'https://public.example.test/export.csv'
    anchor.download = ''
    const event = new MouseEvent('click', { button: 0, cancelable: true })
    Object.defineProperty(event, 'target', { value: anchor })

    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(anchor.hasAttribute('target')).toBe(false)
    anchor.removeAttribute('download')
    event.preventDefault()
    expect(internalAnchorNavigation(router, event, true, true)).toBeNull()
    expect(anchor.hasAttribute('target')).toBe(false)
    expect(anchor.hasAttribute('rel')).toBe(false)
  })

  it('Shift klik přesměruje do pravého panelu i bez aktivního rozdělení', () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/:id', component: { render: () => null } }] })
    const anchor = document.createElement('a')
    anchor.href = '/invoices/42?tab=payments#detail'
    const event = new MouseEvent('click', { button: 0, shiftKey: true })
    Object.defineProperty(event, 'target', { value: anchor })
    expect(internalAnchorNavigation(router, event, false, true)).toEqual({ to: '/invoices/42?tab=payments#detail', placement: 'right' })
    expect(internalAnchorNavigation(router, event, false, false)).toBeNull()
  })

  it('zachytí Shift klik na odkazy menu i obsah, odstraní listener a vynechá odkazy prohlížeče', () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [
      { path: '/invoices/:id', component: { render: () => null }, meta: { requiresAuth: true } },
      { path: '/login', component: { render: () => null } },
    ] })
    const open = vi.fn()
    let supported = true
    const remove = installShiftClickNavigation(router, () => supported, open)
    const anchor = document.createElement('a')
    anchor.href = '/invoices/42'
    const text = document.createElement('span')
    anchor.append(text)
    document.body.append(anchor)
    let preventedBeforeCleanup: boolean | undefined
    anchor.addEventListener('click', event => {
      preventedBeforeCleanup = event.defaultPrevented
      event.preventDefault()
    })
    const click = (options: MouseEventInit = {}) => {
      preventedBeforeCleanup = undefined
      const event = new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: true, ...options })
      text.dispatchEvent(event)
      return { defaultPrevented: preventedBeforeCleanup ?? event.defaultPrevented }
    }
    try {
      expect(click().defaultPrevented).toBe(true)
      expect(open).toHaveBeenCalledExactlyOnceWith('/invoices/42', expect.any(MouseEvent))
      open.mockClear()
      for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { button: 1 }, { shiftKey: false }]) {
        expect(click(options).defaultPrevented).toBe(false)
      }
      supported = false
      expect(click().defaultPrevented).toBe(false)
      supported = true
      anchor.download = 'export.csv'
      expect(click().defaultPrevented).toBe(false)
      anchor.removeAttribute('download')
      anchor.target = '_blank'
      expect(click().defaultPrevented).toBe(false)
      anchor.removeAttribute('target')
      for (const href of ['https://example.com/invoices/42', '/login', '/unknown']) {
        anchor.href = href
        expect(click().defaultPrevented).toBe(false)
      }
      anchor.href = '/invoices/42'
      const prevented = new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: true })
      prevented.preventDefault()
      text.dispatchEvent(prevented)
      expect(open).not.toHaveBeenCalled()
      remove()
      expect(click().defaultPrevented).toBe(false)
      expect(open).not.toHaveBeenCalled()
    } finally {
      remove()
      anchor.remove()
    }
  })
  it('označený odkaz otevře vedle i z posledního panelu; Ctrl klik ponechá původní chování', () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    const anchor = document.createElement('a')
    anchor.href = '/bank/7?tx=42&detail=1'
    anchor.dataset.workspaceOpen = 'beside'
    function click(ctrlKey = false) {
      const event = new MouseEvent('click', { button: 0, ctrlKey })
      Object.defineProperty(event, 'target', { value: anchor })
      return event
    }
    expect(internalAnchorNavigation(router, click(), false)).toEqual({ to: '/bank/7?tx=42&detail=1', placement: 'beside' })
    expect(internalAnchorNavigation(router, click(true), true)).toEqual({ to: '/bank/7?tx=42&detail=1', newPane: true })
    expect(internalAnchorNavigation(router, click(true), false)).toBeNull()
    delete anchor.dataset.workspaceOpen
    expect(internalAnchorNavigation(router, click(), true)).toEqual({ to: '/bank/7?tx=42&detail=1' })
  })
  it('na začátku a konci místní historie předá Zpět a Vpřed správci oken', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push('/projects/new')
    const traverse = vi.fn()
    installFrameNavigation(router, traverse, vi.fn())
    router.back()
    expect(traverse).toHaveBeenLastCalledWith(-1)
    await router.push('/projects/42')
    const back = new Promise<void>(resolve => { const off = router.afterEach(() => { off(); resolve() }) })
    router.back()
    await back
    expect(router.currentRoute.value.path).toBe('/projects/new')
    expect(traverse).toHaveBeenCalledTimes(1)
    const forward = new Promise<void>(resolve => { const off = router.afterEach(() => { off(); resolve() }) })
    router.forward()
    await forward
    expect(router.currentRoute.value.path).toBe('/projects/42')
    router.forward()
    expect(traverse).toHaveBeenLastCalledWith(1)
  })

  it('po úspěšné změně stránky resetuje scroll, změna filtru a zamítnutá navigace ho zachovají', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push('/invoices/new')
    const reset = vi.fn()
    installFrameNavigation(router, vi.fn(), reset)
    await router.push('/invoices/42')
    await nextTick()
    expect(reset).toHaveBeenCalledTimes(1)
    await router.replace('/invoices/42?tab=payments')
    router.beforeEach(to => to.path !== '/blocked')
    await router.push('/blocked')
    await nextTick()
    expect(reset).toHaveBeenCalledTimes(1)
  })
})
