import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { isdsAccessApi, type IsdsAccessOperation, type IsdsSavedAccess } from '@/api/isdsAccess'
import { useSupplierStore } from '@/stores/supplier'

type Environment = 'production' | 'test'

const accessUpdated = ref(0)

export function useIsdsSavedAccess(
  environment: () => Environment,
  operation: IsdsAccessOperation,
  active: () => boolean = () => true,
) {
  const supplier = useSupplierStore()
  const state = reactive({
    choices: [] as IsdsSavedAccess[],
    selectedId: null as number | null,
    confirmed: false,
    remember: false,
    removing: false,
  })
  const scope = computed(() => [supplier.currentSupplierId, environment()] as const)
  const selected = computed(() => state.choices.find(choice => choice.id === state.selectedId))
  const canUseSelected = computed(() => Boolean(selected.value && state.confirmed && !state.removing))
  let scopeVersion = 0
  let formVersion = 0
  let loadVersion = 0

  function reset() {
    ++formVersion
    state.selectedId = null
    state.confirmed = false
    state.remember = false
  }

  async function load() {
    const version = ++loadVersion
    const loaded = await isdsAccessApi.choices(environment(), operation).catch(() => [])
    if (version !== loadVersion) return
    state.choices = loaded
    if (state.selectedId !== null && !selected.value) reset()
  }

  watch([() => supplier.currentSupplierId, environment, active], () => {
    const version = ++scopeVersion
    ++loadVersion
    reset()
    state.choices = []
    state.removing = false
    if (active()) {
      void Promise.resolve().then(() => version === scopeVersion ? load() : undefined)
    }
  }, { immediate: true, flush: 'sync' })

  watch(accessUpdated, () => {
    if (active()) void load()
  })

  watch(() => state.selectedId, () => { state.confirmed = false }, { flush: 'sync' })

  function captureScope() {
    const version = scopeVersion
    return () => version === scopeVersion && active()
  }

  async function credentials(useSaved: boolean, username: string, password: string) {
    if (useSaved) {
      return canUseSelected.value && selected.value ? { access_id: selected.value.id, authorized: true as const } : null
    }
    const login = { username: username.trim(), password }
    if (!state.remember) return login

    const version = formVersion
    const saved = await isdsAccessApi.remember(environment(), operation, login.username, login.password)
    if (version !== formVersion) return null
    ++loadVersion
    state.choices = [...state.choices.filter(choice => choice.id !== saved.id), saved]
    state.remember = false
    ++accessUpdated.value
    return { access_id: saved.id, authorized: true as const }
  }

  async function forget() {
    if (!selected.value || selected.value.scope !== 'personal' || state.removing) return
    const version = scopeVersion
    const id = selected.value.id
    state.removing = true
    try {
      await isdsAccessApi.forget(id)
      if (version !== scopeVersion) return
      ++loadVersion
      state.choices = state.choices.filter(choice => choice.id !== id)
      reset()
      ++accessUpdated.value
    } finally {
      if (version === scopeVersion) state.removing = false
    }
  }

  onBeforeUnmount(() => {
    ++scopeVersion
    ++loadVersion
    reset()
  })

  return { scope, state, selected, canUseSelected, reset, captureScope, credentials, forget }
}

export type IsdsSavedAccessState = ReturnType<typeof useIsdsSavedAccess>
