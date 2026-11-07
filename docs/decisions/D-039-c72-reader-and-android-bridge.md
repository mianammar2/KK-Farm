# D-039: Reference reader is the Chainway C72; Android bridge to the web app
2026-11-07 · Status: **Proposed** · Answers Q-RF-1, Q-RF-2 (partly), Q-RF-3 in [OPEN_QUESTIONS.md](../OPEN_QUESTIONS.md); relates to D-028

## Context
The project lead supplied photos of the farm's test readers. From the About screens: model **C72**, Android 13, MediaTek MT6765V/CB (8 cores), 4 GB RAM, 64 GB, 1080×1920, build `C72.AD13.GL.20250303.V1.12`, DeviceAPI `ver20241230`, Chainway app 3.1.5, Zebra 2D barcode engine, Wi-Fi + dual-SIM 4G. The pistol-grip unit with an RF-wave antenna panel is a UHF (EPC Gen2) reader. AnyConfig 1.09 and CDM 1.29 are Chainway's config tools. Device serial numbers, IMEIs and MACs appear in the photos and must stay out of the repository.

## Decision
- The reference reader is the Chainway C72 (UHF EPC Gen2). Tags are assumed UHF until a tag is inspected (Q-RF-2 stays open for the tag brand and EPC format).
- Integration uses the **Chainway DeviceAPI** (not Zebra's SDK). The SDK is vendor-supplied; check its licence before committing binaries, otherwise keep it out of Git and document setup.
- The web app stays a PWA (D-028). A small native Android component on the C72 reads tags and hands them to the PWA. Order of preference: (1) keyboard-wedge / broadcast intent configured in AnyConfig, for the first spike; (2) a thin Kotlin bridge or Capacitor wrapper calling DeviceAPI directly, for single-tag mode, power and RSSI control.
- Action flows (move, treat, weigh) require a **single-nearest-tag mode** (low power, RSSI threshold, one result per trigger). Inventory mode (many tags) is used only for pen counts and compares the scanned set with the expected set.
- The hardware-agnostic scan interface from RFID_INTEGRATION_RESEARCH.md §4 is unchanged: the app resolves `tag_code → open rfid_assignment → animal`.

## Consequences
- First hardware spike: print the trigger key codes, read an EPC, and run a single-tag read against real tags.
- Barcode/QR scanning (Zebra engine) can identify pens, silos and medicine bottles; the camera supports photo records.
- Android 13 background limits mean scanning runs in a foreground context and sync uses a constrained background job.
- D-036's "no live hardware" restriction needs an explicit owner decision before the spike (see Open items in CLAUDE.md).
