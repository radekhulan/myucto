import { ref } from 'vue'

export interface Toast {
  id: number
  type: 'success' | 'error' | 'info' | 'warning'
  text: string
  action?: { label: string; handler: () => void | Promise<void> }
}

const toasts = ref<Toast[]>([])
let nextId = 1

function push(type: Toast['type'], text: string, ttl = 5000, action?: Toast['action']) {
  const id = nextId++
  toasts.value.push({ id, type, text, action })
  setTimeout(() => {
    const i = toasts.value.findIndex(t => t.id === id)
    if (i !== -1) toasts.value.splice(i, 1)
  }, ttl)
}

function dismiss(id: number) {
  const i = toasts.value.findIndex(t => t.id === id)
  if (i !== -1) toasts.value.splice(i, 1)
}

export function useToast() {
  return {
    toasts,
    success: (t: string, action?: Toast['action']) => push('success', t, action ? 10000 : 5000, action),
    /*
     * Chyba smí nést akci ze stejného důvodu jako úspěch: „rok je uzavřený"
     * bez prokliku na uzávěrku je slepá ulička — uživatel ví, co se stalo,
     * ale ne kam jít. S akcí toast drží déle, aby ji stihl kliknout.
     */
    error:   (t: string, action?: Toast['action']) => push('error', t, action ? 12000 : 8000, action),
    info:    (t: string) => push('info', t),
    /*
     * Varování akci nese ze stejného důvodu jako chyba: hromadné zaúčtování,
     * kterému část dokladů propadla na chybějící účetní období, je varování
     * (zbytek prošel) — ale bez prokliku na Uzávěrku je to pořád slepá ulička.
     */
    warning: (t: string, action?: Toast['action']) => push('warning', t, action ? 12000 : 6000, action),
    dismiss,
  }
}
