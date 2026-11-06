import { computed, ref, shallowRef } from 'vue'
import { isCaptureDevice, isPhotoFile } from './usePhotoCapture'

/**
 * Nad kolik fotek v jednom výběru se na desktopu ořez nenabízí. Dávku skenů
 * z PC by jinak čekalo potvrzení dialogu u každého souboru; na telefonu a tabletu
 * se fotky vybírají z galerie a ořez dává smysl i u delší dávky.
 */
export const DESKTOP_CROP_MAX_PHOTOS = 3

/**
 * Fronta ořezu fotek z jednoho výběru souborů ({@link PhotoCropDialog}): fotky jdou
 * do dialogu po jedné, PDF a ostatní soubory čekají. Po poslední fotce se výřezy
 * vrátí na svá místa v původním výběru a `deliver` dostane celý výběr najednou,
 * takže PDF a fotky z jednoho dropu dojdou pohromadě a v původním pořadí.
 */
export function usePhotoCropQueue(deliver: (files: File[]) => void) {
  const queue = shallowRef<File[]>([])
  const index = ref(0)
  const results = shallowRef<File[]>([])
  let selection: File[] = []

  const current = computed<File | null>(() => queue.value[index.value] ?? null)
  const step = computed(() => index.value + 1)
  const steps = computed(() => queue.value.length)
  /** Fotky, které ještě dialogem neprošly, včetně právě otevřené. */
  const remaining = computed(() => Math.max(0, queue.value.length - index.value))

  function reset(): void {
    selection = []
    queue.value = []
    index.value = 0
    results.value = []
  }

  function finish(): void {
    const cropped = [...results.value]
    const merged = selection.map(file => (isPhotoFile(file) ? cropped.shift() ?? file : file))
    reset()
    deliver(merged)
  }

  /** Přijme výběr; bez fotek nebo u velké dávky na desktopu ho vydá rovnou. */
  function start(files: File[]): void {
    if (files.length === 0) return
    const photos = files.filter(isPhotoFile)
    if (photos.length === 0 || (photos.length > DESKTOP_CROP_MAX_PHOTOS && !isCaptureDevice())) {
      deliver(files)
      return
    }
    selection = files
    queue.value = photos
    index.value = 0
    results.value = []
  }

  /** Hotová fotka z dialogu; po poslední se vydá celý výběr. */
  function done(file: File): void {
    results.value = [...results.value, file]
    if (index.value + 1 < queue.value.length) {
      index.value += 1
      return
    }
    finish()
  }

  /** Otevřená a všechny další fotky jdou v originále, bez dialogu. */
  function skipRest(): void {
    results.value = [...results.value, ...queue.value.slice(index.value)]
    finish()
  }

  /** Zrušení zahodí celý výběr: uživatel chtěl začít znovu, ne nahrát rozdělanou dávku. */
  function cancel(): void {
    reset()
  }

  return { current, step, steps, remaining, start, done, skipRest, cancel }
}
