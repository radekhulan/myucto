import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

export const SIDE_PREVIEW_MEDIA_QUERY = '(min-width: 1536px)'

/** Sdílená detekce šířky pro detail i editor dokladu. */
export function useSidePreviewWide(): Ref<boolean> {
  const wide = ref(false)
  let mql: MediaQueryList | null = null

  function onMediaChange(event: MediaQueryListEvent): void {
    wide.value = event.matches
  }

  onMounted(() => {
    if (typeof window.matchMedia !== 'function') return
    mql = window.matchMedia(SIDE_PREVIEW_MEDIA_QUERY)
    wide.value = mql.matches
    mql.addEventListener('change', onMediaChange)
  })

  onBeforeUnmount(() => {
    mql?.removeEventListener('change', onMediaChange)
    mql = null
  })

  return wide
}
