import { onBeforeUnmount, onMounted } from 'vue'

const listeners = new Set<(supplier: number) => void>()
/** Obnovuje nabídky sazeb mezi panely; již vyplněné finanční hodnoty se nepřepisují. */
export function notifyMembershipRatesChanged(supplier: number) {
  for (const listener of listeners) listener(supplier)
}
export function onMembershipRatesChanged(listener: (supplier: number) => void) {
  onMounted(() => listeners.add(listener))
  onBeforeUnmount(() => listeners.delete(listener))
}
