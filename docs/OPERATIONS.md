# Operasional dan verifikasi NFR

Panduan ini dijalankan oleh operator pada lingkungan yang sesuai. Source tidak menjalankan backup, restore atau perubahan infrastruktur secara otomatis.

## Layanan

- Document root hanya public, TLS aktif, APP_ENV=production dan APP_DEBUG=false.
- Konfigurasikan TRUSTED_PROXIES hanya untuk IP/CIDR reverse proxy yang mengakhiri TLS. Middleware menolak HTTP API yang tidak aman di produksi.
- Scheduler Laravel dijalankan setiap menit oleh cron atau Windows Task Scheduler. Cegah instance ganda yang menggunakan database yang sama.
- Monitor /up untuk bootstrap aplikasi; tambahkan pemeriksaan MySQL, ruang disk, scheduler dan jalur callback Midtrans pada monitoring operasional. /up tidak memeriksa seluruh dependency.
- Polling frontend 15–30 detik untuk stok/harga/status; webhook payment harus dapat dijangkau internet melalui HTTPS.
- SMTP perlu diuji menggunakan akun pengujian untuk registrasi/verifikasi/reset. Jangan memakai transport email log yang mengekspos tautan rahasia; alias log pada source dipetakan ke transport in-memory.

## Backup harian (NFR-09)

1. Simpan backup di lokasi privat di luar document root dan repository, dengan pembatasan akses serta enkripsi sesuai penyedia storage. Lindungi kredensial backup dan konfigurasi APP_KEY secara terpisah.
2. Dump MySQL memakai mysqldump --single-transaction --quick agar snapshot tabel InnoDB konsisten. Gunakan --result-file untuk menghindari perubahan encoding oleh redirect PowerShell. Sertakan schema, data, trigger, routine/event yang digunakan dan metadata migration. Gunakan akun backup dengan izin minimum yang diperlukan.
3. Salin storage/app/private (gambar dan evidence) pada waktu yang konsisten dengan dump. Pertahankan path relatif karena tabel media merujuknya. Jadwalkan di jendela pemeliharaan atau gunakan snapshot DB/storage terkoordinasi agar upload baru tidak menghasilkan pasangan backup yang tidak konsisten.
4. Jadwalkan setiap hari lewat layanan backup/Task Scheduler/cron, simpan log hasil dan ukuran, serta kirim alert jika gagal. Untuk pekerjaan tanpa interaksi, gunakan file konfigurasi client MySQL yang izin aksesnya terbatas atau secret manager; jangan menaruh password pada argumen command line atau repository.
5. Tentukan retensi dan salinan di lokasi kedua sesuai kebijakan operator. Periksa file backup benar-benar ada dan dapat dibaca; status job sukses saja tidak membuktikan restore.

Contoh bentuk perintah dump (ganti path/user/database sesuai lingkungan; -p meminta password secara interaktif):

```powershell
& 'C:\xampp\mysql\bin\mysqldump.exe' -u NAMA_USER -p --single-transaction --quick --routines --triggers --events --result-file='D:\backup-privat\pangantani.sql' pangantani_dev
```

Direktori output harus sudah dibuat dan diproteksi. Perintah di atas bukan backup otomatis yang telah dipasang oleh source.

## Latihan pemulihan

- Gunakan host/database pemulihan terpisah dan versi MySQL/MariaDB yang kompatibel. Jangan menimpa produksi untuk latihan.
- Pulihkan dump SQL ke database pemulihan kosong, lalu pulihkan storage privat pada path yang sama dan konfigurasi aplikasi/APP_KEY yang tepat. Jangan menjalankan fresh di atas hasil restore.
- Matikan notifikasi keluar dan gunakan key Midtrans sandbox saat latihan agar tidak mengubah transaksi merchant produksi.
- Cek metadata migration, hitung akun/order/ledger, cocokkan total berat laporan, baca sampel evidence berizin dan pastikan akses akun lain ditolak.
- Ukur umur snapshot terbaru (target kehilangan data maksimal 24 jam) dan waktu dari deklarasi insiden hingga layanan pulih (target maksimal 4 jam). Catat bukti dan ulangi latihan sebelum peluncuran serta setelah perubahan skema/infra penting.

## Availability, performa dan keamanan

Availability 99,5% harus diukur selama satu bulan dengan catatan downtime dan pemeliharaan. Uji p95 maksimal dua detik dengan 100 pengguna aktif/10.000 offer pada server target. Jalankan uji konkurensi InnoDB dan audit keamanan staging sesuai TESTING.md. Angka target tersebut belum menjadi hasil pengukuran dari penulisan source.
