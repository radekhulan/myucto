/**
 * Doskočení na konkrétní položku formuláře — otevřenou, vysvícenou, zaostřenou.
 *
 * PROČ: hlášky typu „tenhle údaj chybí" popisovaly cestu slovy („karta osoby →
 * Údaje pro registraci zaměstnance"). Účetní ten popis přečte a pak stejně
 * hledá očima, a když je cílová sekce sbalená nebo na jiné záložce, nenajde ji
 * vůbec. Odrolovat na sbalený blok je totéž jako neudělat nic.
 *
 * Proto se cíl nejdřív ZPŘÍSTUPNÍ (rozbalí se všichni sbalení předci), teprve
 * pak se na něj skáče, na dvě vteřiny se orámuje a dostane kurzor. Vysvícení
 * je tam kvůli dlouhým formulářům: samotný scroll nechá člověka hádat, které
 * z patnácti polí na obrazovce bylo to hledané.
 */

/** Jak dlouho pole zůstane orámované. Musí sedět s animací v `main.css`. */
const FLASH_MS = 3000

/** Rezerva od okraje výřezu, aby cíl nekončil nalepený na hraně. */
const MARGIN_PX = 80

/**
 * Rozbalí všechny sbalené předky cíle.
 *
 * `<details>` se otevírá atributem, `hidden` se ruší; `v-show` (`display:none`)
 * se nechává být — to je stav komponenty, do kterého se zvenčí sahat nemá,
 * a řeší ho volající tím, že si přepne záložku dřív, než sem doskočí.
 */
function openAncestors(el: HTMLElement): void {
  let node: HTMLElement | null = el
  while (node !== null) {
    if (node instanceof HTMLDetailsElement) node.open = true
    if (node.hasAttribute('hidden')) node.removeAttribute('hidden')
    node = node.parentElement
  }
}

/** Prvek, kterému má smysl dát kurzor — cíl sám, nebo první ovladač v něm. */
function focusable(el: HTMLElement): HTMLElement | null {
  if (el.matches('input, select, textarea, button, [contenteditable="true"]')) {
    return el
  }

  return el.querySelector<HTMLElement>(
    'input:not([type="hidden"]), select, textarea, [contenteditable="true"]',
  )
}

/**
 * Selektor na položku podle jejího `data-a1-field`.
 *
 * Escapuje se ručně, ne přes `CSS.escape`: v testovacím DOM (happy-dom)
 * globální `CSS` neexistuje a volání by shodilo celý doskok. V hodnotě
 * atributu v uvozovkách stačí ošetřit uvozovku a zpětné lomítko.
 */
export function fieldSelector(field: string): string {
  const escaped = field.replace(/\\/g, '\\\\').replace(/"/g, '\\"')

  return `[data-a1-field="${escaped}"]`
}

/**
 * Doskočí na `selector` uvnitř `root` (výchozí `document`) a zvýrazní ho.
 *
 * Vrací `false`, když cíl na stránce není — volající tak pozná, že navigace
 * selhala, a může místo tichého nic ohlásit důvod.
 */
export function revealField(
  selector: string,
  root: ParentNode = document,
): boolean {
  const target = root.querySelector<HTMLElement>(selector)
  if (target === null) return false

  openAncestors(target)

  /*
   * Doskok se KONTROLUJE, a to opakovaně. Dva důvody, oba ověřené na živé
   * stránce:
   *
   * 1. Plynulý scroll umí prohlížeč vypnout (Chrome „Smooth Scrolling") a pak
   *    `scrollIntoView({ behavior: 'smooth' })` neudělá vůbec nic.
   * 2. I když se trefí, stránka se pod tím ještě přeskládá — dobíhající karta
   *    zaměstnance povyroste o tisíce pixelů a cíl uteče mimo obrazovku.
   *    Naměřeno: cíl skončil 4 373 px NAD viewportem, tedy přesně to „nic
   *    se nestalo", na které si uživatel stěžuje.
   *
   * Hlídá se proto poloha CÍLE, ne to, jestli se stránka pohnula. Kontroly
   * dobíhají ještě chvíli po skoku, dokud se rozložení neustálí.
   */
  target.scrollIntoView({ behavior: 'smooth', block: 'center' })
  const settle = (): void => {
    const { top, bottom } = target.getBoundingClientRect()
    // Rezerva, ne holé „je vidět": položka nalepená na spodní hraně sice
    // technicky ve výřezu je, ale člověk ji tam nehledá a popisek pod ní
    // (nápověda, chybová hláška) už vidět není.
    if (top >= MARGIN_PX && bottom <= window.innerHeight - MARGIN_PX) return
    target.scrollIntoView({ block: 'center' })
  }
  for (const delay of [300, 700, 1200, 2000]) window.setTimeout(settle, delay)

  target.classList.remove('field-flash')
  // Vynucený reflow: bez něj prohlížeč obě změny třídy slije do jedné
  // a animace se při druhém kliknutí na tutéž položku už nespustí.
  void target.offsetWidth
  target.classList.add('field-flash')
  window.setTimeout(() => target.classList.remove('field-flash'), FLASH_MS)

  /*
   * Kurzor až po scrollu: `focus()` sám o sobě stránkou skočí a přebil by
   * plynulý pohyb skokem. `preventScroll` to hlídá i tam, kde ho prohlížeč
   * respektuje.
   */
  window.setTimeout(() => focusable(target)?.focus({ preventScroll: true }), 350)

  return true
}
