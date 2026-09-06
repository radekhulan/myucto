<script setup lang="ts">
/**
 * Přeúčtování už zaúčtovaného dokladu — oprava kontace, která v deníku je.
 *
 * Do zavedení tohohle dialogu se chybná kontace opravovala ručně přes účetní deník
 * (najdi zápis → smaž nebo stornuj → vrať se na doklad → zaúčtuj znovu) a účetní
 * musela sama uhodnout, KTERÝ z těch dvou postupů období dovolí. Špatný odhad se
 * projevil až v posledním kroku, kdy už byl původní zápis pryč.
 *
 * Rozhodnutí přepsat × stornovat × odmítnout NEDĚLÁ tenhle popup: přichází ze
 * serveru z `repost-plan` a provede ho tatáž služba, takže se náhled s výsledkem
 * nemůže rozejít. Popup jen ukáže, co se stane, a nechá upravit řádky.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi, postingErrorI18nKey,
  type ChartAccount, type JournalPostingSource, type RepostPlan,
} from '@/api/accounting'
import Modal from '../ui/Modal.vue'
import JournalLinesEditor, { type EditorLine } from './JournalLinesEditor.vue'
import PostingOriginRow from './PostingOriginRow.vue'
import { btnOutline, btnFilled } from '../ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{
  open: boolean
  /** `bank-transactions` = bankovní pohyb; bankovní invarianty (221 = částka výpisu) hlídá server. */
  source: JournalPostingSource
  docId: number
  /** Popisek dokladu do hlavičky (číslo faktury, u banky popis pohybu). */
  docLabel?: string | null
}>()

const emit = defineEmits<{ close: []; reposted: [] }>()

const { t } = useI18n()

const plan = ref<RepostPlan | null>(null)
const lines = ref<EditorLine[]>([])
const accounts = ref<ChartAccount[]>([])
const description = ref('')
const confirmShift = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editorRef = ref<InstanceType<typeof JournalLinesEditor> | null>(null)

const blocked = computed(() => plan.value?.strategy === 'blocked')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  plan.value = null
  lines.value = []
  confirmShift.value = false
  try {
    const [p, acc] = await Promise.all([
      accountingApi.repostPlan(props.source, props.docId),
      accounts.value.length > 0 ? Promise.resolve(accounts.value) : accountingApi.listAccounts(),
    ])
    plan.value = p
    accounts.value = acc
    description.value = p.description ?? ''
    // Předvyplní se PŮVODNÍ kontace, ne nový návrh ze systému: opravuje se to,
    // co v deníku opravdu je, a účetní musí vidět, co mění.
    lines.value = p.lines.map(l => ({
      account_code: l.account_code ?? '',
      side: l.side,
      amount: l.amount,
    }))
  } catch (e: any) {
    error.value = t(postingErrorI18nKey(e?.response?.data?.error?.code))
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.docId], ([open]) => { if (open) load() }, { immediate: true })

const canSubmit = computed(() =>
  !!plan.value && !blocked.value && !loading.value && !saving.value
  && (editorRef.value?.valid ?? false)
  && (!plan.value.date_shifted || confirmShift.value))

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  error.value = ''
  try {
    await accountingApi.repost(props.source, props.docId, {
      lines: lines.value.map(l => ({
        account_code: l.account_code,
        side: l.side,
        amount: l.amount ?? 0,
      })),
      description: description.value.trim() || null,
      confirm_date_shift: confirmShift.value,
    })
    emit('reposted')
    emit('close')
  } catch (e: any) {
    error.value = t(postingErrorI18nKey(e?.response?.data?.error?.code))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal v-if="open" :title="t('accounting.repost.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4">
      <p v-if="docLabel" class="text-sm text-neutral-600">
        {{ t('accounting.repost.document') }}: <span class="font-mono">{{ docLabel }}</span>
      </p>

      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

      <div v-if="error" class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
        {{ error }}
      </div>

      <template v-if="plan && !loading">
        <!-- Co se stane. Bez téhle věty by uživatel nepoznal rozdíl mezi „přepíše se"
             a „vznikne protizápis" — a přitom je to ten rozdíl, který zůstane v deníku. -->
        <div class="px-3 py-2 rounded-md text-sm border"
          :class="blocked
            ? 'bg-danger-50 border-danger-500/30 text-danger-600'
            : (plan.strategy === 'reverse'
              ? 'bg-warning-50 border-warning-500/30 text-warning-700'
              : 'bg-neutral-50 border-neutral-200 text-neutral-700')">
          <p class="font-medium">
            {{ t(`accounting.repost.strategy_${plan.strategy}`) }}
          </p>
          <p v-if="blocked" class="mt-1">
            {{ t(`accounting.repost.blocked_${plan.reason_code === 'date_locked' ? 'date_locked' : 'period_not_open'}`, {
              date: plan.locked_until ? formatDate(plan.locked_until) : '',
              status: plan.period_status ?? '',
            }) }}
          </p>
          <p v-else-if="plan.strategy === 'reverse'" class="mt-1">
            {{ t(`accounting.repost.reason_${plan.reason_code ?? 'period_not_open'}`, {
              status: plan.period_status ?? '',
              date: plan.locked_until ? formatDate(plan.locked_until) : '',
            }) }}
          </p>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-2 text-sm">
          <div class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.entry') }}</dt>
            <dd class="font-mono">{{ plan.document_no || `#${plan.entry_id}` }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.entry_date') }}</dt>
            <dd class="font-mono">{{ formatDate(plan.entry_date) }}</dd>
          </div>
          <div v-if="plan.target_date" class="flex justify-between gap-2">
            <dt class="text-neutral-500">{{ t('accounting.repost.target_date') }}</dt>
            <dd class="font-mono" :class="plan.date_shifted ? 'text-warning-700 font-semibold' : ''">
              {{ formatDate(plan.target_date) }}
            </dd>
          </div>
        </dl>

        <!-- Podle čeho kontace vznikla. Právě tady to má cenu: než účetní kontaci
             přepíše ručně, má vidět, jestli se nedá opravit rovnou šablona — jinak
             se týž zásah bude opakovat u každého dalšího dokladu. U bankovního
             pohybu je to zároveň jediné místo, kde se šablona ukazuje: řádek výpisu
             na ni místo nemá. -->
        <PostingOriginRow :source="source" :doc-id="docId" />

        <template v-if="!blocked">
          <label class="block text-sm">
            <span class="block text-neutral-500 mb-1">{{ t('accounting.repost.description') }}</span>
            <input v-model="description" type="text"
              class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>

          <JournalLinesEditor ref="editorRef" v-model="lines" :accounts="accounts" list-id="repost-coa" />

          <!-- Posun data se NIKDY nedělá potichu: zamčené období nedovolí zapsat
               k původnímu datu, ale rozdíl v deníku uvidí až účetní závěrka. -->
          <label v-if="plan.date_shifted" class="flex items-start gap-2 text-sm">
            <input v-model="confirmShift" type="checkbox" class="mt-0.5" />
            <span>
              {{ t('accounting.repost.confirm_date_shift', {
                from: formatDate(plan.entry_date),
                to: plan.target_date ? formatDate(plan.target_date) : '',
              }) }}
            </span>
          </label>

          <p class="text-xs text-neutral-500">{{ t('accounting.repost.hint') }}</p>
        </template>
      </template>

      <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-neutral-200">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('warning')" :disabled="!canSubmit" @click="submit">
          {{ saving ? t('common.saving') : t('accounting.repost.confirm') }}
        </button>
      </div>
    </div>
  </Modal>
</template>
