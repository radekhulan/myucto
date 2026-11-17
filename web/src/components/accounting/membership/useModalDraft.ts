import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Rozepsaný formulář v okně (Modal) jako správa kategorií datové schránky: okno se zavře hned, jen když do něj
 * uživatel nic nezadal; jinak se nejdřív zeptá. Rozepsanost poznáme z událostí input/change uvnitř okna,
 * takže toky nemusí porovnávat formulář s výchozími hodnotami (ty se často doplňují až po načtení).
 * Rozběhnutý zápis hlídá zámek toku; ten okno nezavře vůbec.
 */
export function useModalDraft() {
  const { t } = useI18n()
  const touched = ref(false)
  /**
   * Posluchač na obal obsahu okna (@input a @change). Nepočítá se pole hledání (type="search") a prvky
   * označené `data-draft-ignore` (výběr sazby v předpisu jen hledá, nic nevyplňuje).
   */
  function mark(event?: Event) {
    const target = event?.target
    if (target instanceof HTMLInputElement && target.type === 'search') return
    if (target instanceof Element && target.closest('[data-draft-ignore]')) return
    touched.value = true
  }
  /** Po uložení nebo zavření: další zavření se už neptá. */
  function reset() { touched.value = false }
  /** Smí se okno zavřít? Rozepsané se zavře jen po potvrzení. */
  function confirmClose(): boolean {
    if (!touched.value) return true
    if (!window.confirm(t('nonprofitMembership.closeUnsavedConfirm'))) return false
    touched.value = false
    return true
  }
  return { touched, mark, reset, confirmClose }
}
