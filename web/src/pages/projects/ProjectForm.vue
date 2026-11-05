<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { projectsApi, type Project, type ProjectPayload, type BillingEmail } from '@/api/projects'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import BillingTemplateSelect from '@/components/work-reports/BillingTemplateSelect.vue'
import EntityDimensionDefaults from '@/components/dimensions/EntityDimensionDefaults.vue'

/**
 * V `embedded` módu komponenta nečte route a vrací výsledek přes `@created`.
 * `clientId` je předaný přes prop (override route.query.client_id).
 */
const props = withDefaults(defineProps<{ embedded?: boolean; clientId?: number }>(), {
  embedded: false,
  clientId: 0,
})
const emit = defineEmits<{
  (e: 'created', project: Project): void
  (e: 'cancel'): void
}>()

const route = useRoute()
const router = useRouter()

const isEdit = computed(() =>
  !props.embedded && route.params.id !== undefined && route.params.id !== 'new'
)
const projectId = computed(() => (isEdit.value ? Number(route.params.id) : null))
/** Výchozí dimenze se ukládají zvlášť, až po uložení zakázky (nová zakázka id ještě nemá). */
const dimensionDefaults = ref<InstanceType<typeof EntityDimensionDefaults> | null>(null)
const initialClientId = ref<number | null>(null)

const auth = useAuthStore()
const toast = useToast()
const supplierStore = useSupplierStore()
const client = ref<Client | null>(null)
const currencies = ref<Currency[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const submitting = ref(false)
const error = ref('')

const form = ref<ProjectPayload>({
  client_id: 0,
  name: '',
  payment_due_days: 7,
  payment_due_unit: null,
  project_number: null,
  contract_number: null,
  budget_total: null,
  budget_yearly: null,
  budget_monthly: null,
  billing_template_id: null,
  hourly_rate: 1500,
  currency_id: 0,
  status: 'active',
  requires_work_report_approval: false,
  note: null,
  default_revenue_category_id: null,
  billing_emails: [],
  billing_emails_mode: 'auto',
})

// usages (#86): pro které typy zpráv se e-mail použije; default vše zaškrtnuté.
const PROJECT_EMAIL_USAGES = ['documents', 'reminders', 'approvals'] as const
type ProjectEmailUsage = typeof PROJECT_EMAIL_USAGES[number]
const allUsages = (): ProjectEmailUsage[] => [...PROJECT_EMAIL_USAGES]

const billingEmailInput = ref<{ position: 1 | 2 | 3; email: string; label: string; usages: ProjectEmailUsage[] }[]>([
  { position: 1, email: '', label: '', usages: allUsages() },
  { position: 2, email: '', label: '', usages: allUsages() },
  { position: 3, email: '', label: '', usages: allUsages() },
])

function toggleBillingUsage(i: number, code: ProjectEmailUsage) {
  const u = billingEmailInput.value[i].usages
  billingEmailInput.value[i].usages = u.includes(code) ? u.filter(x => x !== code) : [...u, code]
}

// Splatnost zakázky — preset selector. Zakázka má vždy konkrétní hodnotu (žádné
// „dědit"); 'month' = 1 kalendářní měsíc (days=1, unit='month'), 'custom' odhalí
// číselný input ve dnech. NULL unit = jednotka se dědí z klienta → dodavatele.
type ProjectDuePreset = '7' | '14' | 'month' | 'custom'
// 'custom' musí být „sticky" i když hodnota odpovídá presetu (7/14) — jinak by getter
// spadl zpět na preset a číselný input by se nikdy neukázal.
const dueCustom = ref(false)
const projectDuePreset = computed<ProjectDuePreset>({
  get() {
    if (dueCustom.value) return 'custom'
    const d = form.value.payment_due_days
    // NULL jednotka není „dny", ale dědění — preset proto vybíráme podle jednotky,
    // se kterou splatnost skutečně spočítá backend (PaymentDueResolver).
    const u = form.value.payment_due_unit
      ?? client.value?.payment_due_unit
      ?? supplierStore.currentSupplier?.default_payment_due_unit
      ?? 'days'
    if (u === 'month' && d === 1) return 'month'
    if (u === 'days' && d === 7) return '7'
    if (u === 'days' && d === 14) return '14'
    return 'custom'
  },
  set(v: ProjectDuePreset) {
    dueCustom.value = (v === 'custom')
    if (v === '7') { form.value.payment_due_days = 7; form.value.payment_due_unit = 'days' }
    else if (v === '14') { form.value.payment_due_days = 14; form.value.payment_due_unit = 'days' }
    else if (v === 'month') { form.value.payment_due_days = 1; form.value.payment_due_unit = 'month' }
    else { if (form.value.payment_due_days == null || form.value.payment_due_unit === 'month') form.value.payment_due_days = 30; form.value.payment_due_unit = 'days' }
  },
})

onMounted(async () => {
  currencies.value = await codebooksApi.currencies()
  if (!auth.isClientRole) revenueCategories.value = await revenueCategoriesApi.list(false).catch(() => [] as RevenueCategory[])
  if (form.value.currency_id === 0) {
    const def = currencies.value.find(c => c.is_default && c.code === 'CZK') || currencies.value[0]
    if (def) form.value.currency_id = def.id
  }
  // 1. Načti existující zakázku (edit) nebo client_id z query (nová)
  if (isEdit.value && projectId.value) {
    const p = await projectsApi.get(projectId.value)
    Object.assign(form.value, sanitize(p))
    if (auth.canRead('clients')) client.value = await clientsApi.get(p.client_id)
    // Naplň billing inputy (usages null/prázdné = všechny typy zpráv)
    for (let i = 0; i < 3; i++) {
      const found = p.billing_emails.find((b) => b.position === ((i + 1) as 1 | 2 | 3))
      billingEmailInput.value[i] = {
        position: (i + 1) as 1 | 2 | 3,
        email: found?.email || '',
        label: found?.label || '',
        usages: found?.usages?.length ? [...found.usages] as ProjectEmailUsage[] : allUsages(),
      }
    }
  } else {
    // embedded mode → použij prop clientId; jinak route.query.client_id
    const cid = props.embedded
      ? (props.clientId || null)
      : (route.query.client_id ? Number(route.query.client_id) : null)
    if (cid) {
      initialClientId.value = cid
      client.value = await clientsApi.get(cid)
      form.value.client_id = cid
      form.value.currency_id = client.value.currency_default_id
      form.value.payment_due_days = client.value.payment_due_default ?? 7
      form.value.payment_due_unit = client.value.payment_due_unit ?? null
      if (client.value.hourly_rate && client.value.hourly_rate > 0) {
        form.value.hourly_rate = client.value.hourly_rate
      }
    } else if (!props.embedded) {
      router.push('/clients')
    }
  }
})

function sanitize(p: Project): Partial<ProjectPayload> {
  return {
    client_id: p.client_id,
    name: p.name,
    payment_due_days: p.payment_due_days,
    payment_due_unit: p.payment_due_unit ?? null,
    project_number: p.project_number ?? null,
    contract_number: p.contract_number ?? null,
    budget_total: p.budget_total ?? null,
    budget_yearly: p.budget_yearly ?? null,
    budget_monthly: p.budget_monthly ?? null,
    billing_template_id: p.billing_template_id ?? null,
    hourly_rate: p.hourly_rate,
    currency_id: p.currency_id,
    status: p.status,
    requires_work_report_approval: !!p.requires_work_report_approval,
    note: p.note ?? null,
    default_revenue_category_id: p.default_revenue_category_id ?? null,
    billing_emails_mode: p.billing_emails_mode ?? 'auto',
  }
}

async function submit() {
  submitting.value = true
  error.value = ''
  try {
    // Připrav billing emails (usages: všechny tři / žádný → backend uloží NULL = vše)
    const emails: BillingEmail[] = billingEmailInput.value
      .filter((e) => e.email.trim())
      .map((e) => ({
        position: e.position,
        email: e.email.trim(),
        label: e.label.trim() || null,
        usages: e.usages,
      }))
    form.value.billing_emails = emails

    if (isEdit.value && projectId.value) {
      const { client_id, ...rest } = form.value
      if (auth.isClientRole) delete rest.default_revenue_category_id
      void client_id
      const updated = await projectsApi.update(projectId.value, rest)
      await dimensionDefaults.value?.save(projectId.value)
      const revBackfilled = updated.revenue_category_backfilled ?? 0
      if (revBackfilled > 0) {
        toast.success(t('project.default_revenue_category_backfilled', { count: revBackfilled }))
      }
      if (props.embedded) { emit('created', updated); return }
      router.push(`/projects/${projectId.value}`)
    } else {
      const payload = { ...form.value }
      if (auth.isClientRole) delete payload.default_revenue_category_id
      const created = await projectsApi.create(payload)
      await dimensionDefaults.value?.save(created.id)
      if (props.embedded) { emit('created', created); return }
      router.push(`/projects/${created.id}`)
    }
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div :class="embedded ? '' : 'max-w-3xl'">
    <div v-if="!embedded" class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">
        {{ isEdit ? t('project.edit_title') : t('project.new_title') }}
      </h1>
      <RouterLink v-if="client" :to="`/clients/${client.id}`" class="text-sm text-neutral-600 hover:text-neutral-900">
        {{ t('common.back') }}
      </RouterLink>
    </div>

    <div v-if="client && !embedded" class="mb-3 text-sm text-neutral-500">
      {{ t('invoice.client') }}: <span class="font-medium text-neutral-900">{{ client.company_name }}</span>
    </div>

    <form @submit.prevent="submit" autocomplete="off" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="p-5 space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.name') }} *</label>
          <input autocomplete="off" v-model="form.name" required
            class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.payment_due_label') }} *</label>
            <div class="flex gap-2 items-center">
              <select v-model="projectDuePreset"
                class="flex-1 min-w-0 h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option value="7">{{ t('project.payment_due_preset_7') }}</option>
                <option value="14">{{ t('project.payment_due_preset_14') }}</option>
                <option value="month">{{ t('project.payment_due_preset_month') }}</option>
                <option value="custom">{{ t('project.payment_due_preset_custom') }}</option>
              </select>
              <div v-if="projectDuePreset === 'custom'" class="flex items-center gap-1.5 shrink-0">
                <input autocomplete="off" v-model.number="form.payment_due_days" type="number" min="1" max="365"
                  class="w-20 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <span class="text-xs text-neutral-500">{{ t('project.payment_due_custom_days_suffix') }}</span>
              </div>
            </div>
            <p v-if="projectDuePreset === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('project.payment_due_month_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.hourly_rate') }}</label>
            <input autocomplete="off" v-model.number="form.hourly_rate" type="number" step="0.000001" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.currency') }}</label>
            <select v-model.number="form.currency_id"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.status') }}</label>
            <select v-model="form.status"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option value="active">{{ t('common.active') }}</option>
              <option value="paused">{{ t('project.status_paused') }}</option>
              <option value="closed">{{ t('project.status_closed') }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.project_number') }}</label>
            <input autocomplete="off" v-model="form.project_number"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.contract_number') }}</label>
            <input autocomplete="off" v-model="form.contract_number"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.budget_total') }}</label>
            <input autocomplete="off" v-model.number="form.budget_total" type="number" step="0.01" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.budget_yearly') }}</label>
            <input autocomplete="off" v-model.number="form.budget_yearly" type="number" step="0.01" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.budget_monthly') }}</label>
            <input autocomplete="off" v-model.number="form.budget_monthly" type="number" step="0.01" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
        </div>

        <!-- Billing emails — boxík ve stylu e-mailových kontaktů u klienta (#86) -->
        <div class="border border-neutral-200 rounded-md p-3 space-y-3">
          <div>
            <div class="text-sm font-medium text-neutral-700">{{ t('project.billing_emails') }}</div>
            <p class="text-xs text-neutral-500 mt-0.5">
              {{ $i18n.locale === 'cs'
                ? 'Vedle hlavního emailu klienta budou faktury chodit i na tyto adresy.'
                : 'Invoices will be sent to these addresses in addition to the client\'s main email.' }}
            </p>
          </div>

          <div v-for="(_, i) in billingEmailInput" :key="i"
            :class="['rounded-md p-3 space-y-2', billingEmailInput[i].email.trim() ? 'border border-neutral-200 bg-neutral-50/50' : 'border border-dashed border-neutral-200']">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <input autocomplete="off" v-model="billingEmailInput[i].email" type="email" :placeholder="`Email #${i + 1}`"
                class="sm:col-span-2 h-9 px-2.5 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <input autocomplete="off" v-model="billingEmailInput[i].label" :placeholder="$i18n.locale === 'cs' ? 'Popisek (účetní, PM…)' : 'Label (accountant, PM…)'"
                class="h-9 px-2.5 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <!-- Účely (#86) — pro které typy zpráv se e-mail použije; vše zaškrtnuté = default -->
            <div v-if="billingEmailInput[i].email.trim()" class="flex items-center gap-x-4 gap-y-1 flex-wrap text-sm">
              <label v-for="code in PROJECT_EMAIL_USAGES" :key="code" class="flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" :checked="billingEmailInput[i].usages.includes(code)" @change="toggleBillingUsage(i, code)"
                  class="rounded border-neutral-300 text-primary-600" />
                <span class="text-neutral-700">{{ t(`client.email_contacts.usage.${code}`) }}</span>
              </label>
              <span v-if="!billingEmailInput[i].usages.length" class="text-xs text-neutral-400">{{ t('project.billing_email_no_usage_hint') }}</span>
            </div>
          </div>

          <!-- Režim kombinace s kontakty/hlavním e-mailem klienta (#86) -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.billing_emails_mode') }}</label>
            <select v-model="form.billing_emails_mode"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option value="auto">{{ t('project.billing_emails_mode_auto') }}</option>
              <option value="append">{{ t('project.billing_emails_mode_append') }}</option>
              <option value="replace">{{ t('project.billing_emails_mode_replace') }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('project.billing_emails_mode_hint') }}</p>
          </div>
        </div>

        <div class="md:col-span-2"><BillingTemplateSelect v-model="form.billing_template_id" :currency="currencies.find(c => c.id === form.currency_id)?.code" /></div>

        <!-- Schvalování výkazu zákazníkem -->
        <div class="border-t border-neutral-200 pt-4">
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="form.requires_work_report_approval" type="checkbox"
              class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/20" />
            <div>
              <div class="text-sm font-medium text-neutral-900">{{ t('project.requires_approval') }}</div>
              <div class="text-xs text-neutral-500 mt-0.5">{{ t('project.requires_approval_hint') }}</div>
            </div>
          </label>
        </div>

        <!-- Výchozí kategorie tržby zakázky (přednost před klientem) -->
        <div v-if="!auth.isClientRole">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.default_revenue_category') }}</label>
          <select v-model="form.default_revenue_category_id"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option :value="null">— {{ t('project.default_revenue_category_none') }} —</option>
            <option v-for="c in revenueCategories" :key="c.id" :value="c.id">
              {{ c.label }} ({{ c.code }})
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('project.default_revenue_category_hint') }}</p>
        </div>

        <EntityDimensionDefaults v-if="!auth.isClientRole" ref="dimensionDefaults" entity="projects" :entity-id="projectId" />

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('project.note') }}</label>
          <textarea autocomplete="off" v-model="form.note" rows="2"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>
      </div>

      <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 flex justify-end gap-3 rounded-b-lg">
        <button type="button" @click="embedded ? emit('cancel') : router.back()" class="px-4 h-10 border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface text-sm font-medium">{{ t('common.cancel') }}</button>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>
  </div>
</template>
