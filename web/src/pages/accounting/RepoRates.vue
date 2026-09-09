<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type RepoRate } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { useAuthStore } from '@/stores/auth'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const rates = ref<RepoRate[]>([])
const loading = ref(false)
const busy = ref(false)

const form = reactive({
  valid_from: '',
  rate: 0 as number,
  note: '',
})

async function load() {
  loading.value = true
  try {
    rates.value = await accountingApi.getRepoRates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function saveRate() {
  if (!form.valid_from || form.rate < 0) return
  busy.value = true
  try {
    rates.value = await accountingApi.upsertRepoRate({
      valid_from: form.valid_from,
      rate: form.rate,
      note: form.note || undefined,
    })
    toast.success(t('accounting.repo_rates.saved'))
    form.note = ''
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function removeRate(r: RepoRate) {
  if (!window.confirm(t('accounting.repo_rates.delete_confirm'))) return
  busy.value = true
  try {
    await accountingApi.deleteRepoRate(r.valid_from)
    rates.value = rates.value.filter(x => x.valid_from !== r.valid_from)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.repo_rates.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.repo_rates.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="space-y-6">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.repo_rates.hint') }}</p>

        <div v-if="auth.isSuperadmin" class="grid grid-cols-2 sm:grid-cols-4 gap-2 items-end mb-4">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.repo_rates.valid_from') }}</label>
            <DateInput v-model="form.valid_from"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.repo_rates.rate') }}</label>
            <input v-model.number="form.rate" type="number" step="0.001" min="0" max="100"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.repo_rates.note') }}</label>
            <input v-model="form.note" type="text"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <button :disabled="busy || !form.valid_from" @click="saveRate" :class="btnFilled('primary')">
              {{ t('accounting.repo_rates.add') }}
            </button>
          </div>
        </div>

        <EmptyState v-if="rates.length === 0" dense accent="neutral" icon="chart" :title="t('accounting.repo_rates.no_rates')" />
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.repo_rates.valid_from') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.repo_rates.rate') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.repo_rates.note') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.repo_rates.updated') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in rates" :key="r.valid_from" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ formatDate(r.valid_from) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ r.rate }} %</td>
              <td class="px-3 py-2 text-neutral-500">{{ r.note }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(r.updated_at) }}</td>
              <td class="px-3 py-2 text-right">
                <button v-if="auth.isSuperadmin" :disabled="busy" @click="removeRate(r)" :class="btnOutline('danger')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
