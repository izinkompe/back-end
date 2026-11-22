# Pengujian dan status verifikasi

## Pemeriksaan tanpa instalasi

Pemeriksaan sintaks PHP dan kompilasi SQL dilakukan tanpa koneksi database operasional. Test kompilasi mencakup baseline dan migration revisi SRS (23 tabel); waktu bisnis wajib menggunakan DATETIME tanpa default TIMESTAMP implisit.

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

Revisi SRS diuji dengan PHPUnit melalui bootstrap/harness lokal pada SQLite :memory: dan HTTP/notification fake. Guard tests/TestCase.php memaksa koneksi in-memory dan menolak config cache, sehingga pengujian tidak memakai database MySQL milik pengguna. Migrasi hanya diterapkan di database sementara pengujian yang hilang setelah proses selesai. Tidak ada composer install, migration database pengguna, atau transaksi Midtrans sungguhan yang dijalankan.

## Konkurensi MySQL/InnoDB (wajib sebelum produksi)

SQLite tidak membuktikan SELECT FOR UPDATE. Siapkan database MySQL pengujian terpisah, dua akun buyer dan satu penawaran aktif 10 kg. Jalankan dua checkout paralel masing-masing 7 kg menggunakan key dan akun berbeda melalui dua proses HTTP. Hasil yang diharapkan: tepat satu berhasil, satu 409, satu order aktif, reserved=7000, available=3000. Ulangi dengan key yang sama pada akun yang sama; harus satu ID order. Jalankan pada server yang mendukung request paralel (bukan mengandalkan satu worker dev server).

Uji juga callback bersamaan dengan scheduler expire dan ready/receive bersamaan; tidak boleh terjadi reserved negatif, dua ledger penyaluran, atau penurunan paid menjadi unpaid karena notifikasi lama.

## Sandbox Midtrans

1. Ikuti MIDTRANS.md dan pasang URL notification yang dapat dijangkau.
2. Jalankan checkout â†’ Snap â†’ metode pembayaran sandbox â†’ settlement.
3. Uji pending, cancel, expire, challenge, duplicate notification dan retry saat internet terputus.
4. Matikan endpoint callback sementara; jalankan maintain/sync untuk menguji pemulihan status.
5. Pastikan token Snap tidak terlihat dalam daftar order atau akun lain, dan Server Key tidak pernah muncul di response.
6. Uji refund melalui dashboard merchant dan pencatatannya di admin. Pastikan payout petani mengikuti prosedur operasional terpisah.

## Pemeriksaan manual alur dan operasional

- Farmer daftar â†’ alamat â†’ pengajuan + bukti â†’ admin review â†’ draf + foto + slot â†’ publish.
- Buyer filter â†’ checkout â†’ payment â†’ farmer ready â†’ pickup â†’ receive â†’ laporan bertambah tepat sekali.
- Mismatch berat masuk issue; admin mencatat persetujuan, berat akhir dan refund yang diperlukan.
- Pengguna lain tidak dapat membaca order, alamat, bukti verifikasi, complaint/refund evidence atau mengganti data milik akun lain.
- CSV dengan nama komoditas berawalan `=`, `+`, `-`, `@` serta whitespace tidak dieksekusi sebagai formula.
- Uji Next.js pada lebar 360â€“1440, keyboard, kontras dan text scale. Peta gagal tetap menyisakan daftar.
- Uji 100 pengguna dan 10.000 penawaran, ukur p95. Indeks awal bukan bukti target performa.
- Uji backup database + storage dan restore ke lingkungan terpisah; catat RPO/RTO aktual.


## Regresi setelah revisi SRS

SrsComplianceTest mencakup koreksi stok di bawah reservasi, withheld sampai deklarasi petani, larangan admin menyelesaikan tanpa receipt buyer, penyelesaian shortage tanpa stok negatif, koreksi ledger berulang/stale/key berbeda, otorisasi koreksi, filter/thread pengaduan, bukti approval/refund, email verification/change/reset sekali pakai/expiry, draf parsial, filter katalog, metode/unit/validitas faktor, share tertutup, pengingat deduplikasi, selection kebutuhan, preferensi dan privasi, scope CSV, duplicate/preview serta upgrade skema yang mempertahankan data legacy. Alur lengkap juga diuji dari pengajuan petani + upload PNG nyata, approval, publish, checkout, Midtrans HTTP fake, ready, receive hingga laporan.

Bukti hasil SQLite dan kompilasi tidak menggantikan uji MariaDB/MySQL aktual, concurrency, SMTP, TLS/proxy atau sandbox Midtrans. Pengguna tetap menjalankan php artisan test di lingkungan sendiri setelah upgrade dan mencatat output aktual.


## Hasil akhir revisi

- BusinessRulesTest: 3 metode lulus.
- SchemaCompatibilityTest: 1 metode lulus, kompilasi 23 tabel beserta upgrade tanpa koneksi database.
- TransactionTest: 19 metode lulus pada SQLite :memory:.
- SrsComplianceTest: 30 metode lulus pada SQLite :memory: (termasuk 28 pada suite penuh dan dua pemeriksaan akhir HTTPS/proxy serta berkas palsu).
- Total: 53 metode lulus, tidak ada kegagalan tersisa. Hasil ini diperoleh melalui PHPUnit bootstrap/runBare pada lingkungan lokal; bukan klaim eksekusi sandbox Midtrans atau MariaDB operasional.
- 68 file PHP source + artisan lulus lint. JSON/XML, target route, tautan dokumentasi dan skema referensi diperiksa statis.

SQLite pengujian didaftarkan fungsi trigonometrinya melalui PHP agar pencarian radius dapat diuji pada build Windows yang tidak menyertakan fungsi matematika SQLite. Validasi konkurensi dan perilaku SQL MySQL tetap wajib pada staging.
