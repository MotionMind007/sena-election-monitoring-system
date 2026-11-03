# Text Wireframes

## Desktop Super Admin — single drilldown dashboard
```text
┌──────────────┬────────────────────────────────────────────────────────────────────────────┐
│ ElectCount   │ Search            [Event ▼] [Contest ▼] [Profile]                        │
│ Dashboard    ├────────────────────────────────────────────────────────────────────────────┤
│ Real Count   │ Province > Kab/Kota > Kecamatan > Desa > TPS                             │
│ Monitoring   ├────────────────────────────────────────────────────────────────────────────┤
│ Approval     │ [Total TPS] [Submitted] [Verified] [Review] [Revision] [Missing]         │
│ Events       ├──────────────────────┬─────────────────────────┬──────────────────────────┤
│ TPS/Regions  │ Geographic progress  │ Verified vote totals    │ Historical trend*        │
│ Users        │ map (if validated)   │ per contest participant │ (only real snapshots)    │
│ Audit        ├──────────────────────┴─────────────────────────┴──────────────────────────┤
│ Settings     │ Child-region progress table | Status distribution | Activity            │
│              ├────────────────────────────────────────────────────────────────────────────┤
│              │ TPS monitoring table (filtered to selected region and contest)           │
└──────────────┴────────────────────────────────────────────────────────────────────────────┘
```
`*` Trend chart is optional until trustworthy historical snapshot data exists.

## Mobile witness
```text
┌──────────────────────────┐
│ ElectCount        [Bell] │
│ Halo, Saksi              │
│ [Event ▼] [Contest ▼]    │
│ TPS Saya                 │
│ ┌──────────────────────┐ │
│ │ TPS 001  [Status]    │ │
│ │ Desa / Kecamatan     │ │
│ │ [Input / Detail]     │ │
│ └──────────────────────┘ │
│ [Total]    [Verified]    │
│ Home  Laporan Hasil Akun │
└──────────────────────────┘
```

## Mobile form
```text
[← TPS 001] [Contest]
[Upload foto bukti]
[OCR bantu isi] (optional)
[Peserta 01  ____]
[Peserta 02  ____]
[Suara tidak sah ____]
[Total otomatis ____]
[Review & Kirim]
```

## Approval detail desktop
```text
[Queue + filters] | [Evidence zoom] | [Input values + validation]
                  | [Version history and coordinator recommendation]
                  | [Approve] [Revision] [Reject]  (admin only)
```
