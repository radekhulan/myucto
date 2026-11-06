import { computed, inject, provide, ref, type ComputedRef, type InjectionKey } from 'vue'

export interface BusyScope { busy: ComputedRef<boolean>; begin: () => void; end: () => void }
const key: InjectionKey<BusyScope> = Symbol('propertyPaymentsBusy')

export function provideBusyScope(): BusyScope {
  const count = ref(0)
  const scope: BusyScope = { busy: computed(() => count.value > 0), begin: () => { count.value++ }, end: () => { count.value = Math.max(0, count.value - 1) } }
  provide(key, scope)
  return scope
}

export function useBusyScope(): BusyScope {
  return inject(key, null) ?? { busy: computed(() => false), begin: () => {}, end: () => {} }
}
