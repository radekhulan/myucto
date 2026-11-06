import { config, enableAutoUnmount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { afterEach } from 'vitest'
import { BroadcastChannel as NodeBroadcastChannel } from 'node:worker_threads'

enableAutoUnmount(afterEach)

// jsdom BroadcastChannel nemá; ověření účtem organizace přes něj předává výsledek
// z okna ověření (prohlížeče ho mají všechny). Node implementace se chová stejně.
if (typeof globalThis.BroadcastChannel === 'undefined') {
  Object.defineProperty(globalThis, 'BroadcastChannel', { configurable: true, writable: true, value: NodeBroadcastChannel })
  if (globalThis !== window) Object.defineProperty(window, 'BroadcastChannel', { configurable: true, writable: true, value: NodeBroadcastChannel })
}

for (const name of ['confirm', 'prompt'] as const) {
  if (typeof window[name] === 'function') continue
  Object.defineProperty(window, name, {
    configurable: true,
    writable: true,
    value: () => { throw new Error(`Test musí nastavit odpověď na window.${name}().`) },
  })
  if (globalThis !== window) {
    Object.defineProperty(globalThis, name, {
      configurable: true,
      get: () => window[name],
      set: value => { window[name] = value },
    })
  }
}

// Component tests mount pages without installing a router. Keep links visible
// and let individual tests override this stub when they inspect route objects.
config.global.components.RouterLink = defineComponent({
  name: 'RouterLink',
  props: ['to'],
  template: '<a class="router-link" :href="typeof to === \'string\' ? to : to.path" :data-to="JSON.stringify(to)"><slot /></a>',
})
