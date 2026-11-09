<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, postingErrorMessage, type DraftPostingPreview } from '@/api/accounting'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Náhled zápisu pod položkami editoru faktury. Server doklad uloží akcí Uložit
 * v transakci, která se odvolá, a vrátí návrh ze stejné služby jako „Zaúčtovat",
 * takže náhled ukazuje přesně to, co vznikne (včetně DPH a protiúčtu).
 *
 * `payload` = tělo dokladu jako při uložení; null = doklad ještě nejde uložit
 * (chybí partner, měna nebo položky) a náhled se nežádá.
 */
const props = defineProps<{
  source: 'invoices' | 'purchase-invoices'
  docId: number | null
  payload: object | null
}>()

const { t } = useI18n()

const preview = ref<DraftPostingPreview | null>(null)
const error = ref('')
const invalid = ref(false)
const loading = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null
let controller: AbortController | null = null

const key = computed(() => props.payload === null ? null : JSON.stringify([props.docId, props.payload]))

async function load(): Promise<void> {
  if (props.payload === null) return
  controller?.abort()
  const current = new AbortController()
  controller = current
  loading.value = true
  try {
    const data = await accountingApi.draftPostingPreview(props.source, props.docId, props.payload, current.signal)
    if (controller !== current) return
    preview.value = data
    error.value = ''
    invalid.value = false
  } catch (e: any) {
    if (controller !== current || e?.code === 'ERR_CANCELED') return
    preview.value = null
    const status = Number(e?.response?.status)
    invalid.value = status >= 400 && status < 500 && status !== 401 && status !== 403
    error.value = invalid.value ? '' : postingErrorMessage(t, e)
  } finally {
    if (controller === current) loading.value = false
  }
}

watch(key, (value) => {
  if (timer) clearTimeout(timer)
  if (value === null) {
    controller?.abort()
    preview.value = null
    error.value = ''
    invalid.value = false
    loading.value = false
    return
  }
  timer = setTimeout(() => { void load() }, 700)
}, { immediate: true })

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
  controller?.abort()
})

const lines = computed(() => preview.value?.lines ?? [])
const totals = computed(() => lines.value.reduce((acc, l) => {
  if (l.side === 'debit') acc.debit += l.amount
  else acc.credit += l.amount
  return acc
}, { debit: 0, credit: 0 }))

defineExpose({ refresh: load })
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm" data-test="draft-posting-preview">
    <header class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-b border-neutral-100">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-sm font-medium text-neutral-700">{{ t('accounting.editor_posting.title') }}</h2>
        <span v-if="preview && !preview.already_posted && lines.length"
          class="text-xs px-2 py-0.5 rounded-full whitespace-nowrap"
          :class="preview.balanced ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'"
          data-test="draft-posting-balanced">
          {{ preview.balanced ? t('accounting.editor_posting.balanced') : t('accounting.editor_posting.unbalanced') }}
        </span>
        <span v-if="loading" class="text-xs text-neutral-400">{{ t('accounting.editor_posting.loading') }}</span>
      </div>
      <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" :disabled="loading || payload === null"
        data-test="draft-posting-refresh" @click="load()">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('accounting.editor_posting.refresh') }}
      </button>
    </header>

    <div class="px-5 py-3 text-sm">
      <p v-if="payload === null" class="text-xs text-neutral-500">{{ t('accounting.editor_posting.incomplete') }}</p>
      <p v-else-if="invalid" class="text-xs text-neutral-500" data-test="draft-posting-invalid">{{ t('accounting.editor_posting.incomplete') }}</p>
      <p v-else-if="error" class="text-sm text-danger-600" role="alert" data-test="draft-posting-error">{{ error }}</p>
      <p v-else-if="preview?.already_posted" class="text-sm text-neutral-600" data-test="draft-posting-posted">
        {{ t('accounting.editor_posting.already_posted', { id: preview.already_posted }) }}
      </p>
      <template v-else-if="lines.length">
        <!-- Desktop -->
        <table class="hidden sm:table w-full text-sm">
          <thead>
            <tr class="text-xs text-neutral-500">
              <th class="text-left font-normal py-1 pr-2 w-28">{{ t('accounting.editor_posting.account') }}</th>
              <th class="text-left font-normal py-1 pr-2">{{ t('accounting.editor_posting.account_name') }}</th>
              <th class="text-right font-normal py-1 px-2 w-36">{{ t('accounting.editor_posting.debit') }}</th>
              <th class="text-right font-normal py-1 pl-2 w-36">{{ t('accounting.editor_posting.credit') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in lines" :key="i" class="border-t border-neutral-100">
              <td class="py-1 pr-2 font-mono">{{ l.account_code }}</td>
              <td class="py-1 pr-2 text-neutral-600 truncate max-w-0">{{ l.account_name ?? '' }}</td>
              <td class="py-1 px-2 text-right font-mono">{{ l.side === 'debit' ? formatMoney(l.amount, 'CZK') : '' }}</td>
              <td class="py-1 pl-2 text-right font-mono">{{ l.side === 'credit' ? formatMoney(l.amount, 'CZK') : '' }}</td>
            </tr>
            <tr class="border-t border-neutral-200 font-semibold">
              <td class="py-1 pr-2" colspan="2">{{ t('accounting.editor_posting.total') }}</td>
              <td class="py-1 px-2 text-right font-mono">{{ formatMoney(totals.debit, 'CZK') }}</td>
              <td class="py-1 pl-2 text-right font-mono">{{ formatMoney(totals.credit, 'CZK') }}</td>
            </tr>
          </tbody>
        </table>
        <!-- Mobil -->
        <ul class="sm:hidden divide-y divide-neutral-100">
          <li v-for="(l, i) in lines" :key="`m-${i}`" class="py-1.5 flex items-baseline justify-between gap-3">
            <span class="min-w-0">
              <span class="font-mono">{{ l.account_code }}</span>
              <span class="ml-1 text-xs text-neutral-500">{{ l.side === 'debit' ? t('accounting.editor_posting.debit') : t('accounting.editor_posting.credit') }}</span>
              <span class="block text-xs text-neutral-500 truncate">{{ l.account_name ?? '' }}</span>
            </span>
            <span class="font-mono whitespace-nowrap">{{ formatMoney(l.amount, 'CZK') }}</span>
          </li>
        </ul>
        <p class="mt-2 text-xs text-neutral-500">
          {{ t('accounting.editor_posting.hint') }}
          <span v-if="preview?.entry_date">{{ t('accounting.editor_posting.entry_date', { date: preview.entry_date }) }}</span>
        </p>
      </template>
      <p v-else-if="!loading" class="text-xs text-neutral-500">{{ t('accounting.editor_posting.empty') }}</p>
    </div>
  </section>
</template>
