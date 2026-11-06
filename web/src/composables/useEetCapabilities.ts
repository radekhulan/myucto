import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { eetApi, type EetCapabilities, type EetCapabilityDevice } from '@/api/eet'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

/**
 * Možnosti EET aktuální firmy (zapnuto, prostředí, výchozí zařízení). Bez práva
 * `eet` se nic nevolá a EET se tváří jako vypnuté, takže stávající toky faktury
 * zůstávají beze změny.
 */
export function useEetCapabilities() {
  const auth = useAuthStore()
  const supplierStore = useSupplierStore()
  const capabilities = ref<EetCapabilities | null>(null)
  const loaded = ref(false)

  async function load(): Promise<void> {
    if (!auth.canRead('eet')) {
      capabilities.value = null
      loaded.value = true
      return
    }
    try {
      capabilities.value = await eetApi.capabilities()
    } catch {
      capabilities.value = null
    } finally {
      loaded.value = true
    }
  }

  onMounted(() => { void load() })
  onActivated(() => { if (loaded.value) void load() })
  watch(() => supplierStore.currentSupplier?.id, () => { void load() })

  const enabled = computed(() => capabilities.value?.enabled === true)
  const canAccept = computed(() => enabled.value && auth.canWrite('eet.accept_payment'))

  return { capabilities, loaded, enabled, canAccept, reload: load }
}

/** Zařízení nabízená v dialogu; aktivní seznam vrací capabilities, takže ho vidí i obsluha bez \et.settings\. */
export async function loadEetDevices(): Promise<EetCapabilityDevice[]> {
  try {
    return (await eetApi.capabilities()).devices ?? []
  } catch {
    return []
  }
}

const DEVICE_KEY = 'eet.device'

/** Naposledy zvolené zařízení si prohlížeč pamatuje zvlášť pro každou firmu. */
export function rememberedEetDevice(supplierId: number | null | undefined): number | null {
  if (!supplierId) return null
  try {
    const raw = window.localStorage.getItem(`${DEVICE_KEY}.${supplierId}`)
    const n = raw === null ? NaN : Number(raw)
    return Number.isInteger(n) && n > 0 ? n : null
  } catch {
    return null
  }
}

export function rememberEetDevice(supplierId: number | null | undefined, deviceId: number | null): void {
  if (!supplierId) return
  try {
    const key = `${DEVICE_KEY}.${supplierId}`
    if (deviceId) window.localStorage.setItem(key, String(deviceId))
    else window.localStorage.removeItem(key)
  } catch {
    // paměť prohlížeče je jen pohodlí
  }
}

/** Nový klíč idempotence; jeden na otevření dialogu, opakování po chybě sítě ho znovu použije. */
export function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
