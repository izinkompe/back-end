# Riwayat perubahan

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
