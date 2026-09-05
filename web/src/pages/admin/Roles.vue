<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { rolesApi, type PermissionCatalogResponse, type RoleDetail, type RoleListItem } from '@/api/roles'
import type { PermissionValue } from '@/security/permissions'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const { t, locale } = useI18n()
const toast = useToast()
const roles = ref<RoleListItem[]>([])
const catalog = ref<PermissionCatalogResponse | null>(null)
const selected = ref<RoleDetail | null>(null)
const loading = ref(false)
const saving = ref(false)
const creating = ref(false)
const form = reactive({ name: '', role_type: 'staff' as 'staff' | 'client', is_active: true, permissions: {} as Record<string, PermissionValue> })

const fixedRoleKeys = new Set(['superadmin', 'admin', 'admin_plus'])
const fixedRoleOrder: Record<string, number> = { superadmin: 0, admin_plus: 1, admin: 2 }
const orderedRoles = computed(() => [...roles.value].sort((a, b) =>
  (fixedRoleOrder[a.system_key ?? ''] ?? Number.MAX_SAFE_INTEGER)
  - (fixedRoleOrder[b.system_key ?? ''] ?? Number.MAX_SAFE_INTEGER)))
const locked = computed(() => fixedRoleKeys.has(selected.value?.system_key ?? ''))
const applicableGroups = computed(() => Object.entries(catalog.value?.groups ?? {}).map(([key, permissions]) => ({
  key,
  permissions: permissions.filter(p => form.role_type === 'staff' ? p.role_types.includes('staff') : p.role_types.includes('client')),
})).filter(group => group.permissions.length > 0))

function permissionLabel(permission: { key: string; label: string }): string {
  if (locale.value === 'cs') return permission.label
  const text = permission.key.replace(/[._]/g, ' ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function isFixedRole(role: Pick<RoleListItem, 'system_key'>): boolean {
  return fixedRoleKeys.has(role.system_key ?? '')
}

function roleDescription(systemKey: string | null): string {
  if (!fixedRoleKeys.has(systemKey ?? '')) return ''
  return t(`roles.system_descriptions.${systemKey}`)
}

async function load() {
  loading.value = true
  try {
    ;[roles.value, catalog.value] = await Promise.all([rolesApi.list(), rolesApi.catalog()])
  } finally { loading.value = false }
}

function resetForm() {
  form.name = ''
  form.role_type = 'staff'
  form.is_active = true
  form.permissions = {}
}

async function openRole(role: RoleListItem) {
  try {
    const detail = await rolesApi.detail(role.id)
    creating.value = false
    selected.value = detail
    form.name = detail.name
    form.role_type = detail.role_type === 'client' ? 'client' : 'staff'
    form.is_active = detail.is_active
    form.permissions = { ...detail.permissions }
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

function closeForm() {
  creating.value = false
  selected.value = null
  resetForm()
}

function openCreate() {
  selected.value = null
  creating.value = true
  resetForm()
}

async function save() {
  if (!form.name.trim()) return
  if (selected.value?.is_active && !form.is_active) {
    const usage = selected.value.usage ?? { default: selected.value.default_usage ?? 0, overrides: selected.value.override_usage ?? 0 }
    if (!confirm(t('roles.deactivate_confirm', { users: usage.default, overrides: usage.overrides }))) return
  }
  saving.value = true
  try {
    const permissions = Object.fromEntries(Object.entries(form.permissions).filter(([, level]) => level > 0)) as Record<string, PermissionValue>
    if (creating.value) {
      const created = await rolesApi.create({ name: form.name.trim(), type: form.role_type, permissions })
      creating.value = false
      selected.value = created
    } else if (selected.value) {
      selected.value = await rolesApi.update(selected.value.id, {
        name: form.name.trim(), is_active: form.is_active, permissions,
        revision: selected.value.revision, updated_at: selected.value.updated_at,
      })
    }
    toast.success(t('roles.saved'))
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { saving.value = false }
}

async function duplicate(role: RoleListItem) {
  try {
    const copy = await rolesApi.duplicate(role.id, `${role.name} – ${t('roles.copy_suffix')}`)
    await load()
    await openRole(copy)
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

async function remove(role: RoleListItem) {
  if (role.default_usage + role.override_usage > 0 || !confirm(t('roles.delete_confirm', { name: role.name }))) return
  try { await rolesApi.remove(role.id); selected.value = null; await load() }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

onMounted(load)
</script>

<template>
  <div>
    <header class="flex flex-wrap items-center justify-between gap-3 mb-5">
      <div><h1 class="text-2xl font-semibold">{{ t('roles.title') }}</h1><p class="text-sm text-neutral-500">{{ t('roles.subtitle') }}</p></div>
      <button :class="btnFilled('primary')" @click="openCreate"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('roles.new') }}</button>
    </header>
    <div v-if="loading" class="py-12 text-center text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else class="grid lg:grid-cols-[minmax(20rem,28rem)_1fr] gap-5 items-start">
      <div class="bg-surface border border-neutral-200 rounded-lg overflow-hidden divide-y divide-neutral-100">
        <div v-for="role in orderedRoles" :key="role.id" class="p-3" :class="!creating && selected?.id === role.id ? 'bg-primary-50' : 'hover:bg-neutral-50'">
          <button type="button" class="w-full text-left cursor-pointer" @click="openRole(role)">
            <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ role.name }}</span><span v-if="isFixedRole(role)">🔒</span><span v-else :class="role.is_active ? 'text-success-600' : 'text-neutral-400'">{{ role.is_active ? t('common.active') : t('roles.inactive') }}</span></div>
            <div class="text-xs text-neutral-500 mt-1">{{ t(`roles.types.${role.role_type}`) }} · {{ t('roles.usage', { users: role.default_usage, overrides: role.override_usage }) }}</div>
            <p v-if="roleDescription(role.system_key)" class="text-xs text-neutral-500 mt-1">{{ roleDescription(role.system_key) }}</p>
          </button>
          <div v-if="!isFixedRole(role)" class="flex flex-wrap gap-2 mt-2">
            <button type="button" :class="btnOutlineSm('primary')" @click="openRole(role)"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button>
            <button type="button" :class="btnOutlineSm('neutral')" @click="duplicate(role)"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.copy" /></svg>{{ t('roles.duplicate') }}</button>
            <button v-if="role.default_usage + role.override_usage === 0" type="button" :class="btnOutlineSm('danger')" @click="remove(role)"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
          </div>
        </div>
      </div>
      <form v-if="creating || selected" class="bg-surface border border-neutral-200 rounded-lg p-5 space-y-5" @submit.prevent="save">
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('roles.name') }}</span><input v-model="form.name" :disabled="locked" class="w-full h-10 px-3 border border-neutral-300 rounded-md" /></label>
          <label class="text-sm"><span class="block font-medium mb-1">{{ t('roles.type') }}</span><select v-if="creating" v-model="form.role_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"><option value="staff">{{ t('roles.types.staff') }}</option><option value="client">{{ t('roles.types.client') }}</option></select><div v-else class="h-10 px-3 flex items-center bg-neutral-50 rounded-md">{{ t(`roles.types.${selected?.role_type}`) }}</div></label>
        </div>
        <label v-if="!creating && !locked" class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" />{{ t('common.active') }}</label>
        <div v-if="locked" class="rounded-md bg-primary-50 text-primary-700 p-3 text-sm">
          {{ roleDescription(selected?.system_key ?? null) }}
        </div>
        <div v-else class="space-y-4">
          <section v-for="group in applicableGroups" :key="group.key" class="border border-neutral-200 rounded-lg overflow-hidden">
            <h2 class="font-semibold px-3 py-2 bg-neutral-50">{{ t(`permissions.groups.${group.key}`, group.key) }}</h2>
            <div class="divide-y divide-neutral-100">
              <div v-for="permission in group.permissions" :key="permission.key" class="p-3 grid md:grid-cols-[1fr_auto] gap-2 items-center">
                <div><div class="font-medium text-sm">{{ permissionLabel(permission) }}</div><div class="text-xs text-neutral-500">{{ locale === 'cs' ? permission.description : permissionLabel(permission) }}</div></div>
                <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden">
                  <label v-for="level in ([0,1,2] as const)" :key="level" class="cursor-pointer px-2 py-1.5 text-xs" :class="(form.permissions[permission.key] || 0) === level ? 'bg-primary-600 text-white' : 'bg-surface'"><input v-model.number="form.permissions[permission.key]" class="sr-only" type="radio" :value="level" />{{ t(`roles.levels.${level}`) }}</label>
                </div>
              </div>
            </div>
          </section>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="closeForm"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
          <button v-if="!locked" type="submit" :disabled="saving" :class="btnFilled('primary')"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
