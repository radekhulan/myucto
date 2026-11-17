import { computed, inject, onBeforeUnmount, provide, ref, type InjectionKey, type Ref } from 'vue'

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

/** Volá DialogActions: patička nejbližšího okna, nebo null mimo okno. */
export function useModalFooter() {
  const footer = inject(modalFooterKey, null)
  if (footer) {
    footer.claims.value++
    onBeforeUnmount(() => { footer.claims.value-- })
  }
  return { target: computed(() => footer?.target.value ?? null) }
}
