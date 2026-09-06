<script setup lang="ts">
import { ref, computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import type { BankTransaction } from '@/api/bank'
import { bankPostingApi, bankPostingErrorMessage, type PostResult } from '@/api/bankPosting'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PostTransactionModal from './PostTransactionModal.vue'
import RepostModal from '@/components/accounting/RepostModal.vue'

const props = defineProps<{ tx: BankTransaction; currency: string }>()
const emit = defineEmits<{ changed: []; posted: [{ result: PostResult; debit: string; credit: string }] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const posting = computed(() => props.tx.posting ?? null)
const isIgnored = computed(() => props.tx.match_status === 'ignored')
const periodClosed = computed(() => props.tx.period_closed === true || posting.value?.note === 'period_closed')

// Cizí měnu ručně zaúčtovat LZE — automatika ji odmítá (pravidla pracují s CZK částkou), ale
// člověk s doklady v ruce ji zaúčtovat umí. Bez toho by cizoměnové pohyby visely ve frontě navždy
// a nešly zaúčtovat vůbec nijak (back-end to povoluje přes assertPostableTx(allowForeign: true)).
const canPost = computed(() =>
  auth.canWrite('bank.post') && !isIgnored.value && !periodClosed.value
    && (posting.value === null || posting.value.status === null),
)

const busy = ref(false)
const showModal = ref(false)
const repostOpen = ref(false)

/**
 * Přeúčtovat = OPRAVA kontace, která v deníku je — na rozdíl od „Zrušit zaúčtování",
 * které pohyb vrátí do fronty a nechá ho nezaúčtovaný. Bez toho se špatná kontace
 * opravovala jen tou okliku (unpost → znovu zaúčtovat), která v zamčeném období
 * vůbec nejde: `unpost` odmítne storno mimo otevřené a nezamčené období, kdežto
 * přeúčtování tam zápis stornuje protizápisem a opravu zapíše novým zápisem (§35).
 *
 * Bankovní invarianty (pohyb na 221 = částka výpisu, analytika vlastního účtu) hlídá
 * server toutéž cestou jako u ručního zaúčtování — viz BankPostingService::prepareRepostLines().
 */
const canRepost = computed(() =>
  posting.value?.status === 'posted' && auth.canWrite('accounting'))

async function onReposted() {
  toast.success(t('accounting.repost.done'))
  emit('changed')
}

// Inline override kontace při schvalování
const overrideOpen = ref(false)
const accounts = ref<ChartAccount[]>([])
const activeAccounts = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
const overrideDebit = ref('')
const overrideCredit = ref('')
const accountListId = `posting-row-actions-coa-${useId()}`

async function toggleOverride() {
  overrideOpen.value = !overrideOpen.value
  if (overrideOpen.value) {
    overrideDebit.value = posting.value?.debit_account_code ?? ''
    overrideCredit.value = posting.value?.credit_account_code ?? ''
    if (accounts.value.length === 0) {
      try { accounts.value = await accountingApi.listAccounts() } catch { /* datalist jen našeptává */ }
    }
  }
}

async function approve() {
  if (busy.value || !posting.value?.suggestion_id) return
  busy.value = true
  try {
    const overrides = overrideOpen.value && overrideDebit.value && overrideCredit.value
      ? { debit_account_code: overrideDebit.value, credit_account_code: overrideCredit.value }
      : undefined
    await bankPostingApi.approveSuggestion(posting.value.suggestion_id, overrides)
    toast.success(t('bank.posting.posted_done'))
    emit('changed')
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
    emit('changed') // suggestion_not_pending → parent reload
  } finally {
    busy.value = false
  }
}

async function reject() {
  if (busy.value || !posting.value?.suggestion_id) return
  busy.value = true
  try {
    const res = await bankPostingApi.rejectSuggestion(posting.value.suggestion_id)
    if (res.rule_disabled) toast.warning(t('bank.posting.rule_disabled_toast'))
    emit('changed')
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
    emit('changed')
  } finally {
    busy.value = false
  }
}

async function unpost() {
  if (busy.value) return
  if (!confirm(t('bank.posting.unpost_confirm'))) return
  busy.value = true
  try {
    await bankPostingApi.unpost(props.tx.id)
    toast.success(t('bank.posting.unpost_done'))
    emit('changed')
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    busy.value = false
  }
}

function onPosted(payload: { result: PostResult; debit: string; credit: string }) {
  showModal.value = false
  emit('posted', payload)
  emit('changed')
}
</script>

<template>
  <div class="inline-flex flex-col items-end gap-1">
    <!-- Zaúčtovat… -->
    <button v-if="canPost" @click="showModal = true" :disabled="busy" :class="btnFilledSm('primary')">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin"/></svg>
      {{ t('bank.posting.action_post') }}
    </button>

    <!-- Návrh: Schválit / Odmítnout -->
    <template v-else-if="posting?.status === 'suggested' && auth.canWrite('bank.post')">
      <div class="inline-flex items-center gap-1">
        <button @click="approve" :disabled="busy || periodClosed"
          :title="periodClosed ? t('bank.posting.period_closed_badge') : undefined"
          :class="btnFilledSm('success')">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check"/></svg>
          {{ t('bank.posting.action_approve') }}
        </button>
        <button @click="reject" :disabled="busy" :class="btnOutlineSm('neutral')">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/></svg>
          {{ t('bank.posting.action_reject') }}
        </button>
        <button @click="toggleOverride" :disabled="busy"
          class="cursor-pointer text-neutral-400 hover:text-neutral-600 px-1" :title="t('bank.posting.override_accounts')">⚙</button>
      </div>
      <div v-if="overrideOpen" class="inline-flex items-center gap-1 mt-1">
        <input v-model="overrideDebit" :list="accountListId" type="text" :placeholder="t('bank.posting.debit')"
          class="w-20 h-7 px-1.5 border border-neutral-300 rounded text-xs font-mono" />
        <span class="text-neutral-400">/</span>
        <input v-model="overrideCredit" :list="accountListId" type="text" :placeholder="t('bank.posting.credit')"
          class="w-20 h-7 px-1.5 border border-neutral-300 rounded text-xs font-mono" />
      <datalist :id="accountListId">
          <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
        </datalist>
      </div>
    </template>

    <!-- Zaúčtováno: opravit kontaci (Přeúčtovat) nebo zaúčtování zrušit úplně. -->
    <div v-else-if="posting?.status === 'posted' && (canRepost || auth.canWrite('bank.unpost'))"
      class="inline-flex flex-wrap items-center justify-end gap-1">
      <!-- Administrativní zásah do už zaúčtovaného pohybu → warning, ne primary. -->
      <button v-if="canRepost" @click="repostOpen = true" :disabled="busy" :class="btnOutlineSm('warning')">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit"/></svg>
        {{ t('accounting.repost.action') }}
      </button>
      <button v-if="auth.canWrite('bank.unpost')" @click="unpost" :disabled="busy" :class="btnOutlineSm('neutral')">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn"/></svg>
        {{ t('bank.posting.action_unpost') }}
      </button>
    </div>

    <PostTransactionModal v-if="showModal" :tx="tx" :currency="currency"
      @posted="onPosted" @close="showModal = false" />

    <Teleport to="body">
      <RepostModal v-if="repostOpen" :open="repostOpen" source="bank-transactions" :doc-id="tx.id"
        :doc-label="tx.description || tx.variable_symbol"
        @close="repostOpen = false" @reposted="onReposted" />
    </Teleport>
  </div>
</template>
