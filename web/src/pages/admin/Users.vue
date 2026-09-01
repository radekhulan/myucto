<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type AdminSupplierSearchItem, type AdminUser, type UserSupplierAssignment } from '@/api/admin'
import { rolesApi, type RoleListItem } from '@/api/roles'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

/**
 * Proč nejde přidat dalšího ZAPISUJÍCÍHO uživatele.
 *
 * ⚠️ Backend to posílá odjakživa, obrazovka to nečetla: admin vyplnil celý
 * formulář včetně hesla a teprve uložení skončilo na 403. Účty jen pro
 * čtení a klientské účty licenční místo nezabírají, takže je to varování,
 * ne zámek — kterou roli zvolit, ví admin líp než my.
 */
const seatWarning = computed(() => {
  if (auth.newUserBlocked === 'no_license') return t('users.seat_blocked_no_license')
  if (auth.newUserBlocked === 'seat_limit') return t('users.seat_blocked_limit')
  return ''
})
const users = ref<AdminUser[]>([])
const roles = ref<RoleListItem[]>([])
const loading = ref(false)
const showForm = ref(false)
const saving = ref(false)
const error = ref('')
const supplierQuery = ref('')
const supplierResults = ref<AdminSupplierSearchItem[]>([])
const supplierSearching = ref(false)
const supplierSearchOpen = ref(false)
const supplierCursor = ref<string | null>(null)
const assignments = ref<Array<UserSupplierAssignment & { role_id: number | null }>>([])
let searchTimer: ReturnType<typeof setTimeout> | undefined
let supplierSearchGeneration = 0

const form = reactive({ id: null as number | null, email: '', name: '', role_id: 0, locale: 'cs' as 'cs' | 'en', is_active: true, password: '' })
const selectedRole = computed(() => roles.value.find(r => r.id === form.role_id) ?? null)
const compatibleRoles = computed(() => roles.value.filter(r => r.is_active && r.role_type === selectedRole.value?.role_type && r.role_type !== 'superadmin'))
const selectedIsSuperadmin = computed(() => selectedRole.value?.system_key === 'superadmin')
/**
 * Ne-superadmin BEZ přiřazené firmy je na backendu fail-closed (SupplierAccessResolver
 * vrátí denied → /me pošle prázdná práva), takže se do aplikace vůbec nedostane.
 * Dřív to bylo jen měkké varování a uložit šlo — vznikl tak uživatel, který se přihlásí
 * a nic neuvidí. Proto tvrdá podmínka: bez firmy nejde uložit.
 */
const supplierRequired = computed(() => !selectedIsSuperadmin.value && assignments.value.length === 0)
const activeSuperadminCount = computed(() => users.value.filter(u => u.is_active && (u.is_superadmin || u.role.system_key === 'superadmin')).length)
const isLastActiveSuperadmin = (user: AdminUser | undefined): boolean => !!user?.is_active
  && (user.is_superadmin || user.role.system_key === 'superadmin')
  && activeSuperadminCount.value <= 1
const editingLastActiveSuperadmin = computed(() => isLastActiveSuperadmin(users.value.find(u => u.id === form.id)))

async function load() {
  loading.value = true
  try { ;[users.value, roles.value] = await Promise.all([adminApi.listUsers(), rolesApi.list()]) }
  finally { loading.value = false }
}

function resetSupplierSearch() {
  clearTimeout(searchTimer)
  supplierSearchGeneration++
  supplierQuery.value = ''
  supplierResults.value = []
  supplierSearching.value = false
  supplierCursor.value = null
  supplierSearchOpen.value = false
}

function openCreate() {
  resetSupplierSearch()
  const initial = roles.value.find(r => r.is_active && r.role_type === 'staff' && r.system_key !== 'superadmin') ?? roles.value.find(r => r.is_active)
  Object.assign(form, { id: null, email: '', name: '', role_id: initial?.id ?? 0, locale: 'cs', is_active: true, password: '' })
  assignments.value = []
  showForm.value = true
}

async function openEdit(user: AdminUser) {
  resetSupplierSearch()
  Object.assign(form, { id: user.id, email: user.email, name: user.name, role_id: user.role_id || user.role.id, locale: user.locale, is_active: user.is_active, password: '' })
  assignments.value = user.is_superadmin ? [] : (await adminApi.listUserSuppliers(user.id)).map(a => ({ ...a, role_id: a.role_id }))
  showForm.value = true
}

async function searchSuppliers(query: string, append: boolean) {
  const normalizedQuery = query.trim()
  if (normalizedQuery.length === 1 && !/^\d$/.test(normalizedQuery)) {
    supplierResults.value = []
    supplierCursor.value = null
    supplierSearching.value = false
    return
  }
  const generation = ++supplierSearchGeneration
  const cursor = append ? supplierCursor.value : null
  supplierSearching.value = true
  try {
    const response = await adminApi.searchSuppliers({ q: normalizedQuery || undefined, limit: 20, ...(cursor ? { cursor } : {}) })
    if (generation !== supplierSearchGeneration || !supplierSearchOpen.value || normalizedQuery !== supplierQuery.value.trim()) return
    const available = response.data.filter(s => !assignments.value.some(a => a.supplier_id === s.id))
    supplierResults.value = append ? [...supplierResults.value, ...available] : available
    supplierCursor.value = response.next_cursor
  } finally {
    if (generation === supplierSearchGeneration) supplierSearching.value = false
  }
}

function openSupplierSearch() {
  supplierSearchOpen.value = true
  void searchSuppliers(supplierQuery.value, false)
}

function closeSupplierSearch() {
  supplierSearchOpen.value = false
  clearTimeout(searchTimer)
  supplierSearchGeneration++
  supplierSearching.value = false
}

watch(supplierQuery, query => {
  clearTimeout(searchTimer)
  supplierSearchGeneration++
  supplierSearching.value = false
  if (!supplierSearchOpen.value) return
  const normalizedQuery = query.trim()
  if (normalizedQuery.length === 1 && !/^\d$/.test(normalizedQuery)) {
    supplierResults.value = []
    supplierCursor.value = null
    return
  }
  searchTimer = setTimeout(() => { void searchSuppliers(query, false) }, 275)
})

function addSupplier(item: AdminSupplierSearchItem) {
  assignments.value.push({ supplier_id: item.id, name: item.name, ic: item.ic, role_id: null, effective_role: { id: form.role_id, name: selectedRole.value?.name ?? '', type: selectedRole.value?.role_type ?? 'staff' } })
  supplierResults.value = supplierResults.value.filter(s => s.id !== item.id)
  supplierQuery.value = ''
}

async function save() {
  error.value = ''
  if (!form.role_id || (!form.id && !form.password)) { error.value = t('users.password_required'); return }
  const original = users.value.find(u => u.id === form.id)
  if (isLastActiveSuperadmin(original) && (!selectedIsSuperadmin.value || !form.is_active)) {
    error.value = t('users.last_admin_form'); return
  }
  // Pojistka i tady, ne jen přes :disabled — formulář jde odeslat Enterem.
  if (supplierRequired.value) { error.value = t('users.supplier_required'); return }
  saving.value = true
  try {
    if (form.id === null) {
      const user = await adminApi.createUser({ email: form.email, name: form.name, role_id: form.role_id, locale: form.locale, password: form.password })
      if (!selectedIsSuperadmin.value) await adminApi.setUserSuppliers(user.id, assignments.value.map(a => ({ supplier_id: a.supplier_id, role_id: a.role_id })))
    } else {
      await adminApi.updateUser(form.id, { name: form.name, role_id: form.role_id, locale: form.locale, is_active: form.is_active, ...(form.password ? { password: form.password } : {}) })
      if (!selectedIsSuperadmin.value) await adminApi.setUserSuppliers(form.id, assignments.value.map(a => ({ supplier_id: a.supplier_id, role_id: a.role_id })))
    }
    showForm.value = false
    await load()
  } catch (e: any) { error.value = e?.response?.data?.error?.message || t('common.error') }
  finally { saving.value = false }
}

async function deactivate(user: AdminUser) {
  if (isLastActiveSuperadmin(user)) return toast.warning(t('users.last_admin_alert'))
  if (!confirm(t('users.deactivate_confirm', { email: user.email }))) return
  try { await adminApi.deleteUser(user.id); await load() }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

function roleBadge(role: AdminUser['role']): string {
  if (role.type === 'superadmin') return 'bg-primary-100 text-primary-700'
  if (role.type === 'client') return 'bg-success-50 text-success-600'
  return 'bg-warning-50 text-warning-600'
}
onMounted(load)
</script>

<template>
  <div>
    <header class="flex flex-wrap items-center justify-between gap-3 mb-4"><div><h1 class="text-2xl font-semibold">{{ t('users.title') }}</h1><p class="text-sm text-neutral-500">{{ t('users.subtitle') }}</p></div><button :class="btnFilled('primary')" @click="openCreate"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('users.new') }}</button></header>
    <p v-if="seatWarning" class="mb-4 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800">{{ seatWarning }}</p>
    <div v-if="loading" class="py-12 text-center text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg overflow-hidden">
      <div v-for="user in users" :key="user.id" class="p-3 border-b border-neutral-100 flex flex-wrap items-center gap-3" :class="{ 'opacity-50': !user.is_active }">
        <div class="min-w-0 flex-1"><div class="font-medium truncate">{{ user.name }}</div><div class="text-xs text-neutral-500 font-mono truncate">{{ user.email }}</div></div>
        <span class="text-xs px-2 py-1 rounded" :class="roleBadge(user.role)">{{ user.role.name }}</span>
        <span v-if="user.is_superadmin || user.role.system_key === 'superadmin'">🔒</span>
        <button :class="btnOutline('primary')" @click="openEdit(user)">{{ t('common.edit') }}</button>
        <span v-if="isLastActiveSuperadmin(user)" class="text-xs text-neutral-500 whitespace-nowrap">{{ t('users.is_last_admin_lock') }}</span>
        <button v-else-if="user.is_active" :class="btnOutline('danger')" @click="deactivate(user)">{{ t('users.deactivate') }}</button>
      </div>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-50 bg-black/40 p-4 overflow-y-auto" @click.self="showForm=false">
      <form class="bg-surface max-w-2xl mx-auto my-6 rounded-xl shadow-lg p-5 space-y-4" @submit.prevent="save">
        <h2 class="text-lg font-semibold">{{ form.id === null ? t('users.new_title') : t('users.edit_title', { email: form.email }) }}</h2>
        <p v-if="seatWarning && form.id === null" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800">{{ seatWarning }}</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('settings.email') }}</span><input v-model="form.email" :disabled="form.id !== null" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md" /></label>
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('users.name') }}</span><input v-model="form.name" class="w-full h-10 px-3 border border-neutral-300 rounded-md" /></label>
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('users.role') }}</span><select v-model.number="form.role_id" :disabled="editingLastActiveSuperadmin" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface disabled:opacity-60"><option v-for="role in roles.filter(r => r.is_active || r.id === form.role_id)" :key="role.id" :value="role.id">{{ role.name }} ({{ t(`roles.types.${role.role_type}`) }})</option></select></label>
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('common.language') }}</span><select v-model="form.locale" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"><option value="cs">cs</option><option value="en">en</option></select></label>
        </div>
        <label v-if="form.id !== null" class="flex gap-2 items-center"><input v-model="form.is_active" :disabled="editingLastActiveSuperadmin" type="checkbox" />{{ t('common.active') }}<span v-if="editingLastActiveSuperadmin" class="text-xs text-neutral-500">— {{ t('users.is_last_admin_lock') }}</span></label>
        <label class="text-sm"><span class="block font-medium mb-1">{{ t('auth.password') }}</span><input v-model="form.password" type="password" autocomplete="new-password" class="w-full h-10 px-3 border border-neutral-300 rounded-md" /></label>

        <section v-if="!selectedIsSuperadmin" class="border-t border-neutral-200 pt-4 space-y-3">
          <div><h3 class="font-medium">{{ t('users.suppliers_title') }}</h3><p class="text-xs text-neutral-500">{{ t('users.suppliers_hint') }}</p></div>
          <div v-for="(item,index) in assignments" :key="item.supplier_id" class="border border-neutral-200 rounded-md p-3 flex flex-wrap items-center gap-2">
            <div class="flex-1 min-w-40"><div class="font-medium">{{ item.name }}</div><div v-if="item.ic" class="text-xs text-neutral-500">{{ t('common.ic') }} {{ item.ic }}</div></div>
            <select v-model.number="item.role_id" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm"><option :value="null">{{ t('users.supplier_role_inherit') }}</option><option v-for="role in compatibleRoles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
            <button type="button" :class="btnOutline('danger')" @click="assignments.splice(index,1)">{{ t('common.remove') }}</button>
          </div>
          <div class="relative">
            <input
              v-model="supplierQuery"
              type="text"
              role="combobox"
              autocomplete="off"
              aria-autocomplete="list"
              :aria-expanded="supplierSearchOpen"
              :aria-controls="supplierSearchOpen ? 'user-supplier-search-results' : undefined"
              :placeholder="t('users.supplier_search')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md"
              @focus="openSupplierSearch"
              @blur="closeSupplierSearch"
            />
            <div v-if="supplierSearchOpen && supplierSearching" class="text-xs text-neutral-500 mt-1">{{ t('common.loading') }}</div>
            <div
              v-if="supplierSearchOpen && supplierResults.length"
              id="user-supplier-search-results"
              role="listbox"
              class="absolute z-10 top-full inset-x-0 max-h-64 overflow-y-auto bg-surface border border-neutral-200 rounded-md shadow-lg"
              @mousedown.prevent
            >
              <button v-for="item in supplierResults" :key="item.id" type="button" role="option" class="block w-full text-left px-3 py-2 hover:bg-neutral-50" @click="addSupplier(item)">{{ item.name }} <span v-if="item.ic" class="text-xs text-neutral-500">({{ item.ic }})</span></button>
              <button v-if="supplierCursor" type="button" class="block w-full px-3 py-2 text-sm text-primary-600 hover:bg-primary-50" @click="searchSuppliers(supplierQuery, true)">{{ t('users.supplier_more') }}</button>
            </div>
          </div>
          <p v-if="supplierRequired" class="rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-600">
            {{ t('users.supplier_required') }}
          </p>
        </section>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <!-- Oddělovač a vzduch nad tlačítky: bez nich seděla lišta akcí nalepená
             na posledním poli formuláře a vypadala jako jeho součást. -->
        <footer class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 mt-2"><button type="button" :class="btnOutline('neutral')" @click="showForm=false">{{ t('common.cancel') }}</button><button type="submit" :disabled="saving || supplierRequired" :class="btnFilled('primary')">{{ t('common.save') }}</button></footer>
      </form>
    </div>
  </div>
</template>
