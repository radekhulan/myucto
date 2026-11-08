import { computed, createApp, defineComponent, h, nextTick, provide, ref, Fragment } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { RouterView, type RouteLocationRaw } from 'vue-router'
import { i18n, ensureInitialLocaleReady, ensureLocaleLoaded } from '@/i18n'
import { vMath } from '@/directives/vMath'
import { vSelectOnFocus } from '@/directives/vSelectOnFocus'
import { vColumnLabels } from '@/directives/columnLabels'
import { useAuthStore } from '@/stores/auth'
import { authorizationGuard, applyAuthorizationMeta } from '@/router'
import { sanitizeMenuItems, sanitizeOpenTabs, setOpenTabSelectHandler, workspaceMenuItems, workspaceOpenTabs } from './newTabData'
import { createPaneRouter } from '@/router/createPaneRouter'
import { setForbiddenPermissionHandler } from '@/api/client'
import { shortcutFromEvent, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { paneCountFromShortcut, panelIndexFromShortcut } from '@/workspace/panelShortcuts'
import { installFrameNavigation, internalAnchorNavigation } from '@/workspace/frameNavigation'
import { windowContextKey } from '@/workspace/windowContext'
import { installWindowDataRefresh } from '@/workspace/windowDataRefresh'
import WindowStaleNotice from '@/components/workspace/WindowStaleNotice.vue'
import Toaster from '@/components/Toaster.vue'
import WebAuthnCeremonyPrompt from '@/components/WebAuthnCeremonyPrompt.vue'
import TableViewportScrollbars from '@/components/ui/TableViewportScrollbars.vue'
import { internalRouteFromAnchor } from '@/workspace/panelLinks'
import {
  isWindowFrameParentMessage,
  postWindowFrameMessage,
  type WindowFrameBootstrap,
  type WindowFrameInitMessage,
  type WindowFrameLocale,
  type WindowFramePaneId,
  type WindowFrameParentMessage,
  type WindowFrameTheme,
} from '@/workspace/frameProtocol'

let appearanceSequence = 0

function toggleClass(element: HTMLElement, name: string, enabled = true): void {
  if (element.classList.contains(name) !== enabled) element.classList.toggle(name, enabled)
}

let singlePanelState = false
let observedMain: Element | null = null
let mainObserver: MutationObserver | null = null

/*
 * Horní odsazení jediného okna: hlavní plocha shellu ruší vlastní padding, když
 * v ní je jen okno (main.css, `:has(> .workspace-host:only-child)`). Hláška nad
 * oknem (údržba, licence, úložiště) ale může zmizet i bez nové zprávy o vzhledu,
 * třeba zavřením křížkem. Proto se stav přepočítá i při změně obsahu shellu,
 * jinak by obsah okna vyjel až k menu.
 */
function syncSinglePadding(): void {
  const shellMain = window.frameElement?.closest('main') ?? null
  toggleClass(document.body, 'workspace-window-single-padded', singlePanelState
    && (!shellMain || window.parent.getComputedStyle(shellMain).paddingTop === '0px'))
  if (shellMain === observedMain) return
  mainObserver?.disconnect()
  mainObserver = null
  observedMain = shellMain
  if (shellMain) {
    const Observer = (window.parent as typeof window).MutationObserver ?? MutationObserver
    mainObserver = new Observer(() => syncSinglePadding())
    mainObserver.observe(shellMain, { childList: true, attributes: true, attributeFilter: ['class', 'style'] })
  }
}

async function applyAppearance(theme: WindowFrameTheme, locale: WindowFrameLocale, accent = 'primary', singlePanel = false): Promise<void> {
  const sequence = ++appearanceSequence
  toggleClass(document.documentElement, 'dark', theme === 'dark')
  toggleClass(document.documentElement, 'workspace-window-frame')
  toggleClass(document.body, 'workspace-window-frame')
  toggleClass(document.body, 'workspace-window-single', singlePanel)
  singlePanelState = singlePanel
  syncSinglePadding()
  if (document.body.dataset.accent !== accent) document.body.dataset.accent = accent
  if (i18n.global.locale.value !== locale) await ensureLocaleLoaded(locale)
  if (sequence !== appearanceSequence) return
  if (document.documentElement.lang !== locale) document.documentElement.lang = locale
  i18n.global.locale.value = locale
}

function keyboardPayload(event: KeyboardEvent) {
  return {
    key: event.key,
    code: event.code,
    altKey: event.altKey,
    ctrlKey: event.ctrlKey,
    metaKey: event.metaKey,
    shiftKey: event.shiftKey,
    isComposing: event.isComposing,
    repeat: event.repeat,
    editable: event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)),
  }
}

async function initialMessage(frame: WindowFrameBootstrap): Promise<WindowFrameInitMessage> {
  return await new Promise((resolve) => {
    const receive = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin || event.source !== window.parent) return
      if (!isWindowFrameParentMessage(event.data, frame.id) || event.data.type !== 'myucto-window-init') return
      window.removeEventListener('message', receive)
      resolve(event.data)
    }
    window.addEventListener('message', receive)
    postWindowFrameMessage({ type: 'myucto-window-ready', id: frame.id })
  })
}

export async function startWindowFrame(frame: WindowFrameBootstrap): Promise<void> {
  const init = await initialMessage(frame)
  await ensureInitialLocaleReady()
  await applyAppearance(init.theme, init.locale, init.accent, init.singlePanel)

  const active = ref(init.active)
  const paneId = ref<WindowFramePaneId | null>(init.paneId ?? null)
  const canOpenRight = ref(init.canOpenRight === true)
  const canOpenRightWithSplit = ref(init.canOpenRightWithSplit === true)
  const globalShortcuts = ref(new Set(init.globalShortcuts ?? []))
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore(pinia)
  let refreshingPermissions = false
  setForbiddenPermissionHandler(async () => {
    if (refreshingPermissions) return
    refreshingPermissions = true
    try { await auth.refresh() }
    finally { refreshingPermissions = false }
  })

  await auth.refresh()
  if (init.csrfToken) auth.setSessionCsrfToken(init.csrfToken)
  const shortcuts = useKeyboardShortcuts()
  shortcuts.registerActions(init.shortcutActions ?? [])
  if (init.menuItems) workspaceMenuItems.value = sanitizeMenuItems(init.menuItems)
  if (init.openTabs) workspaceOpenTabs.value = sanitizeOpenTabs(init.openTabs)
  setOpenTabSelectHandler(windowId => postWindowFrameMessage({ type: 'myucto-window-select', id: frame.id, windowId }))
  const globalNavigation = (to: RouteLocationRaw) => {
    postWindowFrameMessage({ type: 'myucto-window-global', id: frame.id, to })
  }
  const router = createPaneRouter({
    prepareRoutes: applyAuthorizationMeta,
    guard: (to, from) => authorizationGuard(to, from, {
      allowGlobalSideEffects: false,
      onGlobalNavigation: globalNavigation,
    }),
    onGlobalNavigation: globalNavigation,
  })

  let suppliedTitle = ''
  let suppliedSubtitle: string | null = null
  let routeMessageQueued = false
  let lastRouteMessage: { fullPath: string; title: string; subtitle: string | null } | undefined
  const viewKey = ref(0)
  const dataRefresh = installWindowDataRefresh({
    path: () => router.currentRoute.value.path,
    visible: computed(() => paneId.value !== null),
    remount: () => { viewKey.value++ },
  })
  const mountElement = document.getElementById('app')
  if (!mountElement) throw new Error('Missing window frame mount element.')
  const root: HTMLElement = mountElement
  root.style.containerName = 'workspace'
  root.style.containerType = 'inline-size'

  function sendRoute(): void {
    routeMessageQueued = false
    const route = router.currentRoute.value
    const heading = root.querySelector('h1')?.textContent?.trim()
    const message = { fullPath: route.fullPath, title: suppliedTitle || heading || route.fullPath, subtitle: suppliedSubtitle }
    // Teleport anchors and report rows also trigger the title observer. Sending
    // unchanged metadata makes the shell unnecessarily reapply the frame state.
    if (lastRouteMessage?.fullPath === message.fullPath && lastRouteMessage.title === message.title
      && lastRouteMessage.subtitle === message.subtitle) return
    lastRouteMessage = message
    postWindowFrameMessage({
      type: 'myucto-window-route',
      id: frame.id,
      ...message,
    })
  }

  function queueRouteMessage(): void {
    if (routeMessageQueued) return
    routeMessageQueued = true
    void nextTick(sendRoute)
  }

  const FrameRoot = defineComponent({
    name: 'WorkspaceWindowFrame',
    setup() {
      const context = {
        paneId: computed(() => active.value ? paneId.value : null),
        active: computed(() => active.value),
        visible: computed(() => paneId.value !== null),
        root,
        portal: document.body,
        navigate: async (to: RouteLocationRaw) => {
          postWindowFrameMessage({ type: 'myucto-window-navigate', id: frame.id, to })
        },
        setTitle: (title: string | null, subtitle?: string | null) => {
          suppliedTitle = title ?? ''
          suppliedSubtitle = subtitle ?? null
          queueRouteMessage()
        },
      }
      provide(windowContextKey, context)
      return () => h(Fragment, [h(RouterView, { key: viewKey.value }), dataRefresh.showNotice.value ? h(WindowStaleNotice, { onReload: dataRefresh.reload }) : null, h(Toaster), h(WebAuthnCeremonyPrompt), h(TableViewportScrollbars)])
    },
  })

  router.afterEach((_to, from, failure) => {
    if (failure) return
    if (_to.path !== from.path) {
      suppliedTitle = ''
      suppliedSubtitle = null
      dataRefresh.routeChanged()
    }
    queueRouteMessage()
  })
  await router.push(init.to)
  await router.isReady()
  installFrameNavigation(router, direction => {
    postWindowFrameMessage({ type: 'myucto-window-history', id: frame.id, direction })
  }, () => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }))

  const app = createApp(FrameRoot)
  app.config.idPrefix = `${frame.id}-`
  app.use(pinia)
  app.use(router)
  app.use(i18n)
  app.directive('math', vMath)
  app.directive('select-on-focus', vSelectOnFocus)
  app.directive('column-labels', vColumnLabels)
  app.mount(root)

  const titleObserver = new MutationObserver(queueRouteMessage)
  titleObserver.observe(root, { childList: true, subtree: true, characterData: true })
  queueRouteMessage()

  window.addEventListener('message', (event: MessageEvent<unknown>) => {
    if (event.origin !== window.location.origin || event.source !== window.parent) return
    if (!isWindowFrameParentMessage(event.data, frame.id) || event.data.type !== 'myucto-window-state') return
    const state: WindowFrameParentMessage = event.data
    active.value = state.active
    if (state.paneId !== undefined) paneId.value = state.paneId
    if (state.canOpenRightWithSplit !== undefined) canOpenRightWithSplit.value = state.canOpenRightWithSplit
    if (state.canOpenRight !== undefined) canOpenRight.value = state.canOpenRight
    void applyAppearance(state.theme, state.locale, state.accent, state.singlePanel).catch(() => {
      postWindowFrameMessage({ type: 'myucto-window-error', id: frame.id, code: 'locale-failed' })
    })
    if (state.globalShortcuts) globalShortcuts.value = new Set(state.globalShortcuts)
    if (state.shortcutActions) shortcuts.registerActions(state.shortcutActions)
    if (state.menuItems) workspaceMenuItems.value = sanitizeMenuItems(state.menuItems)
    if (state.openTabs) workspaceOpenTabs.value = sanitizeOpenTabs(state.openTabs)
    if (state.csrfToken) auth.setSessionCsrfToken(state.csrfToken)
    if (state.invalidation?.supplierId === frame.supplierId) window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: state.invalidation }))
    if (state.refreshAuth) void auth.refresh()
  })

  window.addEventListener('keydown', (event) => {
    if (event.isComposing || event.repeat || event.getModifierState?.('AltGraph')) return
    if (event.target instanceof Element && event.target.closest('[data-shortcut-capture]')) return
    if ([...document.querySelectorAll('[role="dialog"][aria-modal="true"], dialog[open]')]
      .some(dialog => !dialog.closest('[hidden], [aria-hidden="true"]'))) return
    const shortcut = shortcutFromEvent(event)
    const panelShortcut = panelIndexFromShortcut(event) !== null || paneCountFromShortcut(event) !== null
    if (!panelShortcut && shortcut !== 'ctrl+k' && !globalShortcuts.value.has(shortcut)) return
    event.preventDefault()
    event.stopImmediatePropagation()
    postWindowFrameMessage({ type: 'myucto-window-key', id: frame.id, event: keyboardPayload(event) })
  }, true)

  document.addEventListener('pointerdown', () => {
    postWindowFrameMessage({ type: 'myucto-window-activate', id: frame.id })
  }, true)

  let lastActivity = 0
  for (const type of ['pointerdown', 'keydown', 'beforeinput', 'input', 'wheel', 'touchstart']) {
    document.addEventListener(type, event => {
      if (!event.isTrusted || document.visibilityState !== 'visible' || Date.now() - lastActivity < 1000) return
      lastActivity = Date.now()
      postWindowFrameMessage({ type: 'myucto-window-activity', id: frame.id })
    }, { capture: true, passive: true })
  }

  document.addEventListener('click', (event) => {
    const navigation = internalAnchorNavigation(router, event, canOpenRight.value, canOpenRightWithSplit.value)
    if (!navigation) return
    event.preventDefault()
    event.stopImmediatePropagation()
    if (!navigation.newPane && !navigation.placement && router.resolve(navigation.to).path === router.currentRoute.value.path) void router.push(navigation.to)
    else postWindowFrameMessage({ type: 'myucto-window-navigate', id: frame.id, ...navigation })
  }, true)

  document.addEventListener('dragover', event => {
    const types = Array.from(event.dataTransfer?.types ?? [])
    if (!types.includes('Files') && (types.includes('application/x-myucto-route') || types.includes('text/uri-list'))) event.preventDefault()
  })
  document.addEventListener('drop', event => {
    if (Array.from(event.dataTransfer?.types ?? []).includes('Files')) return
    const raw = event.dataTransfer?.getData('application/x-myucto-route')
      || event.dataTransfer?.getData('text/uri-list').split(/\r?\n/).find(line => line && !line.startsWith('#'))
    if (!raw) return
    const anchor = document.createElement('a')
    anchor.href = raw
    const to = internalRouteFromAnchor(anchor)
    if (!to || !router.resolve(to).matched.length) return
    event.preventDefault()
    event.stopImmediatePropagation()
    postWindowFrameMessage({ type: 'myucto-window-navigate', id: frame.id, to })
  }, true)
}
