import type {
  FulfillmentReturn,
  StockTrackingAllocation,
  StockTrackingMode,
} from "@/api/stock";

export interface TrackingChoice {
  key: string;
  stock_tracking_unit_id?: number;
  serial_number?: string | null;
  lot_code?: string | null;
  expires_on?: string | null;
  location_id?: number | null;
  available: string;
  quantity: string;
}

const SCALE = 1000;

function quantityToMilli(value: string): number | null {
  const match = String(value)
    .trim()
    .match(/^(\d+)(?:\.(\d{1,3}))?$/);
  if (!match) return null;
  const whole = Number(match[1]);
  if (!Number.isSafeInteger(whole)) return null;
  return whole * SCALE + Number((match[2] ?? "").padEnd(3, "0"));
}

function milliToQuantity(value: number): string {
  return (value / SCALE).toFixed(3);
}

function identity(
  allocation: Pick<
    TrackingChoice,
    "stock_tracking_unit_id" | "serial_number" | "lot_code" | "expires_on"
  >,
): string {
  if (allocation.stock_tracking_unit_id)
    return `id:${allocation.stock_tracking_unit_id}`;
  if (allocation.serial_number) return `serial:${allocation.serial_number}`;
  return `lot:${allocation.lot_code ?? ""}|${allocation.expires_on ?? ""}`;
}

export function trackingChoiceKey(
  allocation: Pick<
    TrackingChoice,
    | "stock_tracking_unit_id"
    | "serial_number"
    | "lot_code"
    | "expires_on"
    | "location_id"
  >,
): string {
  return `${identity(allocation)}|location:${allocation.location_id ?? "none"}`;
}

export function trackingPayload(
  mode: StockTrackingMode,
  lineQuantity: string,
  choices: TrackingChoice[],
): StockTrackingAllocation[] {
  if (mode === "none") return [];
  const lineMilli = quantityToMilli(lineQuantity);
  if (lineMilli === null || lineMilli <= 0)
    throw new Error("invalid-line-quantity");

  let total = 0;
  const allocations: StockTrackingAllocation[] = [];
  for (const choice of choices) {
    const quantity = quantityToMilli(choice.quantity);
    const available = quantityToMilli(choice.available);
    if (
      quantity === null ||
      available === null ||
      quantity < 0 ||
      quantity > available
    )
      throw new Error("invalid-tracking-quantity");
    if (quantity === 0) continue;
    if (mode === "serial" && quantity !== SCALE)
      throw new Error("invalid-serial-quantity");
    total += quantity;
    allocations.push({
      stock_tracking_unit_id: choice.stock_tracking_unit_id,
      quantity: milliToQuantity(quantity),
      serial_number: choice.serial_number ?? undefined,
      lot_code: choice.lot_code ?? undefined,
      expires_on: choice.expires_on ?? undefined,
      location_id: choice.location_id ?? undefined,
    });
  }
  if (total !== lineMilli) throw new Error("tracking-total-mismatch");
  return allocations;
}

export function returnTrackingChoices(
  snapshot: StockTrackingAllocation[],
  returns: FulfillmentReturn[],
  shipmentItemId: number,
): TrackingChoice[] {
  const available = new Map<
    string,
    { allocation: StockTrackingAllocation; quantity: number }
  >();
  for (const allocation of snapshot) {
    const quantity = quantityToMilli(allocation.quantity);
    if (quantity === null || quantity <= 0) continue;
    const key = identity(allocation);
    const entry = available.get(key);
    if (entry) entry.quantity += quantity;
    else available.set(key, { allocation, quantity });
  }
  for (const returned of returns) {
    for (const item of returned.items) {
      if (item.shipment_item_id !== shipmentItemId) continue;
      const allocations =
        (
          item.component_snapshot as {
            tracking_allocations?: StockTrackingAllocation[];
          }
        ).tracking_allocations ?? [];
      for (const allocation of allocations) {
        const quantity = quantityToMilli(allocation.quantity);
        const entry = available.get(identity(allocation));
        if (entry && quantity !== null) entry.quantity -= quantity;
      }
    }
  }
  return [...available.entries()]
    .filter(([, entry]) => entry.quantity > 0)
    .map(([key, entry]) => ({
      key,
      stock_tracking_unit_id: entry.allocation.stock_tracking_unit_id,
      serial_number: entry.allocation.serial_number,
      lot_code: entry.allocation.lot_code,
      expires_on: entry.allocation.expires_on,
      available: milliToQuantity(entry.quantity),
      quantity: milliToQuantity(entry.quantity),
    }));
}

export function resetTrackingChoiceLocations(
  choices: TrackingChoice[],
): TrackingChoice[] {
  return choices.map((choice) => ({ ...choice, location_id: null }));
}

export function isTracked(mode: StockTrackingMode | undefined): boolean {
  return mode === "lot" || mode === "serial";
}
