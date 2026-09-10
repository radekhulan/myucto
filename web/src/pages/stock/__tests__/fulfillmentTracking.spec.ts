import { describe, expect, it } from "vitest";
import type { FulfillmentReturn, StockTrackingAllocation } from "@/api/stock";
import {
  resetTrackingChoiceLocations,
  returnTrackingChoices,
  trackingChoiceKey,
  trackingPayload,
} from "../fulfillmentTracking";

describe("fulfillment tracking payloads", () => {
  it("creates exact serial and lot shipment allocations with their source locations", () => {
    const serial = {
      stock_tracking_unit_id: 11,
      serial_number: "SN-01",
      location_id: 7,
      available: "1.000",
      quantity: "1.000",
    };
    const lot = {
      stock_tracking_unit_id: 12,
      lot_code: "LOT-02",
      expires_on: "2027-12-31",
      location_id: 8,
      available: "3.000",
      quantity: "2.000",
    };

    expect(
      trackingPayload("serial", "1.000", [
        { ...serial, key: trackingChoiceKey(serial) },
      ]),
    ).toEqual([
      {
        stock_tracking_unit_id: 11,
        serial_number: "SN-01",
        quantity: "1.000",
        location_id: 7,
        lot_code: undefined,
        expires_on: undefined,
      },
    ]);
    expect(
      trackingPayload("lot", "2.000", [
        { ...lot, key: trackingChoiceKey(lot) },
      ]),
    ).toEqual([
      {
        stock_tracking_unit_id: 12,
        lot_code: "LOT-02",
        expires_on: "2027-12-31",
        quantity: "2.000",
        location_id: 8,
        serial_number: undefined,
      },
    ]);
  });

  it("subtracts every previous return, including scrap, from immutable shipment tracking", () => {
    const snapshot: StockTrackingAllocation[] = [
      { stock_tracking_unit_id: 21, lot_code: "LOT-A", quantity: "5.000" },
      { stock_tracking_unit_id: 22, lot_code: "LOT-B", quantity: "3.000" },
    ];
    const returns: FulfillmentReturn[] = [
      {
        id: 1,
        disposition: "scrap",
        warehouse_id: null,
        receipt_document_id: null,
        note: null,
        received_at: "",
        items: [
          {
            id: 1,
            shipment_item_id: 9,
            qty: "2.000",
            component_snapshot: {
              tracking_allocations: [
                {
                  stock_tracking_unit_id: 21,
                  lot_code: "LOT-A",
                  quantity: "2.000",
                },
              ],
            },
          },
        ],
      },
      {
        id: 2,
        disposition: "sellable",
        warehouse_id: 4,
        receipt_document_id: 3,
        note: null,
        received_at: "",
        items: [
          {
            id: 2,
            shipment_item_id: 9,
            qty: "1.000",
            component_snapshot: {
              tracking_allocations: [
                {
                  stock_tracking_unit_id: 22,
                  lot_code: "LOT-B",
                  quantity: "1.000",
                },
              ],
            },
          },
          {
            id: 3,
            shipment_item_id: 10,
            qty: "1.000",
            component_snapshot: {
              tracking_allocations: [
                {
                  stock_tracking_unit_id: 21,
                  lot_code: "LOT-A",
                  quantity: "1.000",
                },
              ],
            },
          },
        ],
      },
    ];

    const choices = returnTrackingChoices(snapshot, returns, 9);
    expect(
      choices.map((choice) => [
        choice.stock_tracking_unit_id,
        choice.available,
      ]),
    ).toEqual([
      [21, "3.000"],
      [22, "2.000"],
    ]);
    expect(
      trackingPayload(
        "lot",
        "2.000",
        choices.map((choice) => ({
          ...choice,
          quantity: choice.stock_tracking_unit_id === 21 ? "2.000" : "0.000",
        })),
      ),
    ).toMatchObject([
      { stock_tracking_unit_id: 21, lot_code: "LOT-A", quantity: "2.000" },
    ]);
  });

  it("keeps an optional destination location in the return payload and resets it when the destination changes", () => {
    const choices = [
      {
        key: "id:30|location:none",
        stock_tracking_unit_id: 30,
        lot_code: "LOT-DEST",
        location_id: 14,
        available: "2.000",
        quantity: "2.000",
      },
    ];

    expect(trackingPayload("lot", "2.000", choices)).toMatchObject([
      { stock_tracking_unit_id: 30, location_id: 14 },
    ]);
    expect(resetTrackingChoiceLocations(choices)).toEqual([
      { ...choices[0], location_id: null },
    ]);
  });
});
