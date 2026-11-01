<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemKindsApi, type OtherItemKind, type OtherItemKindPayload, type OtherItemSide } from '@/api/otherItems'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import { kindSettingLabel } from '@/utils/otherItemKind'

/**
 * Druhy ostatních pohledávek a závazků — vestavěné i vlastní v jednom seznamu. Každý druh
 * jde skrýt a každý může číslovat vlastní řadou; prefix se volí při prvním zapnutí řady,
 * prefix, šablonu a čítač pak upravuje účetní v Nástrojích → Číselné řady.
 */
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const kinds = ref<OtherItemKind[]>([])
const loading = ref(false)
const saving = ref(false)
const canWrite = computed(() => auth.canWrite('other_items'))
// Vlastní řada mění číselné řady — server chce stejné právo jako úprava řad v Nástrojích.
const canManageSeries = computed(() => auth.canWrite('accounting'))
const sides: OtherItemSide[] = ['payable', 'receivable']
const form = reactive({ side: 'payable' as OtherItemSide, name: '', own_series: false, prefix: '' })
const renaming = reactive<Record<string, string>>({})
/** Druh, kterému se právě zadává prefix nové vlastní řady. */
const seriesFor = ref<string | null>(null)
const seriesPrefix = ref('')

const keyOf = (kind: OtherItemKind) => kind.builtin ? `${kind.side}:${kind.builtin_code}` : `custom:${kind.id}`
const label = (kind: OtherItemKind) => kindSettingLabel(t, kind)
const bySide = (side: OtherItemSide) => kinds.value.filter(kind => kind.side === side)

async function load() {
  loading.value = true
  try {
    kinds.value = await otherItemKindsApi.list()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function run(action: () => Promise<unknown>) {
  if (saving.value) return false
  saving.value = true
  try {
    await action()
    await load()
    emit('changed')
    return true
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
    return false
  } finally {
    saving.value = false
  }
}

function save(kind: OtherItemKind, payload: OtherItemKindPayload) {
  return run(() => kind.builtin || kind.id === null
    ? otherItemKindsApi.updateBuiltin(kind.side, kind.builtin_code!, payload)
    : otherItemKindsApi.update(kind.id, payload))
}

async function create() {
  if (!form.name.trim() || (form.own_series && !form.prefix.trim())) {
    toast.error(t('other_items.kinds.validation_required'))
    return
  }
  const ok = await run(() => otherItemKindsApi.create({
    side: form.side, name: form.name.trim(),
    own_series: form.own_series, ...(form.own_series ? { prefix: form.prefix.trim().toUpperCase() } : {}),
  }))
  if (ok) Object.assign(form, { name: '', own_series: false, prefix: '' })
}

async function rename(kind: OtherItemKind) {
  const key = keyOf(kind)
  const name = (renaming[key] ?? '').trim()
  if (!name || name === kind.name) {
    delete renaming[key]
    return
  }
  if (await save(kind, { name })) delete renaming[key]
}

function toggleActive(kind: OtherItemKind) {
  void save(kind, { is_active: !kind.is_active })
}

/** Vypnutí řady i zapnutí dřívější řady jde rovnou; nová řada si nejdřív řekne o prefix. */
function toggleSeries(kind: OtherItemKind) {
  if (kind.own_series || kind.series_prefix) {
    void save(kind, { own_series: !kind.own_series })
    return
  }
  seriesFor.value = keyOf(kind)
  seriesPrefix.value = ''
}

async function enableSeries(kind: OtherItemKind) {
  const prefix = seriesPrefix.value.trim().toUpperCase()
  if (!prefix) {
    toast.error(t('other_items.kinds.validation_required'))
    return
  }
  if (await save(kind, { own_series: true, prefix })) seriesFor.value = null
}

function remove(kind: OtherItemKind) {
  if (kind.id === null || !window.confirm(t('other_items.kinds.confirm_delete', { name: kind.name }))) return
  void run(() => otherItemKindsApi.remove(kind.id!))
}

onMounted(load)
</script>

<template>
  <Modal :title="t('other_items.kinds.title')" width-class="max-w-3xl" @close="emit('close')">
    <p class="mb-3 text-sm text-neutral-500">{{ t('other_items.kinds.hint') }}</p>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else class="space-y-4">
      <section v-for="side in sides" :key="side" class="overflow-x-auto">
        <h3 class="mb-1 text-sm font-semibold">{{ t(`other_items.side.${side}`) }}</h3>
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500">
            <tr>
              <th class="px-2 py-1 text-left font-medium">{{ t('other_items.kinds.name') }}</th>
              <th class="px-2 py-1 text-left font-medium">{{ t('other_items.kinds.series') }}</th>
              <th class="px-2 py-1"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="kind in bySide(side)" :key="keyOf(kind)" :class="{ 'text-neutral-400': !kind.is_active }">
              <td class="px-2 py-2">
                <input v-if="renaming[keyOf(kind)] !== undefined" v-model="renaming[keyOf(kind)]" maxlength="100"
                  class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2" @keydown.enter.prevent="rename(kind)" @blur="rename(kind)" />
                <template v-else>
                  {{ label(kind) }}
                  <span v-if="!kind.builtin" class="ml-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700">{{ t('other_items.kinds.custom') }}</span>
                  <span v-if="!kind.is_active" class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ t('other_items.kinds.inactive') }}</span>
                </template>
              </td>
              <td class="px-2 py-2 whitespace-nowrap">
                <form v-if="seriesFor === keyOf(kind)" class="flex flex-wrap items-center gap-1" @submit.prevent="enableSeries(kind)">
                  <input v-model="seriesPrefix" maxlength="10" :placeholder="t('accounting.closing.series.prefix')"
                    class="h-8 w-24 rounded-md border border-neutral-300 bg-surface px-2 font-mono uppercase" />
                  <button type="submit" :disabled="saving" :class="[btnOutlineSm('success'), 'whitespace-nowrap']" :title="t('common.save')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  </button>
                  <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :title="t('common.cancel')" @click="seriesFor = null">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  </button>
                </form>
                <template v-else-if="kind.own_series">
                  {{ t('other_items.kinds.own_series') }} <span class="font-mono">{{ kind.series_prefix }}</span>
                </template>
                <template v-else>{{ t(side === 'payable' ? 'other_items.kinds.company_series_payable' : 'other_items.kinds.company_series_receivable') }}</template>
              </td>
              <td class="px-2 py-2">
                <div v-if="canWrite" class="flex flex-wrap justify-end gap-1">
                  <button v-if="!kind.builtin" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :title="t('common.edit')" @click="renaming[keyOf(kind)] = kind.name ?? ''">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button v-if="canManageSeries && seriesFor !== keyOf(kind)" type="button" :disabled="saving" :class="[btnOutlineSm(kind.own_series ? 'warning' : 'primary'), 'whitespace-nowrap']" @click="toggleSeries(kind)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                    {{ kind.own_series ? t('other_items.kinds.series_off') : t('other_items.kinds.series_on') }}
                  </button>
                  <button type="button" :disabled="saving" :class="[btnOutlineSm(kind.is_active ? 'warning' : 'success'), 'whitespace-nowrap']" @click="toggleActive(kind)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="kind.is_active ? ICONS.eyeOff : ICONS.eye" /></svg>
                    {{ kind.is_active ? t('other_items.kinds.deactivate') : t('other_items.kinds.activate') }}
                  </button>
                  <button v-if="!kind.builtin && (!kind.own_series || canManageSeries)" type="button" :disabled="saving" :class="[btnOutlineSm('danger'), 'whitespace-nowrap']" :title="t('common.delete')" @click="remove(kind)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
      <p class="text-xs text-neutral-500">{{ t('other_items.kinds.other_note') }}</p>
    </div>

    <form v-if="canWrite" class="mt-4 space-y-3 border-t border-neutral-200 pt-4" @submit.prevent="create">
      <h3 class="text-sm font-semibold">{{ t('other_items.kinds.new') }}</h3>
      <p class="text-xs text-neutral-500">{{ t('other_items.kinds.custom_tax_note') }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="text-sm font-medium">{{ t('other_items.side_label') }}
          <select v-model="form.side" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
            <option value="receivable">{{ t('other_items.side.receivable') }}</option>
            <option value="payable">{{ t('other_items.side.payable') }}</option>
          </select>
        </label>
        <label class="text-sm font-medium">{{ t('other_items.kinds.name') }}
          <input v-model="form.name" maxlength="100" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
        </label>
        <label v-if="canManageSeries" class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="form.own_series" type="checkbox" class="rounded border-neutral-300" />
          {{ t('other_items.kinds.own_series_label') }}
        </label>
        <label v-if="form.own_series" class="text-sm font-medium">{{ t('accounting.closing.series.prefix') }}
          <input v-model="form.prefix" maxlength="10" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 font-mono uppercase" />
        </label>
      </div>
      <p v-if="form.own_series" class="text-xs text-neutral-500">{{ t('other_items.kinds.own_series_hint') }}</p>
      <button type="submit" :disabled="saving" :class="btnFilled('primary')">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('other_items.kinds.add') }}
      </button>
    </form>

    <template #footer>
      <div class="flex justify-end">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
