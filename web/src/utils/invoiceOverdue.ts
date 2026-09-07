import { ref } from 'vue'
import { appIsoDate, overdueDays } from '@/utils/date'

const includesToday = ref(false)

export function setOverdueIncludesToday(value: boolean): void {
  includesToday.value = value
}

/** Zobrazení a filtry. Upomínky používají skutečný počet dnů po splatnosti. */
export function isInvoiceDateOverdue(dueDate: string): boolean {
  return overdueDays(dueDate) > 0 || (includesToday.value && dueDate === appIsoDate())
}
