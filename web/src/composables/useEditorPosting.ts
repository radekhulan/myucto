import { computed, ref } from 'vue'
import { accountingApi, type ChartAccount, type PostingRule } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

/**
 * Zaúčtování už při zadání faktury: předkontace a účty pro řádky editoru, náhled
 * zápisu pod položkami a „Uložit a zaúčtovat".
 *
 * Patří jen k podvojnému účetnictví a jen účetním: klientská role ani firma
 * v daňové evidenci nic z toho nevidí a editor se jim nemění.
 */
export function useEditorPosting() {
  const auth = useAuthStore()
  const supplierStore = useSupplierStore()
  const rules = ref<PostingRule[]>([])
  const accounts = ref<ChartAccount[]>([])
  let loading: Promise<void> | null = null

  // Zaúčtování na řádku a náhled zápisu vidí jen ten, kdo smí zaúčtovat; ostatním editor
  // tyto části nezobrazí, hodnoty řádku ale beze změny posílá zpět.
  const enabled = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry'
    && !auth.isClientRole && auth.canWrite('accounting.journal.post'))
  const canPost = computed(() => enabled.value && auth.saveAndPostEnabled === true
    && auth.canWrite('accounting.journal.post'))

  function load(): Promise<void> {
    if (!enabled.value) return Promise.resolve()
    // Číselníky jsou pro editor jen pomůcka: jejich chyba nesmí editor shodit.
    loading ??= Promise.all([
      Promise.resolve().then(() => accountingApi.listPostingRules())
        .then(map => { rules.value = Object.values(map ?? {}) }).catch(() => { rules.value = [] }),
      Promise.resolve().then(() => accountingApi.listAccounts())
        .then(list => { accounts.value = list ?? [] }).catch(() => { accounts.value = [] }),
    ]).then(() => undefined)
    return loading
  }

  /** Výchozí účet předkontace dokladu pro nápovědu v poli protiúčtu. */
  function ruleAccount(ruleKey: string, side: 'debit' | 'credit'): string | null {
    const rule = rules.value.find(r => r.rule_key === ruleKey)
    return (side === 'debit' ? rule?.debit_account_code : rule?.credit_account_code) ?? null
  }

  return { enabled, canPost, rules, accounts, load, ruleAccount }
}
