<script setup lang="ts">
import { btnOutlineSm } from '@/components/ui/buttonStyles'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accountingApi, type JournalRelatedItem, type JournalLine } from '@/api/accounting'
import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'
import type { PermissionKey } from '@/security/permissions'

/**
 * Panel „Souvisí" — protějšky zápisu v grafu doklad ↔ úhrada.
 *
 * Deník vede fakturu a její úhradu jako dva nezávislé zápisy; účetní je ale řeší
 * jako jeden případ. Panel proto u každého protějšku nabízí OBOJE: zaúčtování
 * (náhled i odskok do deníku) a zdrojový doklad — bez hledání ve filtrech.
 *
 * Používá se na dvou místech (rozbalený řádek deníku i náhledový drawer), proto
 * si data tahá sám z /journal/{id}/related a nedostává je propsem.
 */
const props = withDefaults(defineProps<{
  entryId: number
  /** Ve draweru dává smysl přepnout náhled na protějšek; v seznamu ne. */
  showPreview?: boolean
}>(), { showPreview: false })

const emit = defineEmits<{
  (e: 'preview', entryId: number): void
  /** Odskok na protějšek v deníku — řeší stránka, viz onEntryClick(). */
  (e: 'focus-entry', entryId: number): void
  /** Kolik protějšků se našlo — volající může panel schovat, když je prázdný. */
  (e: 'loaded', count: number): void
}>()

const { t } = useI18n()
const auth = useAuthStore()

const loading = ref(false)
const items = ref<JournalRelatedItem[]>([])
const truncated = ref(false)
const failed = ref(false)

async function load(id: number) {
  loading.value = true
  failed.value = false
  try {
    const r = await accountingApi.getJournalRelated(id)
    items.value = r.items
    truncated.value = r.truncated
    emit('loaded', r.items.length)
  } catch {
    // Panel je doplňková navigace — když se nenačte, nesmí shodit celý detail zápisu.
    items.value = []
    truncated.value = false
    failed.value = true
    emit('loaded', 0)
  } finally {
    loading.value = false
  }
}

/**
 * Rozpad protějšku na účty, klíčovaný jeho `entry_id`.
 *
 * Účetní u vazby doklad ↔ úhrada nejčastěji ověřuje právě to, JAK je protějšek
 * zaúčtovaný — dosud kvůli tomu musel odskočit do deníku. Endpoint `related`
 * řádky nevrací, tak se dotáhnou zvlášť; protějšků bývá jeden nebo dva, takže
 * je to jeden dva dotazy navíc, a selhání se tiše přejde — panel je doplněk,
 * ne nosná informace.
 */
const relatedLines = ref<Record<number, JournalLine[]>>({})

async function loadRelatedLines(): Promise<void> {
  const ids = items.value.map(i => i.entry_id).filter((id): id is number => id !== null)
  await Promise.all(ids.map(async (id) => {
    if (relatedLines.value[id]) return
    try {
      const detail = await accountingApi.getEntry(id)
      relatedLines.value = { ...relatedLines.value, [id]: detail.lines }
    } catch { /* protějšek zůstane bez rozpadu */ }
  }))
}

watch(() => props.entryId, id => {
  if (id > 0) void load(id).then(loadRelatedLines)
}, { immediate: true })

function typeLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
}

/**
 * Barva odznaku podle druhu hrany. Odvozené hrany (úhrada / hrazený doklad) plynou
 * z evidence plateb, ruční vazba (migrace 1514) je tvrzení uživatele — při kontrole
 * je to zásadní rozdíl, takže nesmí vypadat stejně.
 */
function relationBadge(relation: JournalRelatedItem['relation']): string {
  if (relation === 'payment') return 'bg-success-50 text-success-600'
  if (relation === 'document') return 'bg-primary-100 text-primary-700'
  return 'bg-accent-50 text-accent-700'
}

/** Alokovaná částka se ukazuje jen když se liší od celkové (splátka, souhrnná platba). */
function showsAllocation(it: JournalRelatedItem): boolean {
  return it.allocated_amount !== null && it.amount !== null
    && Math.abs(it.allocated_amount - it.amount) >= 0.005
}

function canOpenDocument(it: JournalRelatedItem): boolean {
  return it.route !== null && auth.canRead(it.permission as PermissionKey)
}

function entryRoute(entryId: number) {
  return { path: '/accounting/journal', query: { entry_id: String(entryId) } }
}

/**
 * Prostý levý klik zpracuje stránka deníku sama (zavře drawer, odfiltruje na zápis
 * a rozbalí ho). Přes RouterLink to nejde: panel se otevírá NAD deníkem, takže cíl
 * je tatáž routa — a když je v adrese už `?entry_id=` téhož zápisu (typicky když se
 * v draweru vracíš na výchozí zápis), vue-router navigaci vyhodnotí jako no-op
 * a klik navenek neudělá nic.
 *
 * Odkaz ale zůstává odkazem: `href` drží platný cíl, takže Ctrl/⌘/prostřední klik
 * dál otevře zápis v nové kartě — ty klikům nesaháme.
 */
function onEntryClick(e: MouseEvent, entryId: number): void {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return
  e.preventDefault()
  emit('focus-entry', entryId)
}
</script>

<template>
  <section v-if="loading || items.length || failed">
    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-neutral-500">
      {{ t('accounting.journal.related.title') }}
    </h3>

    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="failed" class="text-sm text-neutral-500">{{ t('accounting.journal.related.failed') }}</p>

    <ul v-else class="divide-y divide-neutral-200 rounded-lg border border-neutral-200">
      <li v-for="it in items" :key="`${it.source_type}:${it.source_id}`"
          class="px-3 py-2.5 text-sm">
      <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <!-- Ruční vazba má vlastní barvu: odvozená hrana (úhrada/doklad) plyne
                 z evidence plateb, tuhle někdo zadal — a to je při kontrole rozdíl. -->
            <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="relationBadge(it.relation)">
              {{ t(`accounting.journal.related.relation.${it.relation}`) }}
            </span>
            <span class="text-xs text-neutral-500">{{ typeLabel(it.source_type) }}</span>
            <span class="font-medium">{{ it.title || `#${it.source_id}` }}</span>
            <span v-if="it.date" class="text-xs text-neutral-500">{{ formatDate(it.date) }}</span>
          </div>
          <p v-if="it.subtitle" class="mt-0.5 truncate text-xs text-neutral-500">{{ it.subtitle }}</p>
          <p class="mt-0.5 font-mono text-xs text-neutral-600">
            {{ formatMoney(it.amount ?? 0, it.currency || 'CZK') }}
            <span v-if="showsAllocation(it)" class="text-neutral-500">
              ({{ t('accounting.journal.related.allocated') }}
              {{ formatMoney(it.allocated_amount ?? 0, it.currency || 'CZK') }})
            </span>
          </p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-1.5">
          <!--
            Nezaúčtovaný protějšek je sám o sobě nález (saldo nesedí s deníkem),
            proto se místo prokliku vypíše důvod, ne prázdné místo.
          -->
          <span v-if="it.entry_id === null"
                class="rounded bg-warning-50 px-1.5 py-0.5 text-xs font-medium text-warning-600 whitespace-nowrap">
            {{ t('accounting.journal.related.not_posted') }}
          </span>
          <template v-else>
            <button v-if="showPreview" type="button" @click="emit('preview', it.entry_id!)"
                    :class="btnOutlineSm('primary')">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              {{ t('accounting.journal.related.preview') }}
            </button>
            <RouterLink :to="entryRoute(it.entry_id!)" @click="onEntryClick($event, it.entry_id!)"
                        :class="btnOutlineSm('neutral')">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.247m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.247" />
              </svg>
              {{ t('accounting.journal.related.open_entry') }} #{{ it.entry_id }}
            </RouterLink>
          </template>
          <RouterLink v-if="canOpenDocument(it)"
                      :to="{ name: it.route!.name, params: it.route!.params, query: it.route!.query }"
                      :class="btnOutlineSm('neutral')">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            {{ t('accounting.journal.related.open_document') }}
          </RouterLink>
        </div>
      </div>

      <!-- Jak je protějšek zaúčtovaný. Přesně tohle účetní u vazby doklad ↔
           úhrada ověřuje nejčastěji a dosud kvůli tomu musel odskočit do deníku.
           Tatáž komponenta jako u prohlíženého zápisu, jen kompaktní. -->
      <JournalLinesTable v-if="it.entry_id !== null && relatedLines[it.entry_id]?.length"
        class="mt-2" dense :lines="relatedLines[it.entry_id]!" :context-date="it.date" />
      </li>

      <li v-if="truncated" class="bg-neutral-50 px-3 py-1.5 text-xs text-neutral-500">
        {{ t('accounting.journal.related.truncated', { shown: items.length }) }}
      </li>
    </ul>
  </section>
</template>
