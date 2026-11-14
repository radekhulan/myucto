import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { startWindowFrame } from '@/workspace/windowFrame'
import { installWindowSupplierScope } from '@/workspace/supplierContext'

const mocks = vi.hoisted(() => ({
  ensureLocaleLoaded: vi.fn().mockResolvedValue(undefined),
  putPreferenceKey: vi.fn().mockResolvedValue(undefined),
  getPreferenceKey: vi.fn().mockResolvedValue(null),
}))

vi.mock('@/api/preferences', () => ({ preferencesApi: mocks }))
vi.mock('@/api/client', () => ({ setForbiddenPermissionHandler: vi.fn() }))
vi.mock('@/stores/auth', async () => {
  const { defineStore } = await import('pinia')
  return {
    useAuthStore: defineStore('frame-test-auth', {
      state: () => ({ user: { id: 1 } }),
      actions: { async bootstrapWorkspace() {}, async refresh() {} },
    }),
  }
})
vi.mock('@/router', () => ({
  authorizationGuard: vi.fn(() => true),
  applyAuthorizationMeta: vi.fn(),
}))
vi.mock('@/router/createPaneRouter', async () => {
  const { createRouter, createMemoryHistory } = await import('vue-router')
  const { default: KeyboardShortcuts } = await import('@/pages/KeyboardShortcuts.vue')
  return {
    createPaneRouter: () => createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/shortcuts', component: KeyboardShortcuts }],
    }),
  }
})
vi.mock('@/i18n', async () => {
  const { createI18n } = await import('vue-i18n')
  return {
    ensureInitialLocaleReady: vi.fn().mockResolvedValue(undefined),
    ensureLocaleLoaded: mocks.ensureLocaleLoaded,
    i18n: createI18n({
      legacy: false,
      locale: 'en',
      missingWarn: false,
      fallbackWarn: false,
      messages: { en: { common: { save: 'Save' }, keyboard_shortcuts: { capture_label: 'Shortcut for {action}' } } },
    }),
  }
})
vi.mock('@/components/Toaster.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/WebAuthnCeremonyPrompt.vue', () => ({ default: { render: () => null } }))
vi.mock('@/directives/vMath', () => ({ vMath: {} }))
vi.mock('@/directives/vSelectOnFocus', () => ({ vSelectOnFocus: {} }))
vi.mock('@/directives/columnLabels', () => ({ vColumnLabels: {} }))
vi.mock('@/utils/clientPlatform', () => ({ isApplePlatform: () => false }))

const frame = { id: 'shortcut-settings', supplierId: 1 }
const actions = [
  { id: 'windows.switcher', label: 'Window switcher', group: 'general' },
  { id: 'windows.previous', label: 'Previous window', group: 'general' },
  { id: 'new:/invoices/new', label: 'New invoice', group: 'create', to: '/invoices/new' },
] as const

function parentMessage(type: 'myucto-window-init' | 'myucto-window-state', shortcutActions: readonly unknown[]) {
  window.dispatchEvent(new MessageEvent('message', {
    origin: window.location.origin,
    source: window.parent,
    data: { type, id: frame.id, active: true, theme: 'light', locale: 'en', to: '/shortcuts', shortcutActions },
  }))
}

function binding(label: string): HTMLInputElement {
  const input = document.querySelector<HTMLInputElement>(`input[aria-label="Shortcut for ${label}"]`)
  expect(input).not.toBeNull()
  return input!
}

afterEach(() => {
  const root = document.getElementById('app') as (HTMLElement & { __vue_app__?: { unmount(): void } }) | null
  root?.__vue_app__?.unmount()
  document.body.replaceChildren()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('window frame shortcut settings', () => {
  it('renders parent actions and defaults, accepts updated actions, and saves a changed binding back to the parent', async () => {
    const postMessage = vi.fn()
    vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} unobserve() {} })
    vi.stubGlobal('parent', { postMessage })
    document.body.innerHTML = '<div id="app"></div>'
    installWindowSupplierScope({ windowId: frame.id, supplierId: frame.supplierId })

    const boot = startWindowFrame(frame)
    expect(postMessage).toHaveBeenCalledWith({ type: 'myucto-window-ready', id: frame.id }, window.location.origin)
    parentMessage('myucto-window-init', actions)
    await boot
    await flushPromises()

    expect(binding('Window switcher').value).toBe('Alt + W')
    expect(binding('Previous window').value).toBe('Alt + PageUp')
    expect(binding('New invoice').value).toBe('Alt + 1')
    expect(mocks.getPreferenceKey).toHaveBeenCalledWith('keyboard.shortcuts')

    parentMessage('myucto-window-state', [...actions, { id: 'nav:/clients', label: 'Clients', group: 'menu', to: '/clients' }])
    await nextTick()
    expect(binding('Clients').value).toBe('Alt + K')

    binding('Window switcher').dispatchEvent(new KeyboardEvent('keydown', {
      key: 'g', code: 'KeyG', altKey: true, bubbles: true, cancelable: true,
    }))
    await nextTick()
    expect(binding('Window switcher').value).toBe('Alt + G')
    const save = [...document.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Save')
    expect(save).toBeDefined()
    save!.click()
    await flushPromises()

    expect(mocks.putPreferenceKey).toHaveBeenCalledWith('keyboard.shortcuts', {
      version: 1, overrides: { 'windows.switcher': 'alt+g' },
    })
    expect(postMessage).toHaveBeenCalledWith({ type: 'myucto-window-shortcuts-changed', id: frame.id }, window.location.origin)

    const { i18n } = await import('@/i18n')
    let loaded!: () => void
    mocks.ensureLocaleLoaded.mockImplementationOnce(() => new Promise<void>(resolve => { loaded = resolve }))
    window.dispatchEvent(new MessageEvent('message', {
      origin: window.location.origin, source: window.parent,
      data: { type: 'myucto-window-state', id: frame.id, active: true, theme: 'light', locale: 'cs' },
    }))
    expect(mocks.ensureLocaleLoaded).toHaveBeenCalledWith('cs')
    expect(i18n.global.locale.value).toBe('en')
    loaded()
    await flushPromises()
    expect(i18n.global.locale.value).toBe('cs')
  })
})
