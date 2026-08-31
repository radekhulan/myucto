<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import { closingApi, type ClosingState, type StatementNotes } from '@/api/closing'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

const periodId = Number(route.params.id)
const canWrite = computed(() => auth.canWrite('accounting'))

const state = ref<ClosingState | null>(null)
const notes = ref<StatementNotes | null>(null)
const loading = ref(true)
const error = ref('')

// Rozeditované texty per sekce — uložený obsah z API se drží zvlášť, ať jde poznat
// „změněno, neuloženo" a tlačítko Uložit svítí jen tam, kde má co uložit.
const drafts = ref<Record<string, string>>({})
const savingAll = ref(false)
const carryingOver = ref(false)

function applyNotes(n: StatementNotes) {
  notes.value = n
  const d: Record<string, string> = {}
  for (const s of n.sections) d[s.key] = s.content ?? ''
  drafts.value = d
}

function isDirty(key: string): boolean {
  const stored = notes.value?.sections.find(s => s.key === key)?.content ?? ''
  return (drafts.value[key] ?? '') !== stored
}

const dirtyKeys = computed(() => notes.value?.sections.filter(s => isDirty(s.key)).map(s => s.key) ?? [])
const filledCount = computed(() => notes.value?.sections.filter(s => s.filled).length ?? 0)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [st, n] = await Promise.all([closingApi.state(periodId), closingApi.statementNotes(periodId)])
    state.value = st
    applyNotes(n)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

// Jedno společné Uložit — uloží všechny rozeditované sekce najednou (API je per
// sekce, takže se PUTuje postupně a aplikuje se poslední vrácený stav). Tlačítko
// u každé sekce bylo zbytečné klikání.
// Převzetí loňské přílohy. Přepíše se jen to, co je letos prázdné — vlastní text
// účetní se nikdy nepřebije — a převzaté sekce si nesou rok původu, dokud je účetní
// neuloží jako své.
async function carryOver() {
  if (!canWrite.value || carryingOver.value) return
  carryingOver.value = true
  try {
    const res = await closingApi.carryOverStatementNotes(periodId)
    applyNotes(res.notes)
    toast.success(t('accounting.statement_notes.carried_over_done', { count: res.carried.length }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    carryingOver.value = false
  }
}

async function saveAll() {
  if (!canWrite.value || savingAll.value || dirtyKeys.value.length === 0) return
  savingAll.value = true
  try {
    // Drafty se čtou předem — applyNotes() po posledním PUTu je resetuje.
    const toSave = dirtyKeys.value.map(key => {
      const raw = drafts.value[key] ?? ''
      return { key, content: raw.trim() !== '' ? raw : null }
    })
    let fresh: StatementNotes | null = null
    for (const s of toSave) {
      fresh = await closingApi.saveStatementNote(periodId, s.key, s.content)
    }
    if (fresh) applyNotes(fresh)
    toast.success(t('accounting.statement_notes.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
    // Částečně uložený stav — načti čerstvý, ať badge/drafty odpovídají serveru.
    try { applyNotes(await closingApi.statementNotes(periodId)) } catch { /* ponech */ }
  } finally {
    savingAll.value = false
  }
}

function scopeBadge(scope: string): string | null {
  if (scope === 'audited') return t('accounting.statement_notes.scope_audited')
  if (scope === 'large') return t('accounting.statement_notes.scope_large')
  return null
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div class="flex items-center justify-between mb-1 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.statement_notes.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.statement_notes.subtitle') }}</p>
        <p v-if="state" class="text-sm text-neutral-600 mt-1">
          {{ state.period.fiscal_year }} · {{ formatDate(state.period.starts_on) }} – {{ formatDate(state.period.ends_on) }}
        </p>
      </div>
      <RouterLink :to="{ name: 'accounting-closing-package', params: { id: periodId } }" class="text-sm text-neutral-500 hover:text-neutral-700">
        {{ t('accounting.statement_notes.back_to_package') }}
      </RouterLink>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-600 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="text-sm text-neutral-400 py-8 text-center">…</div>

    <template v-else-if="notes">
      <!-- Stav úplnosti — u uzavřeného roku jde doplnit taky: příloha se ukládá per
           fiskální rok a dopisuje se v průběhu uzávěrky i po ní. -->
      <div
        class="rounded-md p-3 text-sm border"
        :class="notes.complete
          ? 'bg-success-50 border-success-500/30 text-success-700'
          : 'bg-warning-50 border-warning-500/30 text-warning-700'"
      >
        <template v-if="notes.complete">{{ t('accounting.statement_notes.complete') }}</template>
        <template v-else>{{ t('accounting.statement_notes.incomplete', { filled: filledCount, total: notes.sections.length }) }}</template>
      </div>

      <div v-if="!canWrite" class="text-xs text-neutral-500">
        {{ t('accounting.statement_notes.readonly_hint') }}
      </div>

      <!-- Převzetí loňských textů je nabídka, ne automatika: většina přílohy se rok
           od roku opakuje, ale některá loňská věta už letos platit nemusí. Proto se
           přebírá jen do prázdných sekcí a převzaté se označí ke kontrole. -->
      <div v-if="canWrite && notes.carry_over.available > 0"
        class="rounded-md border border-primary-500/30 bg-primary-50 p-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-primary-800">
          {{ t('accounting.statement_notes.carry_over_offer', {
            count: notes.carry_over.available,
            year: notes.carry_over.source_year,
          }) }}
        </p>
        <button type="button" :class="btnOutline('primary')" :disabled="carryingOver" @click="carryOver">
          {{ t('accounting.statement_notes.carry_over_action', { year: notes.carry_over.source_year }) }}
        </button>
      </div>

      <div v-for="s in notes.sections" :key="s.key"
        class="bg-surface border rounded-lg p-4 shadow-sm space-y-2"
        :class="s.filled ? 'border-neutral-200' : 'border-warning-500/40'">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h2 class="text-sm font-semibold text-neutral-800">{{ s.label }}</h2>
              <span v-if="s.auto" class="text-[11px] px-1.5 py-0.5 rounded bg-primary-100 text-primary-700">
                {{ t('accounting.statement_notes.auto_badge') }}
              </span>
              <span v-if="scopeBadge(s.scope)" class="text-[11px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600">
                {{ scopeBadge(s.scope) }}
              </span>
              <span v-if="s.carried_over_from_year"
                class="text-[11px] px-1.5 py-0.5 rounded bg-warning-100 text-warning-800">
                {{ t('accounting.statement_notes.carried_over_badge', { year: s.carried_over_from_year }) }}
              </span>
            </div>
            <p class="text-xs text-neutral-500 mt-0.5">{{ s.legal }}</p>
            <!-- Upozornění stojí u sekce, ne v nápovědě stránky: účetní ho musí
                 vidět ve chvíli, kdy tu sekci píše, ne až po odevzdání výkazu. -->
            <p v-if="s.hint" class="text-xs text-warning-700 mt-1 max-w-2xl">{{ s.hint }}</p>
          </div>
          <span class="text-xs font-semibold px-2 py-0.5 rounded shrink-0"
            :class="s.filled ? 'bg-success-100 text-success-700' : 'bg-warning-100 text-warning-700'">
            {{ s.filled ? t('accounting.statement_notes.filled') : t('accounting.statement_notes.missing') }}
          </span>
        </div>

        <textarea
          v-model="drafts[s.key]"
          rows="4"
          :disabled="!canWrite"
          :placeholder="s.auto ? t('accounting.statement_notes.auto_placeholder') : t('accounting.statement_notes.placeholder')"
          class="w-full text-sm border border-neutral-300 rounded-md p-2.5 focus:ring-primary-500 focus:border-primary-500 disabled:bg-neutral-50 disabled:text-neutral-500"
        ></textarea>

        <p v-if="s.auto" class="text-[11px] text-neutral-400">{{ t('accounting.statement_notes.auto_hint') }}</p>
        <p v-if="s.carried_over_from_year" class="text-[11px] text-warning-700">
          {{ t('accounting.statement_notes.carried_over_hint', { year: s.carried_over_from_year }) }}
        </p>
      </div>

      <!-- Jedno společné Uložit pro všechny rozeditované sekce — sticky, ať je po
           editaci kterékoli sekce po ruce bez scrollování. -->
      <div v-if="canWrite" class="sticky bottom-4 flex items-center justify-end gap-3 bg-surface/95 backdrop-blur border border-neutral-200 rounded-lg px-4 py-3 shadow-md">
        <span v-if="dirtyKeys.length" class="text-xs text-neutral-500">
          {{ t('accounting.statement_notes.unsaved', { count: dirtyKeys.length }) }}
        </span>
        <button
          type="button"
          @click="saveAll"
          :disabled="dirtyKeys.length === 0 || savingAll"
          :class="btnFilled('primary')"
        >
          {{ savingAll ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </template>
  </div>
</template>
