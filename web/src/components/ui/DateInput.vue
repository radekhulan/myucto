<script setup lang="ts">
/**
 * Datumové pole, které mluví jazykem aplikace.
 *
 * Why: nativní `<input type="date">` formátuje podle jazyka prohlížeče, ne
 * aplikace (myinvoice#276). Uživatel s anglickým systémem a českým rozhraním tak
 * vedle „01. 09. 2026" ve výpisu edituje „09/01/2026" a u prvních dvanácti dnů
 * měsíce prohozený měsíc nepozná. Textové pole tady píše i čte v pořadí složek
 * jazyka aplikace (viz utils/dateInput.ts), nativní kalendář zůstává za ikonou.
 *
 * Kontrakt `v-model` je shodný s nativním inputem — ISO `YYYY-MM-DD` nebo `''` —
 * takže je to náhrada 1:1 bez zásahu do dat, watcherů ani API.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatIsoForInput, parseInputDate } from '@/utils/dateInput'
import { ICONS } from './buttonStyles'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  modelValue: string | null | undefined
  required?: boolean
  disabled?: boolean
  readonly?: boolean
  /** ISO hranice pro nativní kalendář; textový zápis mimo rozsah označí pole neplatné. */
  min?: string
  max?: string
  invalid?: boolean
  accent?: 'primary' | 'payroll'
  inputId?: string
  ariaLabel?: string
}>(), {
  required: false,
  disabled: false,
  readonly: false,
  min: undefined,
  max: undefined,
  invalid: false,
  accent: 'primary',
  inputId: undefined,
  ariaLabel: undefined,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'blur': [event: FocusEvent]
  /**
   * Hodnota potvrzená uživatelem, se sémantikou nativního `change`: až při
   * opuštění pole (a jen když se opravdu změnila) nebo hned po výběru
   * z kalendáře — NE při každém stisku klávesy.
   *
   * Filtry sestav na tom stojí (`@change="load"`): kdyby to jelo po znacích,
   * střílel by se dotaz na server po každé číslici; kdyby to nejelo vůbec,
   * výběr z kalendáře by sestavu tiše nepřenačetl.
   */
  'change': [value: string]
}>()

const attrs = useAttrs()
const { t, locale } = useI18n()

const textInput = ref<HTMLInputElement | null>(null)
const nativeInput = ref<HTMLInputElement | null>(null)
const text = ref(formatIsoForInput(props.modelValue, locale.value))
const focused = ref(false)
/**
 * Text neodpovídá žádnému platnému datu (`unparsable`), nebo je to platné datum
 * mimo min/max (`outOfRange`). Obojí drží formulář neodeslatelný; vizuálně se
 * ukáže až po opuštění pole, aby rozepsaný zápis neblikal červeně.
 */
const unparsable = ref(false)
const outOfRange = ref(false)
/** Hodnota při vstupu do pole — proti ní se na blur pozná, jestli se opravdu změnila. */
const valueOnFocus = ref(props.modelValue ?? '')
const generatedId = `date-input-${useId()}`

const id = computed(() => props.inputId ?? (attrs.id as string | undefined) ?? generatedId)
const hasError = computed(() => unparsable.value || outOfRange.value)
const isInvalid = computed(() => props.invalid || (hasError.value && !focused.value))
const inactive = computed(() => props.disabled || props.readonly)

/** Třídy z místa použití patří na textové pole (velikost, rámeček); wrapper jen drží ikonu. */
const passedClass = computed(() => (attrs.class as string | undefined) ?? '')
const wrapperClass = computed(() => /\bw-full\b/.test(passedClass.value) ? 'relative block w-full' : 'relative inline-block')
/**
 * Nativní `<input type="date">` má vlastní vnitřní šířku podle formátu, takže
 * spousta míst v aplikaci žádnou třídu šířky nepředává. Textové pole má proti
 * tomu výchozí šířku ~20 znaků a bez náhrady by filtry a tabulky roztáhlo.
 * Když volající šířku neurčí, držíme ji sami; jakákoli `w-*` třída z místa
 * použití má přednost.
 */
const widthClass = computed(() =>
  /\bw-(full|auto|screen|fit|min|max|px|\d|\[)/.test(passedClass.value) ? '' : 'w-40')
const accentClass = computed(() => props.accent === 'payroll'
  ? 'focus:ring-payroll-500/20 focus:border-payroll-500'
  : 'focus:ring-primary-500/20 focus:border-primary-500')

/** Vše kromě class/id jde na textové pole (data-* atributy, name, title, aria-*, style). */
const forwardedAttrs = computed(() => {
  const rest: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class' || key === 'id') continue
    rest[key] = value
  }
  return rest
})

function withinBounds(iso: string): boolean {
  if (props.min && iso < props.min) return false
  if (props.max && iso > props.max) return false
  return true
}

function rangeMessage(): string {
  const min = props.min ? formatIsoForInput(props.min, locale.value) : ''
  const max = props.max ? formatIsoForInput(props.max, locale.value) : ''
  if (min && max) return t('common.date_input.out_of_range_between', { min, max })
  if (min) return t('common.date_input.out_of_range_min', { min })
  return t('common.date_input.out_of_range_max', { max })
}

function syncValidity(): void {
  // Nativní validita formuláře: `required` hlídá prohlížeč sám, nesmyslný zápis
  // a datum mimo rozsah hlásíme přes setCustomValidity, aby form.checkValidity()
  // (a tím i Enter / Ctrl+S přes requestSubmit) zůstalo pravdivé.
  const message = unparsable.value
    ? t('common.date_input.invalid')
    : outOfRange.value ? rangeMessage() : ''
  textInput.value?.setCustomValidity(message)
}

function clearErrors(): void {
  unparsable.value = false
  outOfRange.value = false
}

function commit(iso: string): void {
  clearErrors()
  syncValidity()
  if (iso !== (props.modelValue ?? '')) emit('update:modelValue', iso)
}

/**
 * Vyhodnotí text: platný a v rozsahu → commit a vrátí ISO ('' pro prázdné);
 * jinak nastaví příznak chyby a vrátí null.
 */
function applyText(value: string, { allowShortYear }: { allowShortYear: boolean }): string | null {
  if (value.trim() === '') {
    commit('')
    return ''
  }
  const iso = parseInputDate(value, locale.value, { allowShortYear })
  if (iso === null) {
    unparsable.value = true
    outOfRange.value = false
    syncValidity()
    return null
  }
  // Platné už během psaní: watchery (splatnost z data vystavení) reagují hned,
  // stejně jako u nativního inputu. Text se kanonizuje až na blur, ať kurzor neskáče.
  commit(iso)
  // Datum mimo min/max nativní pole PŘIJME a jen ho označí za neplatné
  // (rangeUnderflow/rangeOverflow). Držíme se toho: stránky, které si na rozsah
  // hlídají vlastní hlášku (konec platnosti před začátkem), ji musí dostat do
  // modelu, jinak by jejich kontrola neměla co vyhodnotit. Odeslání formuláře
  // přesto blokujeme přes setCustomValidity.
  if (!withinBounds(iso)) {
    outOfRange.value = true
    syncValidity()
  }
  return iso
}

function onInput(event: Event): void {
  text.value = (event.target as HTMLInputElement).value
  // Dvouciferný rok se během psaní nepřijímá: „1.9.20" je mezistav k „1.9.2026",
  // a jako rok 2020 by rozjel přepočet splatnosti i asynchronní dotazy (číselná
  // řada, ceník) nad datem, které uživatel nikdy nemyslel. Doplní se až na blur.
  applyText(text.value, { allowShortYear: false })
}

function onBlur(event: FocusEvent): void {
  focused.value = false
  // Kanonizovat z právě potvrzeného ISO, ne z props.modelValue — rodič ho po
  // emitu přepíše až v dalším ticku.
  const iso = applyText(text.value, { allowShortYear: true })
  if (iso !== null) {
    text.value = formatIsoForInput(iso, locale.value)
    // Nativní `change` chodí na blur a jen při skutečné změně. Rozepsaný
    // nesmysl (iso === null) hodnotu nepotvrzuje, takže ani nehlásí změnu.
    if (iso !== valueOnFocus.value) {
      valueOnFocus.value = iso
      emit('change', iso)
    }
  }
  emit('blur', event)
}

function onNativeChange(event: Event): void {
  const iso = (event.target as HTMLInputElement).value
  // Prázdná hodnota = tlačítko „Vymazat" v kalendáři (Firefox). Nativní input
  // by emitoval '', tak i my — kontrakt 1:1.
  const changed = iso !== (props.modelValue ?? '')
  commit(iso)
  text.value = formatIsoForInput(iso, locale.value)
  // Výběr z kalendáře je potvrzení hodnoty, ne rozepsaný text — `change` letí
  // hned, stejně jako u nativního pole. Bez toho by filtr sestavy po kliknutí
  // do kalendáře nepřenačetl data.
  valueOnFocus.value = iso
  if (changed) emit('change', iso)
  // Fokus zpět textovému poli: nativní input tím ztratí fokus a Safari zavře
  // popover kalendáře (Chrome ho po výběru zavírá sám). Uživatel navíc může
  // rovnou pokračovat psaním nebo Tabem na další pole.
  textInput.value?.focus({ preventScroll: true })
}

/**
 * Kalendář zavřený bez výběru (Escape, klik mimo) nechá fokus na neviditelném
 * nativním inputu — uživatel to nevidí a jeho psaní by tiše editovalo skryté
 * segmenty data. Klávesová událost při otevřeném popoveru k inputu nedorazí,
 * ale první stisk po zavření ano: přesměrujeme ho do textového pole a znak
 * nezahodíme. Tab nechat být — má kam jít.
 */
function onNativeKeydown(event: KeyboardEvent): void {
  if (event.key === 'Tab') return
  event.preventDefault()
  const field = textInput.value
  if (!field) return
  field.focus({ preventScroll: true })
  if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
    text.value = text.value + event.key
    applyText(text.value, { allowShortYear: false })
  }
}

function openPicker(): void {
  const el = nativeInput.value
  if (!el || inactive.value) return
  // Safari drží popover kalendáře jen dokud má nativní input fokus — bez něj
  // zůstane viset, kliknutí do stránky ho nezavřou a dál mění TOTO pole.
  // Proto nejdřív fokus, teprve pak showPicker.
  el.focus({ preventScroll: true })
  try {
    if (typeof el.showPicker === 'function') {
      el.showPicker()
      return
    }
  } catch {
    // showPicker vyžaduje uživatelské gesto a bezpečný kontext; když odmítne,
    // spadneme na click, který v prohlížečích bez showPicker kalendář otevře taky.
  }
  el.click()
}

function resetToModel(): void {
  text.value = formatIsoForInput(props.modelValue, locale.value)
  clearErrors()
  void nextTick(syncValidity)
}

// Změna zvenčí (načtení faktury, přepočet splatnosti, přepnutí jazyka) přepíše
// text. Výjimka jen pro pole, ve kterém uživatel právě píše:
//  - rozepsaný (neplatný) text se nezahodí,
//  - hotový zápis, který už modelu odpovídá („1.9.2026" → 2026-09-01), se
//    nepřeformátuje pod kurzorem — kanonizuje ho až blur.
// Fokus sám důvodem není: splatnost přepočtená z data vystavení se musí
// ukázat i v poli, které má zrovna fokus, jinak by ji blur přepsal starou hodnotou.
watch(() => [props.modelValue, locale.value] as const, ([iso]) => {
  if (focused.value) {
    if (hasError.value) return
    if (parseInputDate(text.value, locale.value) === (iso || null)) return
  }
  resetToModel()
})

// Responzivní editory renderují totéž pole dvakrát (tabulka `hidden md:block`
// a karty `md:hidden`) a display:none instanci neodpojí. Rozepsaná chyba v kopii,
// kterou uživatel po změně šířky okna už nevidí, by přes setCustomValidity dál
// blokovala odeslání formuláře bez jakéhokoli viditelného důvodu. Proto se
// instance, která přestane být vykreslená, vrátí k hodnotě modelu.
let resizeObserver: ResizeObserver | null = null
onMounted(() => {
  if (typeof ResizeObserver === 'undefined' || !textInput.value) return
  resizeObserver = new ResizeObserver((entries) => {
    const box = entries[0]?.contentRect
    if (box && box.width === 0 && box.height === 0 && hasError.value) resetToModel()
  })
  resizeObserver.observe(textInput.value)
})
onBeforeUnmount(() => resizeObserver?.disconnect())
</script>

<template>
  <div :class="wrapperClass">
    <input
      ref="textInput"
      v-bind="forwardedAttrs"
      :id="id"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      :value="text"
      :placeholder="t('common.date_input.placeholder')"
      :required="required"
      :disabled="disabled"
      :readonly="readonly"
      :aria-label="ariaLabel"
      :aria-invalid="isInvalid || undefined"
      :class="[
        widthClass,
        passedClass,
        'pr-9 bg-surface text-neutral-900 placeholder:text-neutral-500',
        `focus:ring-2 outline-none ${accentClass}`,
        'disabled:bg-neutral-50 disabled:text-neutral-400 disabled:cursor-not-allowed',
        isInvalid ? 'border-danger-500' : '',
      ]"
      @focus="focused = true; valueOnFocus = modelValue ?? ''"
      @input="onInput"
      @blur="onBlur"
    >
    <!--
      Nativní kalendář: skrytý date input leží přesně pod ikonou, aby prohlížeč
      ukotvil vyskakovací kalendář k ní. Ovládá se jen ikonou (tabindex -1),
      klávesnice patří textovému poli. Není aria-hidden — při otevřeném
      kalendáři fokus drží (Safari), a fokusovaný aria-hidden prvek je chyba.
    -->
    <input
      ref="nativeInput"
      type="date"
      tabindex="-1"
      :aria-label="t('common.date_input.open_calendar')"
      :value="modelValue ?? ''"
      :min="min"
      :max="max"
      :disabled="inactive"
      class="absolute inset-y-0 right-0 w-8 opacity-0 pointer-events-none"
      @change="onNativeChange"
      @keydown="onNativeKeydown"
    >
    <button
      type="button"
      :disabled="inactive"
      :aria-label="t('common.date_input.open_calendar')"
      :title="t('common.date_input.open_calendar')"
      class="absolute inset-y-0 right-0 flex w-8 items-center justify-center rounded-r-md text-neutral-500 transition-colors hover:text-neutral-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:cursor-not-allowed disabled:opacity-50"
      @click="openPicker"
    >
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
        <path :d="ICONS.calendar" />
      </svg>
    </button>
  </div>
</template>
