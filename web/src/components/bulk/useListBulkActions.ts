import { computed, shallowRef, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import type { ActionItem } from '@/components/ui/ActionBar.vue'
import type { BulkSelection, BulkTarget } from '@/api/bulkActions'

export interface BulkAccess {
  canWrite: (key: 'accounting.bulk_changes' | 'accounting.journal.bulk_repost' | 'accounting.journal.post') => boolean
  isCompanyAdminRole?: boolean
  isClientRole?: boolean
}

/**
 * Položka „Hromadné akce" se ukáže jen tomu, kdo smí aspoň jednu operaci: nové právo
 * hromadných změn, dosavadní hromadné přeúčtování, nebo správce firmy. Přesnou dostupnost
 * jednotlivých operací pak vrací server.
 */
export function canOpenBulkActions(auth: BulkAccess): boolean {
  if (auth.isClientRole) return false
  return auth.canWrite('accounting.bulk_changes')
    || (auth.canWrite('accounting.journal.bulk_repost') && auth.canWrite('accounting.journal.post'))
    || auth.isCompanyAdminRole === true
}

export interface BulkDialogState {
  selection: BulkSelection
  selectionCount: number
  filterSelection: BulkSelection | null
  filterCount: number
}

/**
 * Výběr pro dialog: s ručním výběrem jdou id (a jako druhá volba „všech N podle filtru"),
 * bez něj rovnou query seznamu. Výběr se zafixuje při otevření, aby se pod rozběhnutou
 * úlohou nezměnil.
 */
export function bulkDialogState(ids: number[], listQuery: Record<string, unknown>, total: number): BulkDialogState {
  const filterSelection: BulkSelection = { list_query: { ...listQuery } }
  if (ids.length > 0) {
    return { selection: { ids: [...ids] }, selectionCount: ids.length, filterSelection, filterCount: total }
  }
  return { selection: filterSelection, selectionCount: total, filterSelection: null, filterCount: total }
}

export function useListBulkActions(options: {
  target: BulkTarget
  selectedIds: Ref<number[]>
  listQuery: () => Record<string, unknown>
  total: Ref<number>
}) {
  const { t } = useI18n()
  const auth = useAuthStore()
  const dialog = shallowRef<BulkDialogState | null>(null)

  const canUse = computed(() => canOpenBulkActions(auth as unknown as BulkAccess))

  function open() {
    dialog.value = bulkDialogState(options.selectedIds.value, options.listQuery(), options.total.value)
  }

  function close() {
    dialog.value = null
  }

  const menuItem = computed<ActionItem>(() => ({
    key: 'bulk-actions',
    label: options.selectedIds.value.length > 0
      ? t('bulk_actions.menu_selected', { n: options.selectedIds.value.length })
      : t('bulk_actions.menu'),
    icon: 'table',
    tier: 'overflow',
    variant: 'warning',
    show: canUse.value,
    disabled: options.selectedIds.value.length === 0 && options.total.value === 0,
    run: open,
  }))

  return { target: options.target, canUse, dialog, open, close, menuItem }
}
