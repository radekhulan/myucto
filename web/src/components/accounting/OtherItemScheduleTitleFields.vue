<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemPlansApi, type OtherItemSchedule, type OtherItemScheduleTitleSettings } from '@/api/otherItemPlans'
import { formatDate } from '@/composables/useFormat'
import { scheduleTitleErrorText, type ScheduleTitleError } from '@/utils/otherItemScheduleTitleError'

/**
 * Šablona názvu výskytů opakování. Náhled počítá server stejným kódem jako generování
 * (zástupné znaky opakované faktury), takže se s vytvořenou položkou nerozejde.
 */
const props = defineProps<{
  itemId: number
  /** Četnost nového rozvrhu; u existujícího rozvrhu server bere jeho příští termín. */
  frequency?: OtherItemSchedule['frequency']
  /** Konec nového rozvrhu: délka názvu se kontroluje jen do něj, stejně jako při uložení. */
  endsOn?: string | null
  /** Chyba z uložení s polem formuláře, zobrazí se u něj. */
  submitError?: ScheduleTitleError | null
}>()
const model = defineModel<OtherItemScheduleTitleSettings>({ required: true })
const { t, tm, rt, te } = useI18n()
const ids = { input: useId(), hint: useId(), error: useId(), syncError: useId() }

const helpOpen = ref(false)
const helpRows = computed(() =>
  (tm('other_items.plans.title_help_rows') as { c: string; d: string }[]).map(r => ({ c: rt(r.c), d: rt(r.d) })))
const preview = ref<{ title: string; issued_on: string } | null>(null)
/** Chyba náhledu u pole (neplatná šablona) a chyba bez pole (síť, skončený rozvrh) zvlášť: druhá není vina šablony. */
const previewError = ref<ScheduleTitleError | null>(null)
const previewProblem = ref<string | null>(null)
const previewLoading = ref(false)
const error = computed(() => previewError.value || (props.submitError?.field ? props.submitError : null))
const errorMessage = computed(() => error.value ? errorText(error.value) : '')
let seq = 0
let timer: ReturnType<typeof setTimeout> | undefined

const errorText = (e: ScheduleTitleError) => scheduleTitleErrorText(e, t, te)

async function loadPreview() {
  const current = ++seq
  previewLoading.value = true
  try {
    const result = await otherItemPlansApi.titlePreview(props.itemId, { ...model.value, frequency: props.frequency, ends_on: props.endsOn })
    if (current !== seq) return
    preview.value = result
    previewError.value = null
    previewProblem.value = null
  } catch (e: any) {
    if (current !== seq) return
    preview.value = null
    const data: ScheduleTitleError | undefined = e?.response?.data?.error
    previewError.value = data?.field === 'title_template' || data?.field === 'title_sync_period' ? data : null
    previewProblem.value = previewError.value ? null : errorText(data || {})
  } finally {
    if (current === seq) previewLoading.value = false
  }
}

// Psaní šablony: náhled až po krátké pauze, pozdní odpověď staršího textu se zahodí (seq).
watch(() => [props.itemId, props.frequency, props.endsOn, model.value.title_template, model.value.title_sync_period], () => {
  clearTimeout(timer)
  timer = setTimeout(() => void loadPreview(), 300)
}, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))

function setTemplate(value: string) {
  model.value = { ...model.value, title_template: value === '' ? null : value }
}
function setSync(value: boolean) {
  model.value = { ...model.value, title_sync_period: value }
}
</script>

<template>
  <div class="space-y-2" data-test="schedule-title-fields">
    <label :for="ids.input" class="block text-sm font-medium">{{ t('other_items.plans.title_template') }}</label>
    <input :id="ids.input" :value="model.title_template ?? ''" type="text" maxlength="255"
      :placeholder="t('other_items.plans.title_template_placeholder')"
      :aria-invalid="error?.field === 'title_template' ? 'true' : undefined"
      :aria-describedby="error?.field === 'title_template' ? `${ids.error} ${ids.hint}` : ids.hint"
      :class="['block h-9 w-full rounded-md border bg-surface px-2 text-sm', error?.field === 'title_template' ? 'border-danger-500' : 'border-neutral-300']"
      data-test="schedule-title-template" @input="setTemplate(($event.target as HTMLInputElement).value)" />
    <p v-if="error?.field === 'title_template'" :id="ids.error" role="alert" class="text-xs text-danger-600" data-test="schedule-title-error">{{ errorMessage }}</p>
    <p :id="ids.hint" class="text-xs text-neutral-500">{{ t('other_items.plans.title_template_hint') }}</p>
    <label class="flex items-center gap-2 text-sm">
      <input :checked="model.title_sync_period" type="checkbox"
        :aria-invalid="error?.field === 'title_sync_period' ? 'true' : undefined"
        :aria-describedby="error?.field === 'title_sync_period' ? ids.syncError : undefined"
        data-test="schedule-title-sync" @change="setSync(($event.target as HTMLInputElement).checked)" />
      {{ t('other_items.plans.title_sync_period') }}
    </label>
    <p v-if="error?.field === 'title_sync_period'" :id="ids.syncError" role="alert" class="text-xs text-danger-600" data-test="schedule-title-sync-error">{{ errorMessage }}</p>
    <p class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm" aria-live="polite" data-test="schedule-title-preview">
      <template v-if="preview">
        <span class="text-neutral-500">{{ t('other_items.plans.title_preview', { date: formatDate(preview.issued_on) }) }}</span>
        <span class="ml-1 break-words font-medium">{{ preview.title }}</span>
      </template>
      <span v-else-if="previewLoading" class="text-neutral-500">{{ t('other_items.plans.title_preview_loading') }}</span>
      <span v-else-if="previewProblem" class="text-neutral-500" data-test="schedule-title-preview-problem">{{ previewProblem }}</span>
      <span v-else class="text-neutral-500">—</span>
    </p>
    <!-- Nápověda zástupných znaků jako u opakované faktury — defaultně zabalená. -->
    <div>
      <button type="button" class="cursor-pointer inline-flex items-center gap-1 text-xs text-primary-700 hover:text-primary-800"
        :aria-expanded="helpOpen" data-test="schedule-title-help-toggle" @click="helpOpen = !helpOpen">
        <svg :class="['h-3 w-3 transition-transform', helpOpen ? 'rotate-90' : '']" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.17 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
        </svg>
        {{ t('other_items.plans.title_help_toggle') }}
      </button>
      <div v-if="helpOpen" class="mt-2 space-y-2 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600" data-test="schedule-title-help">
        <p>{{ t('other_items.plans.title_help_intro') }}</p>
        <dl class="space-y-1">
          <div v-for="(row, i) in helpRows" :key="i" class="flex gap-2">
            <dt class="shrink-0 whitespace-nowrap font-mono text-neutral-800">{{ row.c }}</dt>
            <dd>{{ row.d }}</dd>
          </div>
        </dl>
        <p class="text-neutral-500">{{ t('other_items.plans.title_help_example') }}</p>
      </div>
    </div>
  </div>
</template>
