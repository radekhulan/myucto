<script setup lang="ts">
import { onMounted, onActivated, onDeactivated, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type OperationalBrandingSettings } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import BrandingProfilesSettings from '@/components/settings/BrandingProfilesSettings.vue'
import PdfAppearanceSettings from '@/components/settings/PdfAppearanceSettings.vue'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { requestSupplierIdNumber } from '@/workspace/supplierContext'

const { t } = useI18n()
const props = withDefaults(defineProps<{ clientScoped?: boolean }>(), {
  clientScoped: false,
})
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const { blockDemoMutation } = useDemoMode()
const supplier = ref<OperationalBrandingSettings | null>(null)
const loading = ref(true)
const previewLocale = ref<'cs' | 'en'>('cs')
const previewHtml = ref('')
const logoFileInput = ref<HTMLInputElement | null>(null)
const logoUploading = ref(false)
let mounted = false
let active = true
let generation = 0
let saveQueue = Promise.resolve()
let colorTimer: ReturnType<typeof setTimeout> | null = null

async function bumpPreview() {
  if (!supplier.value) return
  try {
    previewHtml.value = await settingsApi.emailPreviewHtml(previewLocale.value, null, props.clientScoped)
  } catch (e: any) {
    previewHtml.value = `<pre style="color:red">${e?.message || 'Preview failed'}</pre>`
  }
}

function invalidate() {
  generation++
  if (colorTimer) clearTimeout(colorTimer)
  colorTimer = null
}

function isCurrent(id: number, version: number) {
  const requestId = requestSupplierIdNumber()
  return active && generation === version && supplierStore.currentSupplierId === id
    && (!requestId || requestId === id) && auth.canWrite('settings.branding')
}

async function load() {
  const version = generation
  const id = supplierStore.currentSupplierId
  loading.value = true
  try {
    const result = await settingsApi.getClientBranding()
    if (!isCurrent(id, version) || result.id !== id) return
    supplier.value = result
    await bumpPreview()
  } finally {
    if (generation === version) loading.value = false
  }
}

async function refreshAfterSaves() {
  const version = generation
  const id = supplierStore.currentSupplierId
  loading.value = true
  await saveQueue
  if (isCurrent(id, version)) await load()
}

function savePatch(payload: Partial<OperationalBrandingSettings>, silent = true) {
  if (blockDemoMutation() || !supplier.value) return
  const id = supplier.value.id
  const version = generation
  saveQueue = saveQueue.then(async () => {
    if (!isCurrent(id, version)) return
    try {
      await settingsApi.updateClientBranding(payload, id)
      if (!isCurrent(id, version)) return
      if (!silent) toast.success(t('common.saved'))
      await bumpPreview()
    } catch (e: any) {
      if (!isCurrent(id, version)) return
      toast.error(e?.response?.data?.error?.message || t('common.error'))
      // Při chybě zahoď i navazující změny a obnov potvrzený stav serveru.
      invalidate()
      supplier.value = null
      try { await load() } catch { toast.error(t('common.error')) }
    }
  })
}

function saveBranding(silent = false) {
  if (!supplier.value) return
  if (!/^#[0-9A-Fa-f]{6}$/.test(supplier.value.email_accent_color || '')) {
    if (!silent) toast.error(t('settings.branding_color_invalid'))
    return
  }
  savePatch({ email_branding_enabled: supplier.value.email_branding_enabled,
    email_accent_color: supplier.value.email_accent_color, pdf_logo_show_name: supplier.value.pdf_logo_show_name }, silent)
}

function changePdf(patch: Partial<OperationalBrandingSettings>) {
  if (!supplier.value) return
  Object.assign(supplier.value, patch)
  savePatch(patch)
}

function changeColor() {
  if (colorTimer) clearTimeout(colorTimer)
  if (!supplier.value) return
  const id = supplier.value.id
  const version = generation
  const color = supplier.value.email_accent_color
  colorTimer = setTimeout(() => {
    if (isCurrent(id, version) && /^#[0-9A-Fa-f]{6}$/.test(color)) {
      savePatch({ email_accent_color: color })
    }
  }, 500)
}

function pickLogo() {
  logoFileInput.value?.click()
}

async function onLogoSelected(ev: Event) {
  if (blockDemoMutation()) return
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file || !supplier.value) return
  if (file.size > 1_048_576) {
    toast.error(t('settings.branding_logo_too_large'))
    if (logoFileInput.value) logoFileInput.value.value = ''
    return
  }
  logoUploading.value = true
  try {
    const result = await settingsApi.uploadEmailLogo(file, props.clientScoped)
    supplier.value.logo_path = result.logo_path
    supplier.value.has_email_logo = true
    toast.success(t('settings.branding_logo_uploaded'))
    await bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    logoUploading.value = false
    if (logoFileInput.value) logoFileInput.value.value = ''
  }
}

async function removeLogo() {
  if (blockDemoMutation()) return
  if (!supplier.value || !window.confirm(t('settings.branding_logo_remove_confirm'))) return
  try {
    await settingsApi.deleteEmailLogo(props.clientScoped)
    supplier.value.logo_path = null
    supplier.value.has_email_logo = false
    toast.success(t('settings.branding_logo_removed'))
    await bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

watch(previewLocale, () => { if (supplier.value) bumpPreview() })
watch(() => supplierStore.currentSupplierId, () => { invalidate(); supplier.value = null; if (active) void refreshAfterSaves() }, { flush: 'sync' })
watch(() => auth.canWrite('settings.branding'), allowed => {
  invalidate()
  if (!allowed) supplier.value = null
  else if (active) void refreshAfterSaves()
}, { flush: 'sync' })
onMounted(async () => { await load(); mounted = true })
onActivated(async () => {
  active = true
  if (mounted) await refreshAfterSaves()
})
onDeactivated(() => { active = false; invalidate() })
onBeforeUnmount(() => { active = false; invalidate() })
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('settings.branding_page_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('settings.branding_subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="supplier">
    <BrandingProfilesSettings
      v-model:enabled="supplier.branding_profiles_enabled"
      :supplier="supplier"
      :client-scoped="props.clientScoped"
      @changed="refreshAfterSaves"
    />

    <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm mt-5">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.branding_title') }}</h2>
        <label class="inline-flex items-center gap-2 cursor-pointer whitespace-nowrap">
          <input v-model="supplier.email_branding_enabled" type="checkbox" @change="savePatch({ email_branding_enabled: supplier.email_branding_enabled })" class="h-4 w-4 accent-primary-600" />
          <span class="text-sm text-neutral-700">{{ t('settings.branding_enabled') }}</span>
        </label>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_logo') }}</label>
            <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_logo_hint') }}</p>
            <div class="flex flex-wrap items-center gap-3">
              <button
                type="button"
                :disabled="logoUploading || !supplier.email_branding_enabled"
                :class="btnOutline('primary')"
                @click="pickLogo"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                {{ logoUploading ? t('common.loading') : (supplier.has_email_logo ? t('settings.branding_logo_replace') : t('settings.branding_logo_upload')) }}
              </button>
              <button v-if="supplier.has_email_logo" type="button" :class="btnOutline('danger')" @click="removeLogo">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.remove') }}
              </button>
              <input ref="logoFileInput" type="file" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" class="hidden" @change="onLogoSelected" />
            </div>
            <label class="inline-flex items-center gap-2 mt-3 cursor-pointer">
              <input
                v-model="supplier.pdf_logo_show_name"
                @change="savePatch({ pdf_logo_show_name: supplier.pdf_logo_show_name })"
                type="checkbox"
                :disabled="!supplier.email_branding_enabled || !supplier.has_email_logo"
                class="h-4 w-4 accent-primary-600 disabled:opacity-50"
              />
              <span class="text-sm text-neutral-700">{{ t('settings.branding_logo_show_name') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.branding_logo_show_name_hint') }}</p>

          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_accent_color') }}</label>
            <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_accent_color_hint') }}</p>
            <div class="flex flex-wrap items-center gap-3">
              <input
                v-model="supplier.email_accent_color"
                @input="changeColor"
                type="color"
                :disabled="!supplier.email_branding_enabled"
                class="h-10 w-14 cursor-pointer rounded border border-neutral-300 disabled:opacity-50"
              />
              <input
                v-model="supplier.email_accent_color"
                @input="changeColor"
                type="text"
                placeholder="#3B2D83"
                pattern="^#[0-9A-Fa-f]{6}$"
                :disabled="!supplier.email_branding_enabled"
                class="h-10 w-32 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:opacity-50"
              />
              <button
                type="button"
                :disabled="!supplier.email_branding_enabled"
                class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                @click="supplier.email_accent_color = '#3B2D83'; changeColor()"
              >
                {{ t('settings.branding_accent_reset') }}
              </button>
            </div>
          </div>

          <p class="text-xs text-neutral-500">{{ t('settings.branding_save_hint') }}</p>

          <button type="button" :class="btnOutline('primary')" @click="saveBranding(false)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('settings.branding_save') }}
          </button>
        </div>

        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="block text-sm font-medium text-neutral-700">{{ t('settings.branding_preview') }}</label>
            <div class="flex items-center gap-1 text-xs">
              <button type="button" class="cursor-pointer px-2" :class="previewLocale === 'cs' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'" @click="previewLocale = 'cs'">CS</button>
              <span class="text-neutral-300">|</span>
              <button type="button" class="cursor-pointer px-2" :class="previewLocale === 'en' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'" @click="previewLocale = 'en'">EN</button>
              <button type="button" class="cursor-pointer ml-2 px-2 text-neutral-500 hover:text-neutral-700" :title="t('common.refresh')" @click="bumpPreview">↻</button>
            </div>
          </div>
          <iframe :srcdoc="previewHtml" sandbox="allow-same-origin" class="w-full h-[420px] border border-neutral-200 rounded-md bg-neutral-50" />
        </div>
      </div>
    </section>
    <section v-if="auth.canWrite('settings.branding')" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm mt-5">
      <PdfAppearanceSettings :model-value="supplier" @change="changePdf" />
    </section>
    </template>
  </div>
</template>
