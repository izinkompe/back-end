# Database dan aturan transaksi

Migration merupakan sumber utama skema; `database/sql/schema.mysql.sql` menyediakan DDL setara untuk pemeriksaan/import alternatif. Database aplikasi menggunakan MySQL/MariaDB dengan InnoDB. SQLite in-memory hanya untuk pengujian fitur yang tidak membuktikan penguncian InnoDB.

## Relasi

| Tabel | Fungsi |
|---|---|
| users, personal_access_tokens, password_reset_tokens | Akun, token Sanctum dan reset sekali pakai |
| addresses | Alamat lengkap dan koordinat privat milik pengguna |
| farmer_verifications | Satu pengajuan terkini per petani, keputusan dan reviewer |
| categories, offers | Kategori dan kelompok hasil panen |
| price_steps, pickup_slots | Harga bertahap dan jadwal penawaran; jadwal lama dinonaktifkan, tidak dihapus |
| orders | Snapshot transaksi, idempotency key, berat, harga dan status stok |
| payments | Satu identitas transaksi Midtrans per pesanan |
| complaints, refunds | Pengaduan dan satu catatan refund agregat per pesanan |
| media | Metadata file privat, diakses melalui controller dengan pemeriksaan izin |
| notifications, favorites | Pusat notifikasi, deduplikasi pengingat dan favorit |
| food_needs, need_responses | Papan kebutuhan dan penawaran tanggapan |
| emission_factors, distributions | Versi faktor dan satu hasil penyaluran per pesanan selesai |
| audit_logs | Pelaku, waktu, nilai sebelum/sesudah dan alasan tindakan |

## Satuan

- Input `quantity_kg`, `stock_kg`, `received_kg`: string desimal disarankan, maksimal tiga desimal. Penyimpanan `*_g`: integer gram, 1 kg = 1.000 g.
- `price_per_kg`, `total_idr`, `amount_idr`: rupiah integer, tanpa pecahan rupiah.
- Total dihitung `(quantity_g × price_per_kg + 500) div 1000`, pembulatan setengah ke atas.
- Tanggal panen berupa tanggal kalender. Timestamp disimpan UTC. Input API boleh ISO 8601 dengan `Z` atau offset, lalu dinormalisasi UTC. Frontend menampilkan Asia/Jakarta.
- Estimasi karbon merupakan estimasi berbasis faktor yang dimasukkan admin, bukan klaim otomatis pangan terselamatkan.

## Persediaan

`stock_g` adalah berat fisik yang belum dialokasikan sebagai penjualan selesai, **termasuk** reservasi. `available_g = stock_g - reserved_g`. `sold_g` adalah akumulasi berat yang benar-benar diterima.

Checkout memegang lock akun pembeli (untuk serialisasi idempotency key), penawaran, lalu membuat order/payment dan menaikkan reservasi dalam satu transaksi. Perubahan pesanan berikutnya memegang lock penawaran lalu order. Transaksi database diulang maksimal tiga kali jika deadlock.

- Checkout: reserved bertambah, stock tetap.
- Batal sah: reserved berkurang tepat sekali, stock tetap. Jika produk tidak aman untuk ditawarkan, admin menutup penawaran.
- Selesai: reserved berkurang sebesar pesanan, stock berkurang sebesar pesanan, sold bertambah sebesar penerimaan.
- Selisih berat: order masuk `issue`; setelah keputusan admin, sisa yang tidak diterima tidak otomatis dijual ulang. Petani perlu mengoreksi stok setelah memeriksa kondisi.
- Koreksi stok di bawah reservasi ditolak dengan 409. Petani harus menandai masalah melalui penutupan `affects_orders=true`, kemudian admin menyelesaikan pesanan sebelum koreksi stok.

Snapshot produk, harga, alamat dan jadwal order tidak berubah saat penawaran atau alamat diedit. Perubahan jadwal order hanya berlaku setelah persetujuan pembeli.

## Konsistensi pembayaran dan laporan

Intent inisiasi Midtrans disimpan sebelum HTTP request. Timeout/crash tidak membuat pembayaran tampak belum pernah dibuat. Keadaan `initiating`/`uncertain` tidak melepaskan stok secara otomatis. Hanya status gateway terminal atau pesanan yang belum pernah diinisiasi dapat melepaskan reservasi.

`distributions.order_id` unik dan dibuat bersama perubahan stok/penyelesaian pesanan. Pemanggilan konfirmasi yang sama tidak menggandakan berat. Faktor emisi disalin ke snapshot saat selesai sehingga versi baru tidak mengubah laporan historis. Tanpa faktor, nilai estimasi `null`, bukan nol.

Tidak tersedia penghapusan transaksi atau koreksi diam-diam atas transaksi selesai. Pengaduan setelah selesai ditangani sebagai catatan terpisah; koreksi berat final setelah selesai memerlukan workflow koreksi ledger lanjutan dan belum disediakan. Refund tidak otomatis menghapus berat yang memang sudah diterima.
