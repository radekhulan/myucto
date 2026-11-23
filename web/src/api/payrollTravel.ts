import { api } from './client'

export type TravelTransportMode =
  | 'public_transport' | 'company_vehicle' | 'private_vehicle' | 'other'
export type TravelItemKind = 'transport' | 'accommodation' | 'incidental' | 'private_vehicle'
export type TravelVehicleKind = 'car' | 'single_track'
export type TravelFuelKind = 'petrol_95' | 'petrol_98' | 'diesel' | 'electricity'
export type TravelTripStatus = 'draft' | 'approved' | 'settled' | 'cancelled'

export interface TravelTripItem {
  id: number
  item_kind: TravelItemKind
  spent_on: string
  description: string
  amount_minor: number | null
  is_documented: boolean
  document_reference: string | null
  vehicle_kind: TravelVehicleKind | null
  distance_m: number | null
  consumption_ml_per_100km: number | null
  fuel_kind: TravelFuelKind | null
  documented_fuel_price_minor: number | null
  sort_order: number
}

export interface TravelMealDay {
  kind: 'meal_allowance'
  date: string
  minutes: number
  band: number
  free_meals: number
  base_rate_minor: number
  statutory_minimum_minor: number
  tax_exempt_maximum_minor: number
  entitlement_minor: number
  exempt_minor: number
  taxable_minor: number
  ruleset_id: string
  rule?: string
  merged_dates?: string[]
  note?: string
}

export interface TravelCalculationItem {
  kind: TravelItemKind
  item_id: number | null
  date: string
  description: string
  documented: boolean
  entitlement_minor: number
  exempt_minor: number
  taxable_minor: number
  basic_compensation_minor?: number
  fuel_volume_ml?: number
  fuel_cost_minor?: number
  fuel_price_per_unit_minor?: number
  fuel_price_documented?: boolean
  distance_m?: number
}

export interface TravelCalculationStep {
  label: string
  input_minor_units: number
  rate: { decimal: string; numerator: number; scale: number; denominator: number }
  unrounded_numerator: number
  unrounded_denominator: number
  rounding_mode: string
  output_minor_units: number
}

export interface TravelCalculation {
  status: 'supported' | 'manual_review'
  blockers: string[]
  ruleset_ids: string[]
  meal_days: TravelMealDay[]
  items: TravelCalculationItem[]
  entitlement_total_minor: number
  exempt_total_minor: number
  taxable_total_minor: number
  advance_minor: number
  settlement_difference_minor: number
  steps: TravelCalculationStep[]
  /** Vypořádání proti záloze — jen v náhledu, dopočítá ho server. */
  settlement?: TravelSettlement | null
}

export type TravelAdvanceSettlement = 'payroll' | 'cash'

/** Rozpad vyúčtování proti záloze (§ 183 ZP), tentýž výpočet jako promítnutí do mzdy. */
export interface TravelSettlement {
  mode: TravelAdvanceSettlement
  entitlement_minor: number
  exempt_minor: number
  taxable_minor: number
  advance_minor: number
  payroll_exempt_minor: number
  payroll_advance_offset_minor: number
  payroll_net_minor: number
  cash_payout_minor: number
  employee_refund_minor: number
}

export interface TravelTrip {
  id: number
  employee_id: number
  employment_id: number
  employee_name: string
  employment_code: string
  relation_type: string
  country_code: string
  /** Uložený okamžik v UTC — vzor směn (`starts_at_utc` + `timezone_name`). */
  departure_at_utc: string
  arrival_at_utc: string
  /** IANA zóna, ve které byl čas zadán. */
  timezone_name: string
  /** Místní čas odvozený serverem z UTC instantu a zóny — to, co uživatel zadal. */
  departure_at_local: string
  arrival_at_local: string
  origin_place: string
  destination_place: string
  purpose: string
  transport_mode: TravelTransportMode
  meal_rate_band_1_minor: number | null
  meal_rate_band_2_minor: number | null
  meal_rate_band_3_minor: number | null
  advance_minor: number
  advance_settlement: TravelAdvanceSettlement
  /** Den předložení dokladů k vyúčtování (§ 183 odst. 1 ZP). */
  documents_submitted_on?: string | null
  /** Do kdy má zaměstnanec předložit doklady (jen rozpracovaná cesta bez nich). */
  documents_due_on?: string | null
  /** Do kdy má zaměstnavatel cestu vyúčtovat (po předložení dokladů). */
  settlement_due_on?: string | null
  settlement_period_start: string
  status: TravelTripStatus
  entitlement_total_minor: number | null
  exempt_total_minor: number | null
  taxable_total_minor: number | null
  ruleset_id: string | null
  calculation: TravelCalculation | null
  row_version: number
  items: TravelTripItem[]
  free_meals: Record<string, number>
}

export interface TravelTripItemPayload {
  item_kind: TravelItemKind
  spent_on: string
  description: string
  amount?: string | null
  is_documented?: boolean
  document_reference?: string | null
  vehicle_kind?: TravelVehicleKind | null
  fuel_kind?: TravelFuelKind | null
  distance_km?: string | null
  consumption_per_100km?: string | null
  documented_fuel_price?: string | null
}

export interface TravelTripPayload {
  employee_id: number | null
  employment_id: number | null
  country_code: string
  /** ISO 8601 včetně UTC offsetu — stejně jako u směny. */
  departure_at: string
  arrival_at: string
  /** IANA zóna, ve které uživatel čas zadal. */
  timezone: string
  origin_place: string
  destination_place: string
  purpose: string
  transport_mode: TravelTransportMode
  meal_rate_band_1?: string | null
  meal_rate_band_2?: string | null
  meal_rate_band_3?: string | null
  advance?: string | null
  advance_settlement?: TravelAdvanceSettlement
  documents_submitted_on?: string | null
  settlement_period: string
  items: TravelTripItemPayload[]
  free_meals: { meal_date: string; meal_count: number }[]
  row_version?: number
}

export interface TravelMaterialization {
  status: string
  trip_id: number
  period: string
  created_count: number
  replayed_count: number
  created: { part: string; component_code: string; input_id: number; amount_minor: number }[]
  replayed: { part: string; component_code: string; input_id: number; amount_minor: number }[]
  settlement?: TravelSettlement
  posting?: { status: string; journal_entry_id: number | null; reason: string | null; message?: string | null } | null
}

export interface TravelTripsPage {
  trips: TravelTrip[]
  total: number
  limit: number
  offset: number
}

export const payrollTravelApi = {
  /**
   * Stránka seznamu cest. Server strop drží tvrdě (výchozí 50, maximum 100),
   * takže bez `limit` a `offset` dostaneme jen první stránku a o zbytku bychom
   * mlčeli — `total` je jediné, z čeho se pozná, že další cesty existují.
   *
   * `employmentId` zúží seznam na jeden vztah už na serveru: zužovat načtenou
   * stránku v prohlížeči znamenalo, že cesta z jiné strany se tiše neprojevila.
   */
  listPage: (
    period?: string,
    page?: { limit?: number, offset?: number },
    employmentId?: number,
  ) =>
    api.get<TravelTripsPage>('/payroll/travel/trips', {
      params: {
        ...(period ? { period } : {}),
        ...(page?.limit === undefined ? {} : { limit: page.limit }),
        ...(page?.offset === undefined ? {} : { offset: page.offset }),
        ...(employmentId ? { employment_id: employmentId } : {}),
      },
    }).then(response => response.data),
  preview: (payload: TravelTripPayload) =>
    api.post<{ calculation: TravelCalculation; settlement?: TravelSettlement | null }>(
      '/payroll/travel/preview',
      payload,
    ).then(response => ({
      ...response.data.calculation,
      settlement: response.data.settlement ?? null,
    })),
  create: (payload: TravelTripPayload) =>
    api.post<{ trip: TravelTrip }>('/payroll/travel/trips', payload)
      .then(response => response.data.trip),
  update: (id: number, payload: TravelTripPayload) =>
    api.put<{ trip: TravelTrip }>(`/payroll/travel/trips/${id}`, payload)
      .then(response => response.data.trip),
  calculation: (id: number) =>
    api.get<{ trip: TravelTrip; calculation: TravelCalculation }>(
      `/payroll/travel/trips/${id}/calculation`,
    ).then(response => response.data),
  approve: (id: number, rowVersion: number) =>
    api.post<{ trip: TravelTrip; calculation: TravelCalculation }>(
      `/payroll/travel/trips/${id}/approve`,
      { row_version: rowVersion },
    ).then(response => response.data),
  materialize: (id: number) =>
    api.post<{ materialization: TravelMaterialization }>(
      `/payroll/travel/trips/${id}/materialize`,
      {},
    ).then(response => response.data.materialization),
}
