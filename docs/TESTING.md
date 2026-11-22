# Pengujian dan status verifikasi

## Pemeriksaan tanpa instalasi

Hasil pemeriksaan saat penulisan: 58 file PHP dan entrypoint artisan lulus `php -l`; sembilan pemeriksaan fungsi murni gram/rupiah, jarak garis lurus dan sanitasi CSV lulus. Target controller pada route, kesetaraan tabel/kolom SQL terhadap migration, tautan dokumentasi, JSON Composer dan XML PHPUnit juga diperiksa secara statis. Pemeriksaan tersebut tidak menjalankan aplikasi/database dan tidak menggantikan feature test.

## Pengujian otomatis setelah instalasi

```powershell
php artisan test
```

`phpunit.xml` menggunakan SQLite `:memory:` dan HTTP fake; tidak menghubungi Midtrans sungguhan. Memerlukan ekstensi SQLite. Jangan mengganti DB pengujian ke database operasional karena RefreshDatabase menerapkan ulang skema.

Test yang disertakan mencakup:

- Konversi gram, pembulatan total, Haversine dan pencegahan formula CSV.
- Checkout berulang, key sama/payload berbeda, penolakan stok berlebih dan perubahan harga.
- Snapshot produk/harga, pembatalan/expiry berulang, stok tidak dilepas pada 404 gateway.
- Ketidaksesuaian nominal, fraud challenge, signature palsu dan webhook berulang dengan status server terbaru.
- Penolakan akses order akun lain dan registrasi admin publik.
- Penyelesaian tepat satu kali, faktor karbon tersimpan dan selisih berat menunggu admin.
- Inisiasi Snap berulang/timeout, privasi bukti dan normalisasi waktu.
- Kompilasi seluruh baseline ke SQL MySQL tanpa koneksi database, memastikan waktu bisnis wajib memakai DATETIME dan tidak memiliki default TIMESTAMP implisit.

Feature test belum dijalankan saat penulisan karena pemilik meminta instalasi Composer/migration dikerjakan sendiri. Jangan menyatakan lulus sampai output aktual tersedia.

## Konkurensi MySQL/InnoDB (wajib sebelum produksi)

SQLite tidak membuktikan SELECT FOR UPDATE. Siapkan database MySQL pengujian terpisah, dua akun buyer dan satu penawaran aktif 10 kg. Jalankan dua checkout paralel masing-masing 7 kg menggunakan key dan akun berbeda melalui dua proses HTTP. Hasil yang diharapkan: tepat satu berhasil, satu 409, satu order aktif, reserved=7000, available=3000. Ulangi dengan key yang sama pada akun yang sama; harus satu ID order. Jalankan pada server yang mendukung request paralel (bukan mengandalkan satu worker dev server).

Uji juga callback bersamaan dengan scheduler expire dan ready/receive bersamaan; tidak boleh terjadi reserved negatif, dua ledger penyaluran, atau penurunan paid menjadi unpaid karena notifikasi lama.

## Sandbox Midtrans

1. Ikuti MIDTRANS.md dan pasang URL notification yang dapat dijangkau.
2. Jalankan checkout → Snap → metode pembayaran sandbox → settlement.
3. Uji pending, cancel, expire, challenge, duplicate notification dan retry saat internet terputus.
4. Matikan endpoint callback sementara; jalankan maintain/sync untuk menguji pemulihan status.
5. Pastikan token Snap tidak terlihat dalam daftar order atau akun lain, dan Server Key tidak pernah muncul di response.
6. Uji refund melalui dashboard merchant dan pencatatannya di admin. Pastikan payout petani mengikuti prosedur operasional terpisah.

## Pemeriksaan manual alur dan operasional

- Farmer daftar → alamat → pengajuan + bukti → admin review → draf + foto + slot → publish.
- Buyer filter → checkout → payment → farmer ready → pickup → receive → laporan bertambah tepat sekali.
- Mismatch berat masuk issue; admin mencatat persetujuan, berat akhir dan refund yang diperlukan.
- Pengguna lain tidak dapat membaca order, alamat, bukti verifikasi, complaint/refund evidence atau mengganti data milik akun lain.
- CSV dengan nama komoditas berawalan `=`, `+`, `-`, `@` serta whitespace tidak dieksekusi sebagai formula.
- Uji Next.js pada lebar 360–1440, keyboard, kontras dan text scale. Peta gagal tetap menyisakan daftar.
- Uji 100 pengguna dan 10.000 penawaran, ukur p95. Indeks awal bukan bukti target performa.
- Uji backup database + storage dan restore ke lingkungan terpisah; catat RPO/RTO aktual.
