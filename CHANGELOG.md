# Riwayat perubahan

## 0.3.0 — Autentikasi cookie HttpOnly untuk frontend Next.js, 27 November 2026

- Login/register mengirim cookie HttpOnly `pangantani_token` (7 hari); logout mencabut token dan menghapus cookie. Atribut cookie diatur di `App\Support\AuthCookie` dan `config/pangantani.php` (`AUTH_COOKIE_*`).
- Middleware `AuthenticateFromCookie` meneruskan cookie sebagai Bearer ke `auth:sanctum`; header Bearer eksplisit tetap diutamakan. Request pengubah data via cookie wajib berasal dari origin `CORS_ALLOWED_ORIGINS` (perlindungan CSRF).
- CORS `supports_credentials=true`. Body JSON login tetap memuat token untuk klien non-browser.
- Test baru `CookieAuthTest`. Tidak ada perubahan skema database.

## 0.2.1 — Perbaikan atomisitas reset password, jadwal pesanan aktif, dan suite konkurensi MySQL, 26 November 2026

- Reset password di `AuthController::reset` dieksekusi secara atomik dalam `DB::transaction` dengan penguncian baris (`lockForUpdate`) pada `password_reset_tokens` dan `users`, pencabutan sesi Sanctum, serta penghapusan token sekali pakai.
- Pengajuan dan keputusan perubahan jadwal pengambilan (`propose-pickup` dan `pickup-decision`) di `OrderController` mendukung seluruh pesanan aktif (`awaiting_payment`, `processing`, `ready`), mempertahankan jadwal lama sampai disetujui pembeli, menjaga aturan tenggat pembayaran (`payment_due_at`), serta menolak pesanan selesai/dibatalkan.
- Menambahkan pengujian unit/fitur di `SrsComplianceTest` serta suite pengujian konkurensi MySQL/MariaDB di `tests/Concurrency` (`PasswordResetConcurrencyTest` dan `MysqlTransactionConcurrencyTest`).

## 0.2.0 — Revisi kesesuaian backend SRS, 22 November 2026

- Koreksi stok fisik di bawah reservasi otomatis menutup penawaran dan membuka penanganan order aktif.
- Pelepasan reservasi menahan stok sampai petani menyatakan kelayakan jual kembali.
- Konfirmasi penerimaan buyer wajib sebelum admin menyelesaikan transaksi bermasalah.
- Koreksi berat transaksi selesai memperbarui ledger yang sama dengan audit, agreement, optimistic check dan idempotency key.
- Verifikasi email, perubahan email dengan password dan reset ke kontak terverifikasi; token email tidak dicatat dalam log.
- Filter jenis pengaduan, thread klarifikasi dan bukti privat; approval petani/refund wajib bukti.
- Harga preview, draf parsial, penyalinan foto, commodity terpisah, empty state dan cek ulang selection kebutuhan.
- Faktor emisi memiliki unit/metode/masa berlaku; CSV memiliki scope distribution/orders.
- HTTPS produksi, trusted proxy terkonfigurasi, zona database UTC dan tambahan audit/notifikasi.
- Migration 000002 mempertahankan data terpasang; stok legacy yang pernah dilepas wajib ditinjau petani.
- SQL/API/Next.js/instalasi dan pemetaan 30 FR + 12 BR diperbarui. Pembayaran Midtrans dipertahankan sesuai instruksi pengguna.

Upgrade: backup database/storage, kemudian jalankan php artisan config:clear, php artisan migrate dan php artisan test sendiri. Jangan gunakan migrate:fresh untuk upgrade data terpasang.
