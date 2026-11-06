import type { ComputedRef, InjectionKey } from 'vue'
import type { PaneId } from '@/stores/workspace'
import type { RouteLocationRaw } from 'vue-router'

export interface WindowContext {
  paneId: ComputedRef<PaneId | null>
  active: ComputedRef<boolean>
  /** Okno je vidět v některém panelu, i když aktivní je jiný. Bez hodnoty platí `active`. */
  visible?: ComputedRef<boolean>
  root: HTMLElement
  portal: HTMLElement
  navigate?: (to: RouteLocationRaw) => Promise<void>
  setTitle?: (title: string | null, subtitle?: string | null) => void
}

export const windowContextKey: InjectionKey<WindowContext> = Symbol('workspace-window')
