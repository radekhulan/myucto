import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { dimensionsApi, type DimensionOverview, type DimensionType, type DimensionValue } from '@/api/dimensions'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { dimensionValueLabel, dimensionValueName } from '@/utils/dimensionValueLabel'

/**
 * Číselník dimenzí firmy (Firma → Dimenze) sdílený všemi výběry na stránce.
 *
 * Načítá se jednou za firmu — editor faktury má výběr u hlavičky i u každé
 * položky a každý by si jinak stáhl celý číselník znovu. Po změně číselníku
 * (stránka Dimenze) se volá `reload()`.
 */
const state = reactive({
  supplierId: 0,
  data: null as DimensionOverview | null,
  loading: false,
})
let inFlight: Promise<void> | null = null

export interface DimensionOption {
  value: number
  label: string
  secondary?: string
  depth: number
}

export function useDimensions() {
  const supplier = useSupplierStore()
  const auth = useAuthStore()
  const { t, te } = useI18n()

  /** Sekce je zapnutá a uživatel smí číst účetnictví — jinak se nic neukazuje. */
  const enabled = computed(() => auth.hasCommercialFeatures && supplier.currentSupplier?.dimensions_enabled === true && auth.canRead('accounting'))
  const canEdit = computed(() => enabled.value && auth.canWrite('accounting'))

  async function load(force = false): Promise<void> {
    if (!enabled.value) return
    const sid = supplier.currentSupplierId
    if (!force && state.data && state.supplierId === sid) return
    if (inFlight && !force) return inFlight
    state.loading = true
    inFlight = dimensionsApi.overview()
      .then((data) => {
        state.data = data
        state.supplierId = sid
      })
      .finally(() => {
        state.loading = false
        inFlight = null
      })
    return inFlight
  }

  function setOverview(data: DimensionOverview) {
    state.data = data
    state.supplierId = supplier.currentSupplierId
  }

  const types = computed<DimensionType[]>(() => (state.supplierId === supplier.currentSupplierId ? state.data?.types : null) ?? [])
  const values = computed<DimensionValue[]>(() => (state.supplierId === supplier.currentSupplierId ? state.data?.values : null) ?? [])
  /** Typy, které se nabízí na dokladech a v deníku. */
  const documentTypes = computed(() => types.value.filter(ty => ty.is_active && ty.show_on_documents))
  const valueById = computed(() => new Map(values.value.map(v => [v.id, v])))
  const typeById = computed(() => new Map(types.value.map(ty => [ty.id, ty])))
  const valuesByType = computed(() => {
    const out = new Map<number, DimensionValue[]>()
    for (const v of values.value) {
      const list = out.get(v.type_id)
      if (list) list.push(v)
      else out.set(v.type_id, [v])
    }
    return out
  })

  function pathOf(value: DimensionValue): string[] {
    const out: string[] = []
    const seen = new Set<number>()
    let parentId = value.parent_id
    while (parentId !== null && !seen.has(parentId)) {
      seen.add(parentId)
      const parent = valueById.value.get(parentId)
      if (!parent) break
      out.unshift(nameOf(parent))
      parentId = parent.parent_id
    }
    return out
  }

  /** Hodnoty typu v pořadí stromu (rodič, pak jeho podřízené). */
  function treeOf(typeId: number): { value: DimensionValue; depth: number }[] {
    const own = values.value.filter(v => v.type_id === typeId)
    const ids = new Set(own.map(v => v.id))
    const byParent = new Map<number, DimensionValue[]>()
    for (const v of own) {
      const parent = v.parent_id !== null && ids.has(v.parent_id) ? v.parent_id : 0
      byParent.set(parent, [...(byParent.get(parent) ?? []), v])
    }
    const out: { value: DimensionValue; depth: number }[] = []
    const walk = (parentId: number, depth: number, guard: Set<number>) => {
      for (const v of byParent.get(parentId) ?? []) {
        if (guard.has(v.id)) continue
        guard.add(v.id)
        out.push({ value: v, depth })
        walk(v.id, depth + 1, guard)
      }
    }
    walk(0, 0, new Set())
    return out
  }

  function nameOf(value: DimensionValue): string {
    return dimensionValueName({ t, te }, value, typeById.value.get(value.type_id)?.system_key, valuesByType.value.get(value.type_id))
  }

  function labelOf(value: DimensionValue): string {
    return dimensionValueLabel({ t, te }, value, typeById.value.get(value.type_id)?.system_key, ' – ', valuesByType.value.get(value.type_id))
  }

  /**
   * Volby výběru: aktivní hodnoty ve stromovém pořadí, cesta nadřízených jako druhý
   * řádek (hledání podle nadřízené najde i podřízené). Uzavřená hodnota se nabídne
   * jen tehdy, když už je vybraná.
   */
  function options(typeId: number, selectedId?: number | null): DimensionOption[] {
    return treeOf(typeId)
      .filter(({ value }) => value.is_active || value.id === selectedId)
      .map(({ value, depth }) => {
        const path = pathOf(value)
        return {
          value: value.id,
          label: labelOf(value) + (value.is_active ? '' : ` (${t('dimensions.closed')})`),
          secondary: path.length > 0 ? path.join(' › ') : undefined,
          depth,
        }
      })
  }

  function valueLabel(valueId: number | null | undefined): string {
    if (!valueId) return ''
    const value = valueById.value.get(valueId)
    return value ? labelOf(value) : `#${valueId}`
  }

  return {
    enabled,
    canEdit,
    loading: computed(() => state.loading),
    load,
    reload: () => load(true),
    setOverview,
    overview: computed(() => (state.supplierId === supplier.currentSupplierId ? state.data : null)),
    types,
    values,
    documentTypes,
    valueById,
    typeById,
    treeOf,
    options,
    pathOf,
    nameOf,
    labelOf,
    valueLabel,
  }
}
