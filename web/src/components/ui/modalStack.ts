/**
 * Pořadí otevřených oken (Modal) v jednom panelu pracovní plochy.
 *
 * Why: každé okno poslouchá Escape na celém dokumentu. Okno otevřené z jiného okna (nový kontakt z okna
 * nového člena) by Escapem zavřelo i rodiče a s ním rozepsaný formulář. Escape proto zavírá jen
 * nejvrchnější okno svého panelu, a vůbec ne, když ho už spotřeboval prvek uvnitř (rozbalený výběr, nabídka).
 * Panel i aktivitu čteme až při stisku: okno pracovní plochy se při navigaci odloží (zůstane připojené,
 * jen neaktivní) a jeho otevřená okna se nesmí počítat.
 */
interface Entry { id: symbol; pane: () => unknown; active: () => boolean }
const stack: Entry[] = []

export function pushModal(id: symbol, pane: () => unknown = () => null, active: () => boolean = () => true): void {
  stack.push({ id, pane, active })
}

export function removeModal(id: symbol): void {
  const index = stack.findIndex(entry => entry.id === id)
  if (index !== -1) stack.splice(index, 1)
}

/** Zavře Escape okno `id`? Jen když ho nikdo nespotřeboval a okno je nejvýš mezi aktivními okny svého panelu. */
export function closesOnEscape(id: symbol, event: KeyboardEvent): boolean {
  if (event.defaultPrevented) return false
  const own = stack.find(entry => entry.id === id)
  if (!own) return true
  const pane = own.pane()
  const top = [...stack].reverse().find(entry => entry.active() && entry.pane() === pane)
  return top?.id === id
}
