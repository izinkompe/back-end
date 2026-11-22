# Pemulihan konflik migration

## Invalid default value for ends_at

Definisi awal memakai beberapa kolom TIMESTAMP wajib dalam satu tabel. Pada MariaDB dengan perilaku default TIMESTAMP lama dan strict mode, definisi tersebut dapat menghasilkan error 1067. Source sudah diperbaiki: starts_at, ends_at, pickup_starts_at, pickup_ends_at, payment_due_at dan needed_at menggunakan DATETIME wajib tanpa default otomatis. File SQL referensi diselaraskan. Waktu tetap dinormalisasi UTC oleh aplikasi. Kolom created_at/updated_at tetap TIMESTAMP nullable yang dikelola Laravel.

Referensi: [perilaku default TIMESTAMP MariaDB](https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp). Tidak perlu mematikan strict mode atau mengubah konfigurasi global MariaDB.

Migration yang gagal dapat meninggalkan tabel yang dibuat sebelum error. Karena baseline belum tercatat selesai, mengulang migrate saja akan kembali menemui users already exists. migrate:rollback juga tidak membersihkan baseline yang gagal dan belum tercatat.

**Khusus pangantani_dev yang baru dibuat untuk percobaan ini dan belum berisi data penting**, pastikan DB_DATABASE=pangantani_dev pada .env, lalu jalankan sendiri:

```powershell
php artisan config:clear
php artisan migrate:fresh --seed
```

Perintah migrate:fresh menghapus seluruh tabel pada database yang dikonfigurasi lalu membuatnya kembali. Jangan jalankan pada database lama/produksi atau database berisi data yang perlu dipertahankan. Jika data perlu dipertahankan, gunakan database kosong lain sesuai panduan berikut; adopsi tabel parsial memerlukan pemeriksaan skema terlebih dahulu.

Perubahan source tidak otomatis mengubah tabel pada database yang sudah ada. Basis data yang telah berhasil dipasang dengan baseline lama memerlukan migration ALTER terpisah jika ingin mengubah tipe kolom yang sudah terbentuk; jangan menjalankan fresh pada data operasional.

## Table 'users' already exists

Error ini berarti database tujuan sudah mempunyai tabel `users`, sedangkan Laravel masih menganggap migration `2026_11_22_000001_create_pangantani_tables` belum selesai. Penyebabnya dapat berupa tabel dari proyek sebelumnya, import SQL, atau percobaan migration sebelumnya yang gagal setelah sebagian tabel terbentuk. Pesan error saja belum membuktikan penyebab mana yang terjadi.

Migration awal membutuhkan database kosong. Jangan menggantinya dengan pengecekan `hasTable` yang melewati tabel tanpa memeriksa struktur, dan jangan menandai migration selesai secara manual sebelum seluruh skema diverifikasi.

### Pilihan untuk instalasi pengembangan: database baru

Langkah ini mempertahankan database `pangantani` beserta isinya. Buat database lain yang belum dipakai melalui tab SQL phpMyAdmin atau MySQL client:

```sql
CREATE DATABASE pangantani_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Jika nama itu sudah dipakai, pilih nama baru lain. Jangan import file SQL ke database baru jika akan menggunakan migration.

Edit baris berikut pada `.env` backend (buat dari `.env.example` hanya jika belum ada):

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_DATABASE=pangantani_dev
```

Pertahankan DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD dan APP_KEY yang sesuai. Lalu jalankan di PowerShell pada folder backend:

```powershell
php artisan config:clear
php artisan migrate
php artisan db:seed
```

`config:clear` memastikan Laravel membaca konfigurasi yang baru. Periksa hasil migration; seluruh tabel harus berhasil dibuat pada database baru. Tidak perlu menghapus tabel dari database lama.

### Jika harus memakai database lama

Jangan menjalankan `migrate:fresh` pada database berisi data yang perlu dipertahankan; perintah tersebut menghapus seluruh tabel. Buat backup terlebih dahulu, lalu periksa metadata berikut pada database `pangantani`:

```sql
SHOW TABLES;
SELECT migration, batch FROM migrations ORDER BY id;
SHOW CREATE TABLE users;
```

Jika tabel migrations belum ada, query SELECT tersebut akan gagal; cukup catat hasil SHOW TABLES. Struktur seluruh tabel terkait perlu dibandingkan dengan migration sebelum membuat langkah adopsi/perubahan skema. Tabel users dari Laravel standar, misalnya, belum memiliki role dan preferences yang dibutuhkan PanganTani.

Jika sebelumnya mengimpor `database/sql/schema.mysql.sql` secara lengkap, file itu sudah menyertakan tabel migrations dan catatan baseline. Error ini dapat berarti import tidak lengkap, database tujuan berbeda, atau catatan baseline belum ada. Jangan menambahkan catatan baseline hanya karena tabel users ditemukan: semua 21 tabel beserta struktur dan relasinya harus sesuai terlebih dahulu.

## APPLICATION IN PRODUCTION pada komputer pengembangan

Pesan itu muncul ketika konfigurasi efektif APP_ENV bernilai production. Untuk pengembangan, isi APP_ENV=local di `.env`, lalu jalankan `php artisan config:clear`. Pastikan terminal berada di folder backend yang benar. Jika tetap production, periksa environment variable APP_ENV pada proses/server yang dapat mengesampingkan `.env`.

Pada server produksi sungguhan, pertahankan APP_ENV=production dan APP_DEBUG=false; jangan mengubahnya menjadi local untuk menghilangkan konfirmasi.
