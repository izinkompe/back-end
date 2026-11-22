# Integrasi Midtrans Snap

Panduan resmi: [Snap transaction](https://docs.midtrans.com/reference/snap-transaction), [HTTP notification](https://docs.midtrans.com/docs/https-notification-webhooks), [Get Transaction Status](https://docs.midtrans.com/reference/get-transaction-status).

## Konfigurasi

1. Ambil Server Key dan Client Key sandbox dari akun Midtrans milikmu. Masukkan pada `.env`, jangan pada source/frontend.
2. Server Key hanya digunakan backend. Client Key boleh diberikan kepada frontend.
3. Atur Payment Notification URL di dashboard ke `https://domain-api-kamu/api/v1/payments/midtrans/notification`. Endpoint harus dapat dijangkau Midtrans melalui internet; localhost memerlukan tunnel untuk sandbox.
4. `FRONTEND_URL` menjadi tujuan finish callback `/orders/{id}`. Frontend harus menyediakan halaman itu.
5. Ketika berpindah ke produksi, ganti kedua key dan `MIDTRANS_IS_PRODUCTION=true`; jalankan config cache ulang dan uji dengan prosedur merchant yang sesuai.

## Alur

Checkout membuat order + reservasi + record payment. Pembeli meminta endpoint payment untuk mendapatkan token Snap atau redirect URL. Backend menyimpan intent `initiating` sebelum request ke Midtrans sehingga crash/timeout tidak membuat sistem menganggap gateway belum pernah dipanggil. Setiap order hanya mempunyai satu gateway order ID.

Notifikasi diverifikasi dengan SHA-512 dari gabungan `order_id`, `status_code`, `gross_amount`, Server Key. Selanjutnya backend mengambil status terkini melalui Get Status API dengan Basic Auth Server Key, mencocokkan order ID dan nominal. Webhook yang duplikat/terlambat tidak memercayai status kiriman lama.

- `settlement` → paid.
- `capture` hanya menjadi paid jika `fraud_status=accept`.
- `pending` atau challenge tidak menjadi paid.
- `deny`, `cancel`, `expire` terkonfirmasi → batal dan pelepasan stok tepat sekali jika belum paid.
- `refund`, `partial_refund`, chargeback → dicatat; transaksi belum selesai masuk penanganan admin.

Frontend `onSuccess`, URL finish, dan screenshot pembeli tidak dapat menetapkan paid. Tidak ada verifikasi transfer manual pada implementasi ini.

## Tenggat dan hasil belum pasti

Tenggat adalah nilai terawal dari 30 menit (dapat diatur), batas penawaran, dan awal pickup. Saat membuat Snap baru, sisa waktu harus minimal satu menit. Expiry Snap dalam menit dibulatkan ke bawah agar tidak melampaui tenggat lokal.

Pesanan tanpa inisiasi payment dapat kedaluwarsa lokal dan melepas stok. Jika Snap sudah/kemungkinan dibuat, scheduler mencoba expire gateway lalu memeriksa status. **404 tidak dianggap bukti transaksi terminal**: Snap token dapat sudah ada walau pembeli belum memilih metode bayar. Koneksi gagal/hasil ambigu mempertahankan stok.

Jika payment `uncertain`/`initiating` tidak pulih:

1. Cek order number di dashboard Midtrans. Jangan membuat transaksi lain dengan ID baru untuk order yang sama.
2. Coba endpoint sync-payment. Jika sudah dibayar, sistem akan mencatat paid; jika order sedang issue, admin perlu menyelesaikannya.
3. Jika perlu batal, pembeli ajukan cancel sehingga order masuk issue; admin mencoba cancel-payment dan sync.
4. Jika gateway masih 404, sistem sengaja tidak menyediakan tombol melepas stok berdasarkan 404 saja. Pastikan status/expiry token bersama merchant support. Pemulihan operator harus didasarkan bukti dan prosedur yang disepakati; jangan mengubah database sembarangan. Kasus ini memerlukan penanganan operasional, bukan retry checkout otomatis.

## Refund dan dana petani

Refund dilakukan melalui fasilitas Midtrans Dashboard/kanal operasional merchant sesuai metode yang tersedia. API admin mencatat nominal, alasan, status, reference dan bukti; tidak memanggil API refund otomatis. Endpoint cancel-payment juga bukan refund untuk transaksi paid.

Dana masuk ke merchant Midtrans yang terdaftar. Backend tidak membagi dana atau mentransfer payout ke rekening petani. Model komisi, payout, biaya Midtrans dan rekonsiliasi merchant perlu ditetapkan pemilik produk sebelum penggunaan nyata. Total checkout saat ini hanya harga hasil panen, tanpa ongkir/komisi tambahan.
