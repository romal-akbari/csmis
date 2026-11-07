import assert from "node:assert/strict";
import { test } from "node:test";
import {
  DIRECTORY_RESOURCES,
  changeDirectoryValue,
  directoryInitialValues,
  directoryOptions,
  directoryPayload,
  directoryToday,
  validDirectoryDate,
  validateDirectory,
} from "../src/lib/directories.ts";

const resource = (key) => DIRECTORY_RESOURCES.find((item) => item.key === key);
const lookups = {
  provinces: [
    { id: 1, name: "Kabul" },
    { id: 2, name: "Balkh" },
  ],
  districts: [
    { id: 10, province_id: 1, name: "District A" },
    { id: 20, province_id: 2, name: "District B" },
  ],
  areas: [
    { id: 100, district_id: 10, name: "Area A" },
    { id: 200, district_id: 20, name: "Area B" },
  ],
  managers: [{ id: 7, name: "Manager", branch_id: 1 }],
  company: [{ id: 1, name: "Asia/Kabul" }],
};
const weaver = {
  ...directoryInitialValues(resource("weavers")),
  name: "بافنده کابل",
  province_id: "1",
  district_id: "10",
  area_id: "100",
  manager_employee_id: "7",
  phone: "+93 700 123 456",
  registration_date: "2024-02-29",
};

test("every directory rejects empty required fields without HTML validation", () => {
  for (const item of DIRECTORY_RESOURCES) {
    assert.ok(
      Object.keys(
        validateDirectory(item, directoryInitialValues(item), lookups),
      ).length,
    );
  }
});

test("a valid normalized weaver accepts Dari text and branch manager assignment", () => {
  assert.deepEqual(
    validateDirectory(resource("weavers"), weaver, lookups, false),
    {},
  );
});

test("cascading parent changes clear descendants and limit available choices", () => {
  assert.deepEqual(changeDirectoryValue(weaver, "province_id", "2"), {
    ...weaver,
    province_id: "2",
    district_id: "",
    area_id: "",
  });
  assert.equal(changeDirectoryValue(weaver, "district_id", "20").area_id, "");
  assert.deepEqual(
    directoryOptions(
      resource("weavers").fields.find((field) => field.key === "district_id"),
      weaver,
      lookups,
    ).map((option) => option.id),
    [10],
  );
  assert.deepEqual(
    directoryOptions(
      resource("weavers").fields.find((field) => field.key === "area_id"),
      weaver,
      lookups,
    ).map((option) => option.id),
    [100],
  );
});

test("forged geographic combinations, missing IDs, and out of scope managers fail", () => {
  for (const [field, value] of [
    ["province_id", "999"],
    ["district_id", "20"],
    ["area_id", "200"],
    ["manager_employee_id", "8"],
    ["district_id", "1e1"],
  ]) {
    assert.ok(
      validateDirectory(
        resource("weavers"),
        { ...weaver, [field]: value },
        lookups,
      )[field],
      field,
    );
  }
});

test("only administrators may leave a weaver manager unassigned", () => {
  const values = { ...weaver, manager_employee_id: "" };
  assert.deepEqual(
    validateDirectory(resource("weavers"), values, lookups, true),
    {},
  );
  assert.ok(
    validateDirectory(resource("weavers"), values, lookups, false)
      .manager_employee_id,
  );
});

test("phone validation matches server length and leading digit rules", () => {
  for (const phone of [
    "123456",
    "(070) 12345",
    "++93700123456",
    "phone123",
    "1234567890123456789012345678901",
  ]) {
    assert.ok(
      validateDirectory(resource("weavers"), { ...weaver, phone }, lookups)
        .phone,
      phone,
    );
  }
  for (const phone of ["", "1234567", "+93 700-123(456)"]) {
    assert.equal(
      validateDirectory(resource("weavers"), { ...weaver, phone }, lookups)
        .phone,
      undefined,
      phone,
    );
  }
});

test("strict dates reject impossible days, noncanonical input, and future dates", () => {
  for (const date of [
    "2024-02-30",
    "2025-02-29",
    "2024-2-9",
    "0999-12-31",
    "not-a-date",
  ])
    assert.equal(validDirectoryDate(date), false, date);
  for (const date of ["1000-01-01", "2024-02-29"])
    assert.equal(validDirectoryDate(date), true, date);
  assert.ok(
    validateDirectory(
      resource("weavers"),
      { ...weaver, registration_date: "9999-12-31" },
      lookups,
    ).registration_date,
  );
  assert.match(directoryToday("Asia/Kabul"), /^\d{4}-\d{2}-\d{2}$/);
  assert.deepEqual(
    validateDirectory(
      resource("weavers"),
      { ...weaver, registration_date: directoryToday() },
      lookups,
    ),
    {},
  );
});

test("Unicode length limits and status enums are checked on the client", () => {
  assert.ok(
    validateDirectory(
      resource("provinces"),
      { name: "ا", status: "active" },
      lookups,
    ).name,
  );
  assert.ok(
    validateDirectory(
      resource("provinces"),
      { name: "ا".repeat(101), status: "active" },
      lookups,
    ).name,
  );
  assert.ok(
    validateDirectory(
      resource("suppliers"),
      { name: "Supplier", status: "deleted", notes: "ا".repeat(1001) },
      lookups,
    ).notes,
  );
  assert.ok(
    validateDirectory(
      resource("suppliers"),
      { name: "Supplier", status: "deleted" },
      lookups,
    ).status,
  );
});

test("normalized payloads omit derived geography and trim nullable optional fields", () => {
  const payload = directoryPayload(resource("weavers"), {
    ...weaver,
    name: "  Weaver  ",
    phone: " ",
    manager_employee_id: "",
  });
  assert.equal(payload.name, "Weaver");
  assert.equal(payload.phone, null);
  assert.equal(payload.manager_employee_id, null);
  assert.equal(payload.area_id, 100);
  assert.equal(Object.hasOwn(payload, "province_id"), false);
  assert.equal(Object.hasOwn(payload, "district_id"), false);
  const areaPayload = directoryPayload(resource("areas"), {
    name: "Area",
    province_id: "1",
    district_id: "10",
    status: "active",
  });
  assert.equal(areaPayload.district_id, 10);
  assert.equal(Object.hasOwn(areaPayload, "province_id"), false);
});
