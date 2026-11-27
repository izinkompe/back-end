# Kontrak API v1

Base URL lokal: `http://localhost:8000/api/v1`. Kirim `Accept: application/json`. Untuk JSON, kirim `Content-Type: application/json`. Endpoint terlindungi memerlukan `Authorization: Bearer <token>`.

Nilai peran: `buyer`, `farmer`, `admin`. Tidak ada registrasi admin publik. Response resource tunggal berupa objek langsung kecuali yang dijelaskan di bawah; daftar berhalaman memakai paginator Laravel `{data, current_page, last_page, total, links, ...}` dengan 20 item (audit 50). Kategori dan favorit berupa array langsung.

Semua ID adalah angka. Berat input disarankan string kilogram, contoh `"1.250"`. Berat response/database `*_g` dalam gram. Harga berupa integer IDR. Timestamp response UTC; frontend mengubah ke Asia/Jakarta. Input waktu contoh `2026-12-01T10:00:00+07:00`.

## Error

| HTTP | Arti |
|---|---|
| 401 | Token tidak ada/tidak valid/kedaluwarsa |
| 403 | Peran atau kepemilikan tidak diizinkan, signature tidak valid |
| 404 | Objek tidak ditemukan, draf disembunyikan dari publik |
| 409 | Konflik stok, harga berubah, status tidak memungkinkan, key berbeda payload |
| 422 | Validasi, bentuk `{message, errors: {nama_field: [pesan]}}` |
| 429 | Rate limit; tunggu sebelum mencoba lagi |
| 502 | Hasil layanan Midtrans belum dapat dipastikan |
| 503 | Konfigurasi Midtrans belum tersedia |

Error koneksi gateway yang tidak tertangani mengembalikan error server dan dicatat; scheduler mencoba kembali. Jangan menampilkan detail debug ke pengguna produksi.

## Autentikasi dan profil

| Metode | Path | Akses dan input |
|---|---|---|
| POST | /auth/register | Publik: name, email, password, password_confirmation, role (buyer/farmer), phone?, organization? |
| POST | /auth/login | Publik: email, password |
| POST | /auth/logout | Token: mencabut token sesi aktif |
| POST | /auth/forgot-password | Publik: email; response netral agar email tidak mudah ditebak |
| POST | /auth/reset-password | Publik: email, token, password, password_confirmation; mencabut seluruh token akun |
| GET | /me | Token: `{user, addresses, verification}` |
| PATCH | /me | Token: name?, phone?, organization?, preferences?, email? + current_password saat mengganti email |
| POST | /addresses | Token: label, address, area, latitude, longitude, instructions? |
| PUT | /addresses/{id} | Pemilik: field lengkap seperti POST |
| GET | /notifications | Pemilik: notifikasi berhalaman |
| PATCH | /notifications/{id}/read | Pemilik: tanpa body |

Register/login menghasilkan `{user, token, token_type: "Bearer", expires_in: 604800}` **dan** cookie HttpOnly `pangantani_token` (`Path=/`, `SameSite=Lax`, `Secure` di production, umur 7 hari). Endpoint terlindungi menerima cookie tersebut atau header `Authorization: Bearer <token>`; header Bearer diutamakan. Request POST/PUT/PATCH/DELETE yang hanya membawa cookie wajib memiliki header `Origin` yang terdaftar di `CORS_ALLOWED_ORIGINS`, selain itu 403. Logout mencabut token dan mengirim cookie kedaluwarsa. Password minimal delapan karakter dengan huruf dan angka. Email dapat diganti melalui PATCH /me dengan current_password. Perubahan menghapus status verifikasi, membatalkan token reset email lama dan sesi lain, lalu mengirim tautan verifikasi email baru. Reset password hanya dikirim ke email akun yang sudah diverifikasi.

Contoh preferensi:

```json
{"preferences":{"high_contrast":true,"text_scale":1.5,"reminders":true,"radius_km":25,"latitude":-6.59,"longitude":106.8}}
```

## Katalog dan penawaran

| Metode | Path | Akses |
|---|---|---|
| GET | /categories | Publik |
| GET | /offers | Publik, daftar aktif tersedia |
| GET | /offers/{id} | Publik untuk selain draf; `{offer, similar}`; tautan tertutup tetap dapat dibuka |
| GET | /farmer/offers | Petani, milik sendiri termasuk draf |
| POST | /farmer/offers | Petani, membuat draf |
| PUT | /farmer/offers/{id} | Petani pemilik, mengganti data penawaran/jadwal tersedia |
| POST | /farmer/offers/{id}/publish | Petani pemilik terverifikasi |
| POST | /farmer/offers/{id}/close | Petani pemilik: reason, affects_orders? |
| POST | /farmer/offers/{id}/duplicate | Petani pemilik, draf baru tanpa stok/tanggal/kondisi/jadwal; foto dan informasi dasar disalin |
| POST | /admin/offers/{id}/close | Admin: reason, affects_orders? |

Query katalog: `q`, `category_id`, `min_price`, `max_price`, `min_stock_kg`, `pickup_from`, `pickup_to`, `latitude`, `longitude`, `radius_km`, `sort=newest|price_asc|price_desc|distance`, `closing_soon=1`, `page`. `radius_km` memerlukan dua koordinat. Harga filter/sort adalah harga efektif pada waktu server. Radius memakai garis lurus dengan label `distance_km`, bukan jarak tempuh. Daftar dan marker peta frontend harus memakai hasil API yang sama.

Contoh body create/update penawaran (ganti ID/tanggal):

```json
{
  "name":"Bayam surplus", "category_id":1, "address_id":1,
  "description":"Hasil panen kebun", "surplus_reason":"Panen melebihi permintaan",
  "condition":"Daun baik, ukuran beragam", "harvest_date":"2026-12-01",
  "stock_kg":"10.000", "price_per_kg":10000, "minimum_price":6000,
  "closes_at":"2026-12-02T12:00:00+07:00",
  "slots":[{"starts_at":"2026-12-02T14:00:00+07:00","ends_at":"2026-12-02T17:00:00+07:00"}],
  "price_steps":[{"starts_at":"2026-12-02T08:00:00+07:00","price_per_kg":8000}]
}
```

Draf dapat dibuat cukup dengan name; slots=[] diterima. Input commodity menyimpan nama komoditas (misalnya Bayam), terpisah dari nama pemasaran penawaran. Field yang tidak dikirim saat update dipertahankan. Penerbitan memerlukan kategori, alamat, kondisi, alasan surplus, tanggal panen, foto, stok layak, harga awal/minimum, tenggat dan jadwal sesudah tenggat. Tanggal panen tidak boleh di masa depan. Harga tahap harus berada di antara minimum dan harga awal.

Jika slots dikirim, seluruh slot tersedia diganti dengan ID baru; snapshot order lama tetap berlaku. Tahap harga tetap dipertahankan jika price_steps tidak dikirim; kirim [] untuk menghapusnya. Kondisi yang berubah dianggap memengaruhi pemenuhan secara default; petani dapat menyatakan affects_orders=false jika perubahan tidak mengganggu pemenuhan. Jika memengaruhi pesanan, penawaran ditutup dan pesanan aktif masuk issue otomatis.

Atribut server: current_price, available_kg, available_g, shortage_g, effective_status, seconds_until_close, share_url. Daftar katalog juga memuat server_time, distance_type=straight_line, message dan clear_filters_url untuk hasil kosong.

| Metode | Path | Input/hasil |
|---|---|---|
| PATCH | /farmer/offers/{id}/inventory | stock_kg wajib (stok fisik), reason wajib; condition?, affects_orders?, fit_for_sale? |
| POST | /farmer/offers/{id}/confirm-resale | fit_for_sale=true dan reason; pernyataan petani bahwa stok yang dilepas layak dijual |
| GET | /farmer/offers/{id}/price-preview | Harga awal/minimum/efektif, tahap dan waktu server |

Koreksi stok di bawah reservasi diterima dan otomatis membuka penanganan seluruh order aktif, menutup penawaran serta mengirim pemberitahuan. Stok tersedia tetap minimal nol. Pembatalan/expiry melepaskan reservasi tepat sekali, tetapi stok tersebut masuk withheld_g sampai petani menyatakan layak melalui confirm-resale atau inventory dengan fit_for_sale=true. Confirm-resale tidak membuka penawaran yang ditutup; publish diperlukan setelah data lengkap dan stok cukup.

## Verifikasi dan upload

| Metode | Path | Input/hasil |
|---|---|---|
| POST | /farmer/verification | Petani: description; membuat/mengirim ulang pengajuan ditolak/perlu perbaikan |
| GET | /admin/verifications | Admin: filter `status=pending|approved|rejected|needs_revision`, termasuk applicant dan evidence |
| PATCH | /admin/verifications/{id} | Admin: status (approved/rejected/needs_revision), reason wajib |
| POST | /media | Token, multipart: kind, target_id, file |
| GET | /media/{id} | Foto penawaran selain draf publik; bukti privat perlu Bearer dan izin |

Jenis upload: `offer_photo` Ã¢â€ â€™ ID offer milik petani; `verification` Ã¢â€ â€™ ID pengajuan sendiri; `complaint` Ã¢â€ â€™ ID pengaduan sendiri; `refund` Ã¢â€ â€™ ID catatan refund, admin saja. JPEG/PNG maksimal 5 MB; MIME dan isi gambar diperiksa di server. kind=clarification memakai target_id pesan klarifikasi sendiri. Response metadata memuat `id`, `url`, `kind`, `mime`, `size`; tidak memuat path disk. Tidak ada URL storage privat yang dapat diakses langsung.

Urutan verifikasi: buat pengajuan Ã¢â€ â€™ upload bukti ke ID pengajuan Ã¢â€ â€™ admin meninjau. Persetujuan ditolak jika bukti belum ada. Urutan penawaran: buat draf Ã¢â€ â€™ upload foto Ã¢â€ â€™ publish. Gunakan header Bearer saat mengambil bukti privat; komponen gambar browser biasa tidak menambahkan header tersebut.

## Pesanan, pembayaran dan pengambilan

| Metode | Path | Akses/input |
|---|---|---|
| GET | /orders | Token, sesuai peran; admin semua. Filter status, from, to, page |
| GET | /orders/{id} | Pihak transaksi/admin: `{order, contacts, history}` |
| POST | /orders | Pembeli: offer_id, pickup_slot_id, quantity_kg, expected_price_per_kg; header Idempotency-Key |
| POST | /orders/{id}/payment | Pembeli pemilik: buat/dapatkan Snap token |
| POST | /orders/{id}/sync-payment | Pihak transaksi/admin: cek status server Midtrans |
| POST | /payments/midtrans/notification | Publik khusus Midtrans: signature diverifikasi, status dikonfirmasi ke gateway |
| POST | /farmer/orders/{id}/ready | Petani pemilik: processing Ã¢â€ â€™ ready |
| POST | /farmer/orders/{id}/propose-pickup | Petani pemilik: starts_at, ends_at (pesanan aktif: awaiting_payment, processing, ready) |
| POST | /orders/{id}/pickup-decision | Pembeli pemilik: accept boolean (jadwal lama berlaku sampai disetujui; tidak memperpanjang tenggat bayar) |
| POST | /orders/{id}/receive | Pembeli pemilik: received_kg; sesuai berat Ã¢â€ â€™ completed, selisih Ã¢â€ â€™ issue |
| POST | /orders/{id}/cancel | Pembeli pemilik: reason; belum inisiasi langsung batal, lainnya Ã¢â€ â€™ issue |
| POST | /orders/{id}/complaints | Pihak transaksi/admin: description |

Contoh checkout:

```http
POST /api/v1/orders
Authorization: Bearer TOKEN
Idempotency-Key: checkout-unik-12345
Content-Type: application/json
Accept: application/json

{"offer_id":1,"pickup_slot_id":1,"quantity_kg":"1.250","expected_price_per_kg":10000}
```

Gunakan key yang sama jika mengulang request karena jaringan terputus. Key harus 8Ã¢â‚¬â€œ100 karakter huruf, angka, `_`, `-`. Key sama dengan payload berbeda ditolak. Untuk checkout baru gunakan key baru. Payload sama/key sama mengembalikan ID pesanan yang sama (HTTP 201 juga untuk replay).

Status order: `awaiting_payment Ã¢â€ â€™ processing Ã¢â€ â€™ ready Ã¢â€ â€™ completed`; cabang `cancelled` dan `issue`. Status pembayaran terpisah: `unpaid`, `paid`, `failed`, `expired`, atau status refund/chargeback gateway. `payment.status` menyimpan keadaan gateway/internal seperti `not_started`, `initiating`, `uncertain`, `pending`, `settlement`. Jangan menyamakan ketiga field tersebut.

Response payment: `{snap_token, redirect_url, client_key, production}`. Token Snap hanya diberikan di endpoint payment milik pembeli, tidak dalam daftar pesanan. Redirect/callback frontend tidak mengubah status menjadi paid. Baca ulang order sesudah callback.

## Pengaduan dan administrasi transaksi

| Metode | Path | Input |
|---|---|---|
| GET | /admin/complaints | status=open/clarification/closed |
| PATCH | /admin/complaints/{id} | status=clarification/closed, response wajib |
| POST | /admin/orders/{id}/cancel-payment | reason, hanya order issue belum paid; mencoba cancel gateway lalu cek status |
| POST | /admin/orders/{id}/resolve | action=complete/cancel, reason dan field berikut |
| PUT | /admin/orders/{id}/refund | amount_idr, status=requested/processing/completed, reason, reference wajib saat completed |
| GET | /admin/audit-logs | Audit berhalaman |

`resolve complete`: received_kg positif sampai berat pesanan, buyer_agreed=true, agreement dan reason wajib. Pembeli harus sudah mengirim konfirmasi penerimaan sendiri melalui /receive (buyer_confirmed_at); checkbox admin tidak dapat menggantikan konfirmasi itu. Berat akhir tidak boleh melebihi stok fisik. `resolve cancel`: restock_safe boolean; gateway harus terminal belum bayar atau refund penuh tercatat. restock_safe=false menutup penawaran. Baik true maupun false tidak menggantikan pernyataan kelayakan petani; stok yang dilepas tetap ditahan di withheld_g. Pengaduan dan penyelesaian pesanan merupakan tindakan terpisah: menutup tiket tidak otomatis mengubah stok/order.

Endpoint refund **mencatat** pengembalian dana yang sudah diurus melalui Midtrans Dashboard/kanal operasional; tidak mengirim dana. Satu record agregat per order; tidak dapat ditimpa setelah completed. Buat record status requested/processing terlebih dahulu, upload bukti dengan kind=refund, lalu ubah ke completed dengan reference. Server menolak completed tanpa bukti. Payout ke petani dan rekonsiliasi pembagian dana tidak disediakan.

## Favorit, kebutuhan dan laporan

| Metode | Path | Akses/input |
|---|---|---|
| GET, POST | /favorites | Pembeli; POST kind=farmer/commodity, value=ID petani/nama komoditas |
| DELETE | /favorites/{id} | Pembeli pemilik |
| GET | /needs | Token, kebutuhan aktif; mine=1 untuk milik sendiri |
| POST | /needs | Pembeli: commodity, quantity_kg, area, needed_at |
| PATCH | /needs/{id} | Pembeli pemilik: status=fulfilled/closed |
| GET | /needs/{id}/responses | Pemilik/admin semua tanggapan; petani melihat tanggapannya sendiri |
| POST | /farmer/needs/{id}/responses | Petani: offer_id, pickup_slot_id, quantity_kg, message? |
| POST | /needs/{id}/responses/{id}/select | Pembeli pemilik: mengembalikan `{checkout, quoted_price_per_kg}` |
| GET | /farmer/reports | Petani: laporan dan inventory miliknya |
| GET | /admin/reports | Admin: ringkasan dan transaksi sumber |
| GET | /admin/reports/export | Admin: stream CSV UTF-8 |
| GET, POST | /admin/emission-factors | Admin; POST category_id, kg_co2e_per_kg, source, version, method, assumptions |

Favorit komoditas mencocokkan commodity tanpa membedakan huruf besar/kecil (fallback nama penawaran untuk data lama). Favorit petani mencocokkan farmer_id. Pengingat diaktifkan lewat preferences, dengan radius opsional; satu notifikasi per user/offer. Fitur salin/share tautan dan rendering preferensi berada di frontend.

Pemilihan tanggapan kebutuhan tidak langsung menahan stok. Tampilkan konfirmasi, lalu POST payload checkout ke `/orders` dengan key baru. Jika jadwal/harga/stok berubah, validasi checkout tetap berlaku. Kebutuhan ditandai fulfilled oleh pembeli dan tidak otomatis selesai saat memilih tanggapan.

Laporan menerima filter from, to, area, commodity. Komoditas pada laporan berasal dari commodity snapshot order, dengan fallback nama produk untuk data lama. Export menerima scope=distribution (default, tanggal selesai) atau scope=orders (semua status, tanggal pembuatan). CSV membedakan berat_dipesan_kg dan berat_diterima_kg; field penerimaan kosong untuk order yang belum memiliki ledger. `summary.distributed_g` dalam gram, `estimated_co2e_kg` dalam kgCO2e; total estimasi null berarti belum ada data yang bisa dihitung. `uncalculated_orders` menghitung transaksi tanpa faktor. `transactions.data[].factor_snapshot` memuat sumber, versi, metode dan asumsi. Membuat faktor baru menonaktifkan versi aktif kategori yang sama; snapshot historis dipertahankan.


## Verifikasi email

| Metode | Path | Input/hasil |
|---|---|---|
| POST | /auth/email/verification-notification | Token akun: kirim ulang tautan, dibatasi rate limit |
| GET | /auth/email/verify/{id}/{hash}?expires=...&signature=... | Token akun terkait dan URL signed valid, berlaku 60 menit |

Registrasi mengirim tautan ke halaman Next.js /verify-email dengan query verification_url. Frontend meneruskan request GET ke URL signed itu dengan Bearer token akun yang sesuai; jangan mengubah urutan/nilai parameter atau host URL. Reset password hanya dikirim setelah email terverifikasi. Mode mail array adalah in-memory dan tidak mengirim email; gunakan SMTP/mailbox pengembangan agar pengguna bisa mengakses tautan. Token tidak ditulis ke log aplikasi.

## Klarifikasi pengaduan

POST /orders/{id}/complaints menerima type opsional: quality, weight, pickup, payment, cancellation, other (default). GET /admin/complaints menerima filter type dan status. Admin PATCH /admin/complaints/{id} mencatat tanggapan ke thread serta memberi tahu kedua pihak.

GET /complaints/{id} menampilkan evidence dan messages bagi pihak transaksi/admin. POST /complaints/{id}/messages menerima message (maksimal 5.000 karakter); thread tertutup menolak balasan. Pesan dapat diberi bukti JPEG/PNG melalui POST /media dengan kind=clarification dan target_id ID pesan milik pengirim.

## Koreksi laporan transaksi selesai (BR-10)

POST /admin/orders/{id}/correct-distribution memerlukan Idempotency-Key seperti checkout dan body:

```json
{"received_kg":"6.000","expected_received_kg":"7.000","buyer_agreed":true,"reason":"Koreksi hasil timbang","agreement":"Pembeli dan petani menyepakati berat akhir 6 kg"}
```

Hanya admin dan order completed yang diizinkan. expected_received_kg harus sama dengan berat terkini untuk mencegah koreksi menimpa perubahan lain. Retry key/payload sama tidak menggandakan koreksi; key sama/payload berbeda ditolak. Sistem memperbarui order, sold_g dan baris distributions yang sama, menghitung ulang estimasi memakai snapshot faktor historis, menyimpan distribution_corrections serta audit sebelum/sesudah dan alasan. Koreksi tidak otomatis menaikkan stok jual atau mengurus refund. Berat koreksi boleh nol sampai berat pesanan.

GET /orders/{id} kini memuat corrections dan riwayat gabungan order, pembayaran, refund, pengaduan dan ledger, termasuk aktor serta alasan. Payment memuat status_message dan fraud_status dari gateway, tanpa Server Key/token Snap/signature.

## Faktor emisi

POST /admin/emission-factors juga menerima unit=kgCO2e/kg, method_code=weight_factor_v1, valid_from?, valid_until?. Metode yang didukung adalah berat diterima (kg) dikali faktor (kgCO2e/kg). Unit/metode lain ditolak agar tidak menerapkan rumus yang salah. Faktor yang tidak aktif, belum berlaku atau kedaluwarsa tidak dipakai; estimasi tetap null. Source, version, method (penjelasan) dan assumptions wajib. Snapshot historis tidak berubah ketika versi baru diterbitkan.


## Perlindungan moderasi administrator

Penutupan melalui /admin/offers/{id}/close mencatat moderated_at dan alasan privat. Petani tidak dapat menerbitkan ulang penawaran tersebut sebelum admin meninjau. POST /admin/offers/{id}/release-moderation dengan reason wajib mencabut blokir admin, tetapi penawaran tetap closed sampai petani menerbitkan ulang dengan informasi lengkap. /farmer/offers menampilkan moderation_reason kepada pemilik; alasan tidak dimuat pada katalog/detail publik. Penutupan tidak menghapus order yang sudah dibuat.
