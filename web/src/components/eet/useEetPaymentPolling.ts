import { onBeforeUnmount, ref } from 'vue'
import { eetApi, type EetPaymentEvent } from '@/api/eet'

const POLL_LIMIT_MS = 20_000

export function eetIsOpen(ev: EetPaymentEvent | null | undefined): boolean {
  const s = ev?.eet?.status
  return s === 'pending' || s === 'sending' || s === 'retry_wait'
}

/**
 * Krátký polling stavu EET po přijetí platby: interval z odpovědi (jinak 2 s),
 * nejvýše ~20 s, zastaví se při odpojení komponenty. Platba je přitom už uložená,
 * polling jen dotahuje stav odeslání do EET.
 */
export function useEetPaymentPolling(onUpdate: (ev: EetPaymentEvent) => void) {
  const timedOut = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  let started = 0
  let stopped = false

  function schedule(ev: EetPaymentEvent) {
    if (stopped || !eetIsOpen(ev)) return
    if (Date.now() - started >= POLL_LIMIT_MS) { timedOut.value = true; return }
    const delay = Math.max(1, Math.min(10, ev.poll_after_seconds ?? 2)) * 1000
    timer = setTimeout(async () => {
      if (stopped) return
      try {
        const fresh = await eetApi.payment(ev.payment_event_id)
        if (stopped) return
        onUpdate(fresh)
        schedule(fresh)
      } catch {
        schedule(ev)
      }
    }, delay)
  }

  function start(ev: EetPaymentEvent) {
    if (timer) clearTimeout(timer)
    started = Date.now()
    timedOut.value = false
    schedule(ev)
  }

  function stop() {
    stopped = true
    if (timer) clearTimeout(timer)
  }

  onBeforeUnmount(stop)
  return { start, stop, timedOut }
}
