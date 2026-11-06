import { computed, onBeforeUnmount, ref, shallowRef, toRaw } from 'vue'
import { errorCode, errorMessage, errorStatus } from './membershipFormat'

/** Stavy, u kterých server požadavek jednoznačně odmítl; cokoli jiného je nejistý výsledek. */
const DEFINITE = [400, 401, 403, 404, 409, 422]

export interface FrozenCommit<P> { supplier: number; payload: P }

interface Options<P> {
  supplier: () => number
  canManage: () => boolean
  send: (supplier: number, payload: P) => Promise<unknown>
  onDone: (result: unknown) => void | Promise<void>
}

/**
 * Společný životní cyklus zápisu: náhled -> zmrazený požadavek -> potvrzení.
 *
 * Potvrzení vždy posílá zmrazený požadavek (včetně idempotentního klíče), nikdy živý formulář.
 * Změna vstupu (`touch`) náhled i zmrazený požadavek zahodí, ale jen dokud není potvrzení
 * rozpracované nebo nejisté. Po nejistém výsledku zůstává stejný požadavek znovu odeslatelný.
 */
export function useGuardedCommit<P extends object, R = unknown>(options: Options<P>) {
  const review = shallowRef<R | null>(null)
  const frozen = shallowRef<FrozenCommit<P> | null>(null)
  const acknowledged = ref(false)
  const previewing = ref(false)
  const confirming = ref(false)
  const uncertain = ref(false)
  const error = ref('')
  const errorKind = ref('')
  let alive = true
  let generation = 0
  let inputRevision = 0

  const locked = computed(() => confirming.value || uncertain.value)
  const busy = computed(() => previewing.value || confirming.value)

  function clear() {
    review.value = null
    frozen.value = null
    acknowledged.value = false
    uncertain.value = false
  }

  /** Vstup formuláře se změnil: platí jen mimo odesílání a nejistý výsledek. */
  function touch() {
    if (locked.value) return
    inputRevision++
    generation++
    previewing.value = false
    clear()
  }

  function begin() {
    if (locked.value || previewing.value) return null
    const ticket = ++generation
    const revision = inputRevision
    const supplier = options.supplier()
    previewing.value = true
    error.value = ''
    errorKind.value = ''
    clear()
    return {
      supplier,
      current: () => alive && ticket === generation && revision === inputRevision && supplier === options.supplier(),
      end: () => { if (ticket === generation) previewing.value = false },
    }
  }

  function arm(result: R, payload: P, supplier: number) {
    review.value = result
    frozen.value = { supplier, payload: structuredClone(toRaw(payload)) as P }
  }

  function fail(e: unknown) {
    error.value = errorMessage(e)
    errorKind.value = errorCode(e) || 'unknown'
  }

  async function send() {
    const request = frozen.value
    if (!request || !acknowledged.value || busy.value || !options.canManage() || request.supplier !== options.supplier()) return
    const ticket = ++generation
    confirming.value = true
    error.value = ''
    errorKind.value = ''
    let result: unknown
    try {
      result = await options.send(request.supplier, structuredClone(toRaw(request.payload)) as P)
    } catch (e) {
      if (!alive || ticket !== generation) return
      confirming.value = false
      const notSent = e instanceof Error && e.message === 'nonprofit_membership_supplier_changed'
      if (notSent || DEFINITE.includes(errorStatus(e))) {
        clear()
        fail(e)
        if (notSent) errorKind.value = 'supplier_changed'
      } else {
        uncertain.value = true
        errorKind.value = 'uncertain'
      }
      return
    }
    if (!alive || ticket !== generation || request.supplier !== options.supplier()) return
    clear()
    confirming.value = false
    await options.onDone(result)
  }

  /** Vědomé zahození nejistého požadavku; nový náhled dostane nový idempotentní klíč. */
  function discard() {
    if (confirming.value) return
    generation++
    inputRevision++
    clear()
    error.value = ''
    errorKind.value = ''
  }

  /** Změna organizace: nic z předchozí organizace se už nesmí odeslat ani zobrazit. */
  function reset() {
    generation++
    inputRevision++
    previewing.value = false
    confirming.value = false
    clear()
    error.value = ''
    errorKind.value = ''
  }

  onBeforeUnmount(() => { alive = false; generation++ })

  return { review, frozen, acknowledged, previewing, confirming, uncertain, error, errorKind, locked, busy, touch, begin, arm, send, discard, reset, fail }
}
