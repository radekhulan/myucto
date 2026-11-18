import { computed, inject, onBeforeUnmount, provide, ref, watch, type InjectionKey, type Ref } from 'vue'

/**
 * Patička okna (Modal) pro tlačítka formuláře, který v okně leží jako samostatná komponenta.
 *
 * Why: okna v aplikaci mají tlačítka v patičce (`#footer`: Zrušit, potom potvrzení vpravo), jenže formulář
 * s vlastním stavem (kroky náhled → potvrzení) je komponenta uvnitř těla okna a slot `#footer` rodiče
 * nemá jak naplnit. Okno proto nabídne prvek patičky a DialogActions do něj tlačítka přesune (Teleport).
 */
interface ModalFooter { target: Ref<HTMLElement | null>; claims: Ref<number> }
const modalFooterKey: InjectionKey<ModalFooter> = Symbol('modal-footer')

/** Volá Modal: vrací ref na prvek patičky a počet obsazení (patička je vidět i bez slotu `#footer`). */
export function provideModalFooter(): ModalFooter {
  const footer: ModalFooter = { target: ref(null), claims: ref(0) }
  provide(modalFooterKey, footer)
  return footer
}

/**
 * Volá DialogActions: patička nejbližšího okna, nebo null mimo okno. Patičku obsadí (a tím zobrazí) jen
 * dokud `uses()` platí; tlačítka vykreslená na místě (inline) prázdnou patičku neukazují.
 */
export function useModalFooter(uses: () => boolean = () => true) {
  const footer = inject(modalFooterKey, null)
  let claimed = false
  const set = (on: boolean) => {
    if (!footer || on === claimed) return
    footer.claims.value += on ? 1 : -1
    claimed = on
  }
  watch(uses, on => set(on), { immediate: true })
  onBeforeUnmount(() => set(false))
  return { target: computed(() => footer?.target.value ?? null) }
}
