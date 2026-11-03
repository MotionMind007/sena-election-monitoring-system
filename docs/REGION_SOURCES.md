# Papua Region Sources

## Locked first-release scope

The first deployment is limited to Provinsi Papua after the 2022 provincial split. BPS Papua identifies eight regencies and one municipality in this scope:

| BPS code | Region |
|---|---|
| `9403` | Kabupaten Jayapura |
| `9408` | Kabupaten Kepulauan Yapen |
| `9409` | Kabupaten Biak Numfor |
| `9419` | Kabupaten Sarmi |
| `9420` | Kabupaten Keerom |
| `9426` | Kabupaten Waropen |
| `9427` | Kabupaten Supiori |
| `9428` | Kabupaten Mamberamo Raya |
| `9471` | Kota Jayapura |

The application seeds only these verified province/regency/city records. It does not fabricate district, village, or TPS records.

## Primary references

- [BPS Papua — Area by Regency/Municipality, 2025](https://papua.bps.go.id/id/statistics-table/3/VUZwV01tSlpPVlpsWlRKbmMxcFhhSGhEVjFoUFFUMDkjMw%3D%3D/luas-daerah-dan-jumlah-pulau-menurut-kabupaten-kota-di-provinsi-papua--2021.html), updated 25 February 2026 and sourced to Ministry of Home Affairs Decree 300.2.2-2138/2025.
- [BPS Papua — Number of Districts by Regency/Municipality, 2024](https://papua.bps.go.id/id/statistics-table/3/S3pkSVdHdG1iVU50TXlzdlNYY3pZVzFVV1haVlFUMDkjMw%3D%3D/jumlah-kecamatan-menurut-kabupaten-kota-di-provinsi-papua-selatan--2024.html?year=2024), sourced to BPS Chief Decree 646/2024.
- [BPS Papua in Figures 2025](https://papua.bps.go.id/id/publication/2025/02/28/a6f1b1b106eed051e9bdc870/provinsi-papua-dalam-angka-2025.html).
- [KPU JDIH region selector](https://jdih.kpu.go.id/set_wilayah) for election-specific cross-checking.

## Import rule

District/village files are normalized to UTF-8 CSV with the exact header `code,name,level,parent_code`. Every batch stores source (`BPS` or `KPU`), source version, original filename, checksum, preview summary, actor, status, and commit timestamp. Imports reject duplicate codes, missing parents, invalid hierarchy, cycles, and roots outside Papua code `94`.

TPS records are not sourced from these BPS region tables. Super Admin provisions the confirmed TPS count per desa/kelurahan for an event; repeated provisioning is idempotent and never deletes existing TPS automatically.
