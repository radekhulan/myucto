import './styles/main.css'
import { captureInitialDomainLogin } from '@/security/domainLoginContext'
import { parseWindowFrameBootstrap } from '@/workspace/frameProtocol'
import { initPwaInstall } from '@/composables/usePwaInstall'
import { installPhoneViewport } from '@/composables/useIsPhone'
import { captureSsoFragment, captureSsoLoginTicket, relaySsoStepUpPopup } from '@/security/oidc'

// Nasazení nové verze uklidí assety předchozích vydání a záložka se starou verzí pak
// chunk nedotáhne. Načte se znovu (nový index.html má nové chunky), nejvýš jednou za 30 s.
window.addEventListener('vite:preloadError', (event) => {
  const key = 'myucto-chunk-reload-at'
  try {
    if (Date.now() - Number(sessionStorage.getItem(key) ?? 0) < 30_000) return
    sessionStorage.setItem(key, String(Date.now()))
  } catch { return }
  event.preventDefault()
  window.location.reload()
})

// Okno ověření citlivé operace: jen předá výsledek dialogu, který ho otevřel,
// a zavře se. Aplikace v něm nestartuje.
const ssoStepUpPopup = relaySsoStepUpPopup(window.location, window.history)
if (ssoStepUpPopup) {
  const lang = (navigator.language || 'cs').toLowerCase().startsWith('cs') ? 'cs' : 'en'
  document.title = 'MyÚčto'
  const note = document.createElement('p')
  note.style.cssText = 'font-family:system-ui,sans-serif;margin:3rem auto;max-width:28rem;text-align:center;color:#374151'
  note.textContent = lang === 'cs'
    ? 'Ověření je hotové, můžete pokračovat v původním okně. Toto okno se zavře samo, případně ho zavřete.'
    : 'Verification is complete, continue in the original window. This window closes by itself, or close it now.'
  document.body.replaceChildren(note)
  window.close()
}

// Atribut data-phone podle šířky okna prohlížeče (i v pracovním panelu), viz useIsPhone.
installPhoneViewport()
captureInitialDomainLogin(window.location)
// Návrat od poskytovatele identity nese výsledek ve fragmentu; před startem
// routeru se přečte a z adresy zmizí.
captureSsoLoginTicket(window.location, window.history)
captureSsoFragment(window.location, window.history)

const frame = parseWindowFrameBootstrap(window.location.search, window.parent !== window)

if (ssoStepUpPopup) {
  // nic dalšího, okno ověření
} else if (frame) {
  const { installWindowSupplierScope } = await import('@/workspace/supplierContext')
  installWindowSupplierScope({ windowId: frame.id, supplierId: frame.supplierId })
  const { startWindowFrame } = await import('@/workspace/windowFrame')
  try { await startWindowFrame(frame) }
  catch {
    window.parent.postMessage({ type: 'myucto-window-error', id: frame.id, code: 'bootstrap-failed' }, window.location.origin)
  }
} else {
  const [
    { createApp },
    { createPinia },
    { router },
    { i18n, ensureInitialLocaleReady },
    { default: App },
    { vMath },
    { vSelectOnFocus },
    { vColumnLabels },
    { setForbiddenPermissionHandler },
    { useAuthStore },
  ] = await Promise.all([
    import('vue'),
    import('pinia'),
    import('./router'),
    import('./i18n'),
    import('./App.vue'),
    import('./directives/vMath'),
    import('./directives/vSelectOnFocus'),
    import('./directives/columnLabels'),
    import('@/api/client'),
    import('@/stores/auth'),
  ])

  await ensureInitialLocaleReady()

  initPwaInstall()

  const app = createApp(App)
  const pinia = createPinia()
  app.use(pinia)
  let permissionRefreshRunning = false
  setForbiddenPermissionHandler(async () => {
    if (permissionRefreshRunning) return
    permissionRefreshRunning = true
    try { await useAuthStore(pinia).refresh() }
    finally { permissionRefreshRunning = false }
  })
  app.use(router)
  app.use(i18n)
  app.directive('math', vMath)
  app.directive('select-on-focus', vSelectOnFocus)
  app.directive('column-labels', vColumnLabels)
  app.mount('#app')

  if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    const registerServiceWorker = async () => {
      const ourScript = new URL('/service-worker.js', window.location.origin).href
      try {
        const registrations = await navigator.serviceWorker.getRegistrations()
        await Promise.all(registrations.map((registration) => {
          const script = registration.active?.scriptURL
            ?? registration.waiting?.scriptURL
            ?? registration.installing?.scriptURL
          return script && script !== ourScript ? registration.unregister() : undefined
        }))

        await navigator.serviceWorker.register('/service-worker.js', {
          scope: '/',
          updateViaCache: 'none',
        })
      } catch (error) {
        console.error('Service worker registration failed:', error)
      }
    }

    if (document.readyState === 'complete') void registerServiceWorker()
    else window.addEventListener('load', registerServiceWorker, { once: true })
  }
}
