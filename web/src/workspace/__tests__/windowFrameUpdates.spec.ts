import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import type { Router } from 'vue-router'
import type { WindowContext } from '@/workspace/windowContext'
import { startWindowFrame } from '@/workspace/windowFrame'

const mocks = vi.hoisted(() => ({
  router: undefined as Router | undefined,
  context: undefined as WindowContext | undefined,
  refresh: vi.fn().mockResolvedValue(undefined),
  mounts: 0,
  setSessionCsrfToken: vi.fn(),
  registerActions: vi.fn(),
  ensureLocaleLoaded: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('@/api/client', () => ({ setForbiddenPermissionHandler: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  refresh: mocks.refresh, setSessionCsrfToken: mocks.setSessionCsrfToken,
}) }))
vi.mock('@/router', () => ({ authorizationGuard: vi.fn(() => true), applyAuthorizationMeta: vi.fn() }))
vi.mock('@/router/createPaneRouter', async () => {
  const { createRouter, createMemoryHistory } = await import('vue-router')
  const { defineComponent, h, inject } = await import('vue')
  const { windowContextKey } = await import('@/workspace/windowContext')
  const Report = defineComponent({
    setup() {
      mocks.context = inject(windowContextKey)
      mocks.mounts++
      return () => h('section', [h('h1', 'Report'), h('div', { 'data-content': '' })])
    },
  })
  return { createPaneRouter: () => {
    mocks.router = createRouter({ history: createMemoryHistory(), routes: [
      { path: '/report', component: Report }, { path: '/other', component: Report }, { path: '/accounting/journal', component: Report },
    ] })
    return mocks.router
  } }
})
vi.mock('@/i18n', async () => {
  const { createI18n } = await import('vue-i18n')
  return {
    ensureInitialLocaleReady: vi.fn().mockResolvedValue(undefined),
    ensureLocaleLoaded: mocks.ensureLocaleLoaded,
    i18n: createI18n({ legacy: false, locale: 'en', messages: { en: {}, cs: {} } }),
  }
})
vi.mock('@/composables/useKeyboardShortcuts', () => ({
  shortcutFromEvent: vi.fn(),
  useKeyboardShortcuts: () => ({ registerActions: mocks.registerActions }),
}))
vi.mock('@/components/Toaster.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/WebAuthnCeremonyPrompt.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/TableViewportScrollbars.vue', () => ({ default: { render: () => null } }))
vi.mock('@/directives/vMath', () => ({ vMath: {} }))
vi.mock('@/directives/vSelectOnFocus', () => ({ vSelectOnFocus: {} }))
vi.mock('@/directives/columnLabels', () => ({ vColumnLabels: {} }))

const frame = { id: 'frame-updates', supplierId: 1 }
const cleanups: (() => void)[] = []

function message(type: 'myucto-window-init' | 'myucto-window-state', extra: Record<string, unknown> = {}) {
  window.dispatchEvent(new MessageEvent('message', {
    origin: window.location.origin, source: window.parent,
    data: { type, id: frame.id, active: true, theme: 'light', locale: 'en', to: '/report', ...extra },
  }))
}

async function boot() {
  // Frame listeners normally live for the lifetime of its document.
  const add = window.addEventListener.bind(window)
  vi.spyOn(window, 'addEventListener').mockImplementation((type, listener, options) => {
    add(type, listener, options)
    cleanups.push(() => window.removeEventListener(type, listener, options))
  })
  const postMessage = vi.fn()
  vi.stubGlobal('parent', { postMessage, getComputedStyle: window.getComputedStyle.bind(window) })
  document.body.innerHTML = '<div id="app"></div>'
  const started = startWindowFrame(frame)
  message('myucto-window-init')
  await started
  await flushPromises()
  return postMessage
}

afterEach(async () => {
  const root = document.getElementById('app') as (HTMLElement & { __vue_app__?: { unmount(): void } }) | null
  root?.__vue_app__?.unmount()
  await flushPromises()
  cleanups.splice(0).forEach(cleanup => cleanup())
  document.body.replaceChildren()
  document.documentElement.className = ''
  document.documentElement.removeAttribute('lang')
  document.body.className = ''
  delete document.body.dataset.accent
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('window frame updates', () => {
  it('připraví externí odkaz již v capture fázi skutečného pracovního iframe', async () => {
    const post = await boot()
    post.mockClear()
    const anchor = document.createElement('a')
    anchor.href = 'https://public.example.test/payroll/order'
    anchor.rel = 'nofollow'
    const label = document.createElement('span')
    label.textContent = 'Synthetic external order'
    anchor.append(label)
    document.querySelector('[data-content]')!.append(anchor)
    let observed: { target: string; rel: string; defaultPrevented: boolean } | undefined
    anchor.addEventListener('click', event => {
      observed = { target: anchor.target, rel: anchor.rel, defaultPrevented: event.defaultPrevented }
      event.preventDefault()
    })

    label.dispatchEvent(new MouseEvent('click', { button: 0, bubbles: true, cancelable: true }))

    expect(observed).toEqual({ target: '_blank', rel: 'nofollow noopener', defaultPrevented: false })
    expect(mocks.router!.currentRoute.value.fullPath).toBe('/report')
    expect(post.mock.calls.some(([message]) => message.type === 'myucto-window-navigate')).toBe(false)
  })

  it('rebuilds a data view that became stale and offers a reload on a route that may hold work', async () => {
    await boot()
    message('myucto-window-state', { paneId: 'primary' })
    await flushPromises()
    expect(document.querySelector('[role="status"]')).toBeNull()

    message('myucto-window-state', { invalidation: { supplierId: 1, resource: 'invoices' } })
    await flushPromises()
    expect(document.querySelector('[role="status"]')).not.toBeNull()

    await mocks.router!.push('/accounting/journal')
    await flushPromises()
    expect(document.querySelector('[role="status"]')).toBeNull()
    const mounted = mocks.mounts
    message('myucto-window-state', { invalidation: { supplierId: 2, resource: 'invoices' } })
    await flushPromises()
    expect(mocks.mounts).toBe(mounted)
    message('myucto-window-state', { invalidation: { supplierId: 1, resource: 'invoices' } })
    await flushPromises()
    expect(mocks.mounts).toBe(mounted + 1)
    expect(document.querySelector('[role="status"]')).toBeNull()
  })

  it('ignores unrelated DOM mutations but still reports heading, custom title, subtitle and route changes', async () => {
    const post = await boot()
    const routes = () => post.mock.calls.map(([data]) => data).filter(data => data.type === 'myucto-window-route')
    expect(routes().at(-1)).toMatchObject({ fullPath: '/report', title: 'Report', subtitle: null })
    post.mockClear()

    const content = document.querySelector('[data-content]')!
    content.append(document.createComment('teleport start'), document.createComment('teleport end'))
    await flushPromises()
    content.replaceChildren(document.createElement('table'))
    await flushPromises()
    expect(routes()).toHaveLength(0)

    document.querySelector('h1')!.textContent = 'Updated report'
    await flushPromises()
    expect(routes()).toHaveLength(1)
    expect(routes().at(-1)).toMatchObject({ title: 'Updated report' })
    mocks.context!.setTitle!('Custom report', 'Period')
    await flushPromises()
    mocks.context!.setTitle!('Custom report', 'Period')
    await flushPromises()
    expect(routes()).toHaveLength(2)
    mocks.context!.setTitle!('Custom report', 'Another period')
    await flushPromises()
    expect(routes()).toHaveLength(3)
    expect(routes().at(-1)).toMatchObject({ title: 'Custom report', subtitle: 'Another period' })
    mocks.context!.setTitle!(null)
    await flushPromises()
    expect(routes().at(-1)).toMatchObject({ title: 'Updated report', subtitle: null })

    await mocks.router!.push('/report?period=2')
    await flushPromises()
    expect(routes().at(-1)).toMatchObject({ fullPath: '/report?period=2' })
    const count = routes().length
    await mocks.router!.push('/other')
    await flushPromises()
    expect(routes()).toHaveLength(count + 1)
    expect(routes().at(-1)).toMatchObject({ fullPath: '/other' })
  })

  it('avoids unchanged appearance writes while preserving theme, locale, layout and state updates', async () => {
    await boot()
    const { i18n } = await import('@/i18n')
    const observer = new MutationObserver(() => {})
    observer.observe(document.documentElement, { attributes: true, subtree: true, attributeOldValue: true })
    try {
      message('myucto-window-state')
      expect(observer.takeRecords()).toHaveLength(0)

      message('myucto-window-state', { theme: 'dark', locale: 'cs', accent: 'teal', singlePanel: true })
      await flushPromises()
      expect(document.documentElement.classList.contains('dark')).toBe(true)
      expect(document.documentElement.lang).toBe('cs')
      expect(i18n.global.locale.value).toBe('cs')
      expect(document.body.dataset.accent).toBe('teal')
      expect(document.body.classList.contains('workspace-window-single')).toBe(true)
      expect(document.body.classList.contains('workspace-window-single-padded')).toBe(true)
      expect(mocks.ensureLocaleLoaded).toHaveBeenCalledWith('cs')

      const shellMain = document.createElement('main')
      const iframe = document.createElement('div')
      shellMain.append(iframe)
      document.body.append(shellMain)
      shellMain.style.paddingTop = '8px'
      vi.stubGlobal('frameElement', iframe)
      message('myucto-window-state', { theme: 'dark', locale: 'cs', accent: 'teal', singlePanel: true })
      await flushPromises()
      expect(document.body.classList.contains('workspace-window-single-padded')).toBe(false)
      shellMain.style.paddingTop = '0px'
      message('myucto-window-state', { theme: 'dark', locale: 'cs', accent: 'teal', singlePanel: true })
      await flushPromises()
      expect(document.body.classList.contains('workspace-window-single-padded')).toBe(true)

      // Hláška nad oknem (údržba) se zobrazí a zavře křížkem bez nové zprávy o vzhledu.
      const banner = document.createElement('div')
      shellMain.style.paddingTop = '24px'
      shellMain.prepend(banner)
      await flushPromises()
      expect(document.body.classList.contains('workspace-window-single-padded')).toBe(false)
      banner.remove()
      shellMain.style.paddingTop = '0px'
      await flushPromises()
      expect(document.body.classList.contains('workspace-window-single-padded')).toBe(true)

      const changed = vi.fn()
      window.addEventListener('myucto:window-data-changed', changed, { once: true })
      const refreshes = mocks.refresh.mock.calls.length
      message('myucto-window-state', { active: false, paneId: null, refreshAuth: true,
        csrfToken: 'synthetic-token', invalidation: { supplierId: 1, resource: 'invoices' } })
      await flushPromises()
      expect(mocks.context!.active.value).toBe(false)
      expect(mocks.context!.paneId.value).toBeNull()
      expect(mocks.setSessionCsrfToken).toHaveBeenCalledWith('synthetic-token')
      expect(mocks.refresh).toHaveBeenCalledTimes(refreshes + 1)
      expect(changed).toHaveBeenCalledOnce()
      expect(document.documentElement.classList.contains('dark')).toBe(false)
      expect(document.body.classList.contains('workspace-window-single')).toBe(false)
      expect(document.documentElement.lang).toBe('en')
    } finally {
      observer.disconnect()
    }
  })
})
