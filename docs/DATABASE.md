# Database dan aturan transaksi

Migration merupakan sumber utama skema; `database/sql/schema.mysql.sql` menyediakan DDL setara untuk pemeriksaan/import alternatif. Database aplikasi menggunakan MySQL/MariaDB dengan InnoDB. SQLite in-memory hanya untuk pengujian fitur yang tidak membuktikan penguncian InnoDB.

Migration `2026_11_27_000001` menambahkan `auth_otps` untuk menyimpan hash kode verifikasi dan reset password beserta masa berlaku serta jumlah percobaan.

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
| complaints, complaint_messages, refunds | Pengaduan dan satu catatan refund agregat per pesanan |
| media | Metadata file privat, diakses melalui controller dengan pemeriksaan izin |
| notifications, favorites | Pusat notifikasi, deduplikasi pengingat dan favorit |
| food_needs, need_responses | Papan kebutuhan dan penawaran tanggapan |
| emission_factors, distributions, distribution_corrections | Versi faktor dan satu hasil penyaluran per pesanan selesai |
| audit_logs | Pelaku, waktu, nilai sebelum/sesudah dan alasan tindakan |

## Satuan

- Input `quantity_kg`, `stock_kg`, `received_kg`: string desimal disarankan, maksimal tiga desimal. Penyimpanan `*_g`: integer gram, 1 kg = 1.000 g.
- `price_per_kg`, `total_idr`, `amount_idr`: rupiah integer, tanpa pecahan rupiah.
- Total dihitung `(quantity_g Ãƒâ€” price_per_kg + 500) div 1000`, pembulatan setengah ke atas.
- Tanggal panen berupa tanggal kalender. Timestamp disimpan UTC. Input API boleh ISO 8601 dengan `Z` atau offset, lalu dinormalisasi UTC. Frontend menampilkan Asia/Jakarta.
- Estimasi karbon merupakan estimasi berbasis faktor yang dimasukkan admin, bukan klaim otomatis pangan terselamatkan.

## Persediaan

`stock_g` adalah berat fisik yang belum dialokasikan sebagai penjualan selesai, **termasuk** reservasi. `available_g = max(0, stock_g - reserved_g - withheld_g)`. withheld_g menahan stok yang reservasinya dilepas sampai petani mengonfirmasi kelayakan jual. Jika stok fisik di bawah reservasi, shortage_g menyatakan selisihnya dan order terkait masuk penanganan. `sold_g` adalah akumulasi berat yang benar-benar diterima.

Checkout memegang lock akun pembeli (untuk serialisasi idempotency key), penawaran, lalu membuat order/payment dan menaikkan reservasi dalam satu transaksi. Perubahan pesanan berikutnya memegang lock penawaran lalu order. Transaksi database diulang maksimal tiga kali jika deadlock.

- Checkout: reserved bertambah, stock tetap.
- Batal sah: reserved berkurang tepat sekali, stock tetap. Berat yang dilepas ditahan dalam withheld_g (dibatasi stok fisik). Petani wajib mengonfirmasi kelayakan sebelum dijual ulang. Admin dapat menutup penawaran tetapi tidak menggantikan deklarasi petani.
- Selesai: reserved berkurang sebesar pesanan, stock berkurang sebesar minimum berat pesanan/stok fisik, sold bertambah sebesar penerimaan. Berat penerimaan tidak boleh melebihi stok fisik. Konfirmasi penerimaan pembeli wajib ada.
- Selisih berat: order masuk `issue`; setelah keputusan admin, sisa yang tidak diterima tidak otomatis dijual ulang. Petani perlu mengoreksi stok setelah memeriksa kondisi.
- Koreksi stok di bawah reservasi diterima: stok fisik dicatat, penawaran ditutup dan semua order dengan reservasi aktif masuk issue secara atomik. Petani dan pembeli diberi notifikasi. Tidak ada stock negatif atau penjualan baru atas stok yang kurang.

Snapshot produk, harga, alamat dan jadwal order tidak berubah saat penawaran atau alamat diedit. Perubahan jadwal order hanya berlaku setelah persetujuan pembeli.

## Konsistensi pembayaran dan laporan

Intent inisiasi Midtrans disimpan sebelum HTTP request. Timeout/crash tidak membuat pembayaran tampak belum pernah dibuat. Keadaan `initiating`/`uncertain` tidak melepaskan stok secara otomatis. Hanya status gateway terminal atau pesanan yang belum pernah diinisiasi dapat melepaskan reservasi.

`distributions.order_id` unik dan dibuat bersama perubahan stok/penyelesaian pesanan. Pemanggilan konfirmasi yang sama tidak menggandakan berat. Faktor emisi disalin ke snapshot saat selesai sehingga versi baru tidak mengubah laporan historis. Tanpa faktor, nilai estimasi `null`, bukan nol.

Koreksi berat transaksi selesai tersedia bagi admin melalui correct-distribution. Koreksi memerlukan berat sebelumnya, alasan, kesepakatan dan Idempotency-Key; menyimpan distribution_corrections serta memperbarui baris ledger yang sama, order dan sold_g dalam transaksi atomik. Stok jual tidak ditambah otomatis. Estimasi memakai snapshot faktor historis, termasuk tetap null jika tidak ada faktor. Refund tidak otomatis menghapus berat yang memang sudah diterima.


## Upgrade database terpasang

Migration 000002 menambah withheld_g/commodity, buyer_confirmed_at/resolution_agreement, jenis pengaduan, informasi hasil gateway, metode/masa berlaku faktor, distribution_corrections dan complaint_messages. Jalankan php artisan migrate setelah backup; jangan migrate:fresh untuk memperbarui database berisi data.

Jika ada order legacy yang sudah released, migration menahan seluruh stok fisik penawaran terkait secara konservatif sampai petani meninjau dan mengonfirmasi kelayakan. Data akun, order dan ledger lama tidak dihapus. SQL referensi memuat dua baseline migration untuk import alternatif ke database kosong.

Moderasi admin menyimpan moderated_at dan moderation_reason pada offers. Petani tidak dapat menghapus blokir lewat save/inventory/publish; hanya endpoint admin release-moderation yang dapat mencabutnya. Alasan disembunyikan dari serialisasi publik.
