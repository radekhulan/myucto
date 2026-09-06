<script setup lang="ts">
/**
 * „Podle jaké šablony tenhle zápis vznikl" — řádek v sekci Zaúčtování.
 *
 * Sekce dosud ukazovala jen výsledné účty. Účty ale nevybírá doklad, nýbrž
 * **předkontace** (`posting_rules`, stabilní klíč jako `invoice.services.received`),
 * a ta v UI nebyla vidět nikde — účetní tedy viděla 518, netušila, že se dá změnit
 * jedním řádkem v Nástrojích, a opravovala kontaci na každém dokladu zvlášť.
 *
 * Vrstvy se liší podle druhu dokladu (manuál § 43.2.1):
 *   - vydaná faktura → předkontace podle klíče výnosu na hlavičce,
 *   - přijatá faktura → předkontace podle druhu výdaje na řádcích,
 *   - bankovní pohyb → pravidlo účtování / vestavěné rozpoznání / naučená kontace /
 *     spárovaná platba (předkontace `payment.*`).
 *
 * NÁKLADOVÉ pravidlo (druh řádku přijaté faktury) tu schválně NENÍ: ukazuje se
 * o kus výš v téže sekci u klasifikace dokladu, i s vlastním editačním dialogem.
 * Druhé místo pro tutéž věc by znamenalo dvě cesty k jedné opravě.
 *
 * Tlačítka vedou na EXISTUJÍCÍ obrazovky (Nástroje → Účetní nastavení → Předkontace,
 * Nástroje → Šablony účtování → Pravidla účtování), ne na vlastní formulář.
 * Když zdroj určit nejde, řekne se to — šablona, která se nepoužila, se nepředstírá.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/composables/useFormat'
import { accountingApi, type JournalPostingSource, type PostingOrigin } from '@/api/accounting'

const props = defineProps<{ source: JournalPostingSource; docId: number }>()

const { t } = useI18n()
const auth = useAuthStore()

const origin = ref<PostingOrigin | null>(null)

async function load(docId: number): Promise<void> {
  origin.value = null
  if (!(docId > 0)) return
  try {
    origin.value = await accountingApi.postingOrigin(props.source, docId)
  } catch {
    // Doplňková informace — když se nenačte, nesmí shodit sekci Zaúčtování.
  }
}

watch(() => props.docId, id => { void load(id) }, { immediate: true })
defineExpose({ reload: () => load(props.docId) })

const bankRules = computed(() => origin.value?.rules.filter(r => r.type === 'bank') ?? [])

/** Předkontace se ukazují jen u zaúčtovaného dokladu — jinak není o čem tvrdit, že „vznikl podle". */
const presets = computed(() => (origin.value?.posted ? origin.value.presets : []))

/**
 * Kontace v deníku neodpovídá ani jedné z odvozených předkontací → za účty stojí
 * něco jiného (ruční zápis, nastavení v době účtování). To se musí říct.
 */
const presetsUnused = computed(() =>
  presets.value.length > 0 && presets.value.every(p => !p.used))

const manuallyMade = computed(() =>
  origin.value?.origin === 'manual' || origin.value?.origin === 'unknown')

const canEditPresets = computed(() => auth.canWrite('accounting.templates'))
const canEditBankRules = computed(() => auth.canWrite('bank.rules'))

const editLinkClass =
  'cursor-pointer text-primary-600 hover:text-primary-700 hover:underline '
  + 'inline-flex items-center gap-1 text-xs whitespace-nowrap'
</script>

<template>
  <div v-if="origin && origin.posted" class="text-sm">
    <div class="text-neutral-500 mb-1">{{ t('accounting.posting_origin.label') }}</div>
    <div class="space-y-1">
      <!-- Ruční přeúčtování přebíjí všechno: účty vybrala účetní, ne šablona. -->
      <p v-if="origin.manual_repost" class="text-warning-700">
        {{ t('accounting.posting_origin.manual_repost', {
          date: formatDate(origin.manual_repost.at),
          user: origin.manual_repost.by ?? t('accounting.posting_origin.unknown_user'),
        }) }}
      </p>

      <div v-for="p in presets" :key="p.rule_key" class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="text-neutral-700">
          {{ t('accounting.posting_origin.preset') }}:
          <span class="font-medium">{{ p.description || p.rule_key }}</span>
          <span class="ml-1 font-mono text-xs text-neutral-400">{{ p.rule_key }}</span>
        </span>
        <span v-if="p.exists" class="font-mono text-xs text-neutral-500">
          {{ p.debit_account_code || '—' }} / {{ p.credit_account_code || '—' }}
        </span>
        <span class="text-xs" :class="p.scope === 'company' ? 'text-primary-600' : 'text-neutral-400'">
          ({{ t('accounting.posting_origin.scope_' + (p.scope ?? 'missing')) }})
        </span>
        <span v-if="!p.used" class="text-xs text-warning-700">
          {{ t('accounting.posting_origin.preset_unused') }}
        </span>
        <RouterLink v-if="canEditPresets"
          :to="{ path: '/utilities', query: { section: 'posting-rules', rule_key: p.rule_key } }"
          :class="editLinkClass">
          {{ t('accounting.posting_origin.edit_preset') }}
        </RouterLink>
      </div>

      <div v-for="r in bankRules" :key="r.id" class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="text-neutral-700">
          {{ t('accounting.posting_origin.bank_rule') }}:
          <span v-if="r.exists" class="font-medium">{{ r.name }}</span>
          <span v-else class="text-warning-700">
            {{ t('accounting.posting_origin.rule_deleted', { id: r.id }) }}
          </span>
        </span>
        <RouterLink v-if="canEditBankRules && r.exists"
          :to="{ path: '/templates', query: { section: 'posting' } }" :class="editLinkClass">
          {{ t('accounting.posting_origin.edit_bank_rule') }}
        </RouterLink>
      </div>

      <p v-if="origin.origin === 'detector'" class="text-neutral-500">
        {{ t('accounting.posting_origin.detector', { detector: origin.detector ?? '—' }) }}
      </p>
      <p v-else-if="origin.origin === 'learned'" class="text-neutral-500">
        {{ t('accounting.posting_origin.learned') }}
      </p>
      <p v-else-if="origin.origin === 'ai'" class="text-neutral-500">
        {{ t('accounting.posting_origin.ai') }}
      </p>
      <p v-else-if="origin.origin === 'schedule'" class="text-neutral-500">
        {{ t('accounting.posting_origin.schedule') }}
      </p>

      <!-- Pravdivý fallback: ruční zápis nebo doklad z doby před evidencí provenience. -->
      <p v-if="manuallyMade && presets.length === 0 && bankRules.length === 0" class="text-neutral-500">
        {{ t('accounting.posting_origin.none') }}
      </p>
      <p v-else-if="presetsUnused" class="text-xs text-neutral-500">
        {{ t('accounting.posting_origin.preset_unused_hint') }}
      </p>
    </div>
  </div>
</template>
