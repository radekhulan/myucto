import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

/**
 * Je prvek užší než daná šířka? Měří VLASTNÍ šířku prvku, ne okno ani kontejner
 * `workspace`: sdílená karta (např. Zaúčtování) sedí jednou přes celou šířku detailu
 * a jindy ve sloupci formuláře vedle náhledu PDF, kde kontejnerový breakpoint
 * stránky pořád hlásí „široké" rozložení.
 *
 * Sleduje jen ten jeden prvek (žádný řetězec předků). Dokud šířka není známá
 * (první vykreslení, prostředí bez ResizeObserveru), hlásí `false` = výchozí vzhled.
 */
export function useNarrowContainer(target: Ref<HTMLElement | null>, minWidth: () => number | null): Ref<boolean> {
  const width = ref(0)
  const narrow = ref(false)
  let observer: ResizeObserver | null = null

  function update(): void {
    const limit = minWidth()
    narrow.value = limit !== null && width.value > 0 && width.value < limit
  }

  watch(target, (el) => {
    observer?.disconnect()
    observer = null
    if (!el) return
    width.value = el.clientWidth
    update()
    if (typeof ResizeObserver === 'undefined') return
    observer = new ResizeObserver((entries) => {
      const entry = entries[entries.length - 1]
      width.value = entry?.contentRect?.width ?? el.clientWidth
      update()
    })
    observer.observe(el)
  }, { immediate: true, flush: 'post' })

  watch(minWidth, update)
  onBeforeUnmount(() => observer?.disconnect())

  return narrow
}
