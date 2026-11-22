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

Ikuti [panduan instalasi](docs/INSTALLATION.md). Semua perintah instalasi, konfigurasi, migration dan pengujian dijalankan sendiri oleh pemilik repository. Source ditulis tanpa menjalankan Composer, migration, SQL atau commit.

Dokumentasi tambahan:

- [Kontrak API dan contoh payload](docs/API.md)
- [Integrasi frontend Next.js](docs/NEXTJS.md)
- [Pembayaran Midtrans dan penanganan kegagalan](docs/MIDTRANS.md)
- [Desain database dan aturan transaksi](docs/DATABASE.md)
- [Pemetaan SRS dan keputusan implementasi](docs/REQUIREMENTS.md)
- [Verifikasi dan skenario pengujian](docs/TESTING.md)
- [Skema SQL referensi](database/sql/schema.mysql.sql)
- [Pemulihan konflik migration](docs/TROUBLESHOOTING.md)

## Status verifikasi

Pemeriksaan sintaks **58 file PHP + artisan** dan **9 pemeriksaan aturan murni** lulus. Pemeriksaan statis target controller pada route, tabel/kolom SQL terhadap migration, tautan dokumentasi, composer.json dan phpunit.xml juga lulus. Pengujian Laravel, koneksi database, konkurensi InnoDB, pengiriman email dan transaksi sandbox Midtrans memerlukan instalasi/konfigurasi milik pengguna; belum dijalankan. Target performa, keamanan menyeluruh, ketersediaan dan backup pada SRS belum diukur.
