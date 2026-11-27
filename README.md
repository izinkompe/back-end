# PanganTani API

Backend Laravel 12 (PHP 8.2+) untuk frontend Next.js, database MySQL 8+/MariaDB 10.6+, autentikasi Sanctum Bearer token dan pembayaran Midtrans Snap. Frontend tidak disertakan dalam repository ini.

Implementasi mengacu pada SRS PanganTani yang diberikan. **Permintaan pengguna tentang Midtrans menggantikan pembayaran transfer manual pada SRS.** Figma tidak berhasil diakses saat implementasi, sehingga kesesuaian dengan setiap layar Figma belum diverifikasi.

## Isi backend

- Registrasi pembeli/petani, login, logout, reset kata sandi, profil, alamat, preferensi aksesibilitas.
- Pengajuan verifikasi petani dengan bukti privat dan keputusan administrator.
- Katalog, filter harga/kategori/stok/jadwal/radius, detail, foto, draf, penerbitan, penutupan, penyalinan penawaran dan harga bertahap.
- Checkout satu penawaran, reservasi atomik, penguncian baris InnoDB, idempotency key dan snapshot harga/alamat/jadwal.
- Midtrans Snap, verifikasi signature webhook dan rekonsiliasi Get Status API.
- Penyiapan pesanan, persetujuan perubahan jadwal, penerimaan, selisih berat, pembatalan, pengaduan, pencatatan refund dan audit.
- Favorit, pengingat, papan kebutuhan pangan, laporan distribusi, faktor emisi berversi dan CSV.
- Migration, referensi SQL, seeder kategori/admin serta pengujian unit dan fitur.

## Mulai

Ikuti [panduan instalasi](docs/INSTALLATION.md). Instalasi, konfigurasi server dan migration database pengguna dijalankan sendiri oleh pemilik repository. Revisi ini diuji pada SQLite sementara di memori dan HTTP Midtrans tiruan; tidak menjalankan Composer, migration pada MySQL pengguna, transaksi eksternal atau commit.

Dokumentasi tambahan:

- [Kontrak API dan contoh payload](docs/API.md)
- [Integrasi frontend Next.js](docs/NEXTJS.md)
- [Pembayaran Midtrans dan penanganan kegagalan](docs/MIDTRANS.md)
- [Desain database dan aturan transaksi](docs/DATABASE.md)
- [Pemetaan SRS dan keputusan implementasi](docs/REQUIREMENTS.md)
- [Verifikasi dan skenario pengujian](docs/TESTING.md)
- [Skema SQL referensi](database/sql/schema.mysql.sql)
- [Pemulihan konflik migration](docs/TROUBLESHOOTING.md)
- [Operasional, backup dan pemulihan](docs/OPERATIONS.md)
- [Catatan revisi SRS](CHANGELOG.md)

## Revisi kesesuaian SRS

Revisi 0.2 mencakup penanganan shortage otomatis, pernyataan kelayakan resale oleh petani, konfirmasi receipt buyer sebelum penyelesaian admin, koreksi ledger transaksi selesai, verifikasi/perubahan email, klarifikasi pengaduan, bukti approval/refund, moderasi admin yang tidak dapat dibuka sepihak oleh petani, validitas faktor emisi dan scope CSV. Lihat pemetaan 30 FR dan BR-01–12 pada REQUIREMENTS.md. Pembayaran Midtrans tetap merupakan perubahan eksplisit dari alur transfer manual SRS.

Untuk database yang sudah terpasang: backup dahulu, lalu jalankan php artisan config:clear, php artisan migrate dan php artisan test. Migration 000002 menambah skema tanpa menghapus akun/transaksi. Jangan migrate:fresh untuk upgrade. Stok legacy dari reservasi yang dilepas perlu ditinjau kelayakannya oleh petani.

## Status verifikasi

**55 metode pengujian utama lulus** (4 unit + 51 feature test pada SQLite `:memory:`) ditambah **5 metode pengujian konkurensi nyata pada MySQL/MariaDB (`tests/Concurrency`)**: Metode dijalankan lewat PHPUnit bootstrap/harness lokal; tidak ada koneksi MySQL operasional atau pembayaran sungguhan. SQLite uji mendaftarkan fungsi trigonometri PHP karena sebagian build Windows tidak menyediakannya. Pemeriksaan sintaks 68 file PHP source + artisan, target controller pada route, JSON/XML dan tautan dokumentasi juga lulus.

Kompatibilitas SQL dikompilasi untuk MySQL/MariaDB tanpa menghubungi server. Row locking InnoDB, sandbox Midtrans, SMTP, TLS/proxy aktual, performa, availability, restore backup dan tampilan Next.js tetap perlu diverifikasi pada lingkungan tujuan. Source backend tidak menjadi bukti kelulusan persyaratan UI atau target operasional tersebut.
