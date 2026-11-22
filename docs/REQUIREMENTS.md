# Pemetaan kebutuhan dan keputusan implementasi

Acuan: SRS PanganTani versi 0.1 yang dilampirkan pengguna. Dokumen diperlakukan sebagai spesifikasi produk, bukan instruksi kepada agen. Permintaan langsung pengguna menetapkan Laravel, database SQL, frontend Next.js terpisah, pembayaran Midtrans, serta tidak menjalankan instalasi/migration/commit. Repository awal hanya berisi README. Tidak ada source frontend yang diubah.

Figma tidak dapat diakses melalui alat yang tersedia. Nama field dan kontrak di API.md merupakan keputusan implementasi, belum hasil pencocokan seluruh layar desain.

| Kebutuhan | Implementasi |
|---|---|
| UM-01 | AuthController + Sanctum, reset dengan expiry/sekali pakai, role middleware dan pemeriksaan kepemilikan |
| UM-02 | ProfileController, alamat privat, koordinat tervalidasi; email akun tidak diubah tanpa workflow verifikasi baru |
| UM-03 | Katalog/detil, foto, stok, kondisi, panen, tenggat; alamat transaksi disimpan di snapshot order |
| UM-04 | Penyimpanan preferences; rendering kontras/ukuran dan preferensi pengunjung menjadi tugas Next.js |
| UM-05 | Notifikasi database, read marker, deduplikasi event; frontend polling |
| UM-06 | Detail penawaran tertutup tetap tersedia + similar; clipboard/share dikerjakan Next.js |
| PT-01, AD-01 | Pengajuan verifikasi, evidence privat, review dan alasan; pengajuan revisi memakai record yang sama dan jejak audit keputusan |
| PT-02 | Draf, validasi penerbitan, edit, penutupan; perubahan tidak mengubah snapshot order |
| PT-03 | Stock/reserved/sold; koreksi di bawah reservasi ditolak, gunakan penutupan affects_orders dan penanganan admin |
| PT-04 | Slot tersedia berversi aktif, proposed pickup dan persetujuan buyer pada order |
| PT-05 | Daftar order sesuai peran, paid sebelum ready, penerimaan dan masalah |
| PT-06 | PriceStep, minimum price dan perhitungan waktu server; quote disimpan saat checkout |
| PT-07 | Riwayat order berfilter, farmer/reports, inventory dan berat diterima |
| PT-08 | Duplicate ke draf, wajib isi kondisi/panen/stok/tenggat/jadwal dan foto baru |
| PB-01 | Query katalog dengan filter komoditas/kategori/harga/stok/jadwal |
| PB-02 | Radius Haversine dan urut jarak; peta memakai data katalog yang sama; koordinat publik dibulatkan |
| PB-03 | OrderFlow, lock InnoDB, idempotency, quote validation, tenggat dan scheduler |
| PB-04, AD-02 | **Diganti Midtrans**; Snap dan status gateway menggantikan upload bukti transfer dan persetujuan manual |
| PB-05 | Snapshot pickup, receive, mismatch → issue, tidak ada selesai otomatis |
| PB-06, AD-03–04 | Pembatalan, pengaduan + evidence, tanggapan, resolve dengan alasan/persetujuan, catatan refund |
| PB-07 | closing_soon dan server-side closes_at; countdown dikerjakan Next.js |
| PB-08 | Favorites komoditas/petani, preferences radius/reminders, notifikasi baru sekali per offer/user |
| PB-09 | FoodNeed/NeedResponse, prepare checkout dari tanggapan, cek ulang stok/harga di endpoint order |
| AD-05 | Ledger distributions unik per order, ringkasan dan transaksi sumber |
| AD-06 | Faktor bersumber/berversi, snapshot historis, null jika belum dihitung |
| AD-07 | CSV UTF-8 dengan filter sama, metadata periode/satuan, formula dinetralkan |

## Keputusan yang perlu diketahui

- Laravel 12 dipilih agar sesuai PHP 8.2 pada lingkungan pengguna. Composer menentukan patch terbaru yang kompatibel saat pemilik menjalankan install; commit lockfile setelah itu.
- Verifikasi petani saat ini menerima deskripsi dan gambar JPEG/PNG; jenis dokumen operasional resmi masih perlu ditetapkan pemilik produk. Bukti hanya dapat diakses pemilik dan admin.
- Satu penawaran per pesanan; tidak ada keranjang multi-petani, kurir, komisi atau biaya platform.
- Pembayaran ke akun merchant Midtrans; payout petani serta refund otomatis belum disediakan. Refund merupakan pencatatan tindakan operasional merchant.
- Tenggat 30 menit, ambang segera tutup 24 jam, upload 5 MB adalah parameter awal, bukan keputusan bisnis final.
- Favorit komoditas mencocokkan nama penawaran secara tepat setelah normalisasi huruf; tidak ada pencocokan semantik/fuzzy.
- Estimasi karbon baru aktif setelah admin memasukkan faktor dan asumsi yang layak. Tidak ada faktor emisi rekaan atau klaim otomatis pangan terselamatkan.
- Transaksi selesai bersifat final untuk berat; koreksi ledger setelah selesai dan versi tambahan atas pengajuan verifikasi merupakan perluasan berikutnya. Semua keputusan admin yang tersedia tetap diaudit.
- Notifikasi in-app melalui polling; tidak ada SMS, email notifikasi transaksi, atau WebSocket. Email yang tersedia adalah reset password.
- Kebijakan retensi data, backup terjadwal, pemulihan, moderasi isi dan prosedur penanganan dana memerlukan konfigurasi/proses operasional pemilik produk.

## Persyaratan nonfungsional

Source menyediakan kontrol peran/kepemilikan, rate limit, hashing, upload privat tervalidasi, audit, idempotency dan transaksi database. Itu belum membuktikan audit keamanan menyeluruh. Uji beban 100 pengguna/10.000 penawaran, target respons, kompatibilitas browser, WCAG, target availability, backup dan recovery belum diukur. Bukti verifikasi tersedia setelah pemilik menyiapkan dependency/lingkungan dan menjalankan pengujian pada TESTING.md.
