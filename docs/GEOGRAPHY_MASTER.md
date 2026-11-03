# Master Wilayah Papua

## Snapshot resmi aktif

- Sumber: BPS SIG, _Kode Relasi BPS dengan Kemendagri_.
- Periode sumber: `2025_2.2025`.
- Berkas yang dipin: `storage/app/private/geography-imports/source/papua-bps-2025-semester-2.csv`.
- SHA-256: `2f235ea43bcb9d81ce77793ee3dd772bf1cb3d3889d63525e2c1478d0fa01575`.
- URL rujukan: <https://sig.bps.go.id/bridging-kode/>.

| Level | Jumlah |
| --- | ---: |
| Provinsi | 1 |
| Kabupaten | 8 |
| Kota | 1 |
| Distrik | 106 |
| Kelurahan/Kampung | 1.029 |
| Total | 1.145 |

Snapshot ini mencakup Provinsi Papua setelah pemekaran yang berlaku pada periode tersebut: Kabupaten Biak Numfor, Jayapura, Keerom, Kepulauan Yapen, Mamberamo Raya, Sarmi, Supiori, Waropen, dan Kota Jayapura.

## Pengelolaan

- Super Admin dapat menambah, mengubah nama, dan menghapus wilayah melalui **Wilayah & TPS**.
- CSV manual memakai header `provinsi,kabupaten_kota,distrik,kelurahan_kampung` dan tidak memerlukan kode.
- CSV sumber resmi juga dapat memakai header `code,name,level,parent_code`; kode BPS dipertahankan sebagai identitas master.
- Penghapusan ditolak jika wilayah memiliki turunan, TPS, atau telah digunakan konfigurasi event. Ini menjaga rekam jejak event, laporan, dan audit.
- Bila nama/manual region yang sudah ada cocok dengan BPS pada induk yang sama, import BPS mempertahankan ID region beserta relasinya lalu memperbarui kode dan provenance menjadi BPS.

Saat BPS/Kemendagri menerbitkan pembaruan, unggah batch baru untuk preview dan review sebelum commit. Jangan menghapus batch atau rekam audit lama.
