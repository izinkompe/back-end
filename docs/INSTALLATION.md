# Instalasi oleh pemilik repository

## Kebutuhan

- PHP 8.2+ dengan ekstensi Laravel: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer dan XML. Aktifkan `pdo_mysql` untuk aplikasi dan `pdo_sqlite` untuk pengujian default.
- Composer 2, MySQL 8+ atau MariaDB 10.6+ dengan InnoDB.
- Akun Midtrans sandbox, lalu akun produksi saat siap.
- Node.js hanya diperlukan di repository frontend Next.js, bukan backend ini.

Source menggunakan Laravel 12 agar sesuai PHP 8.2. Dokumentasi resmi: [Laravel 12](https://laravel.com/docs/12.x), [Sanctum](https://laravel.com/docs/12.x/sanctum).

## Langkah PowerShell

Jalankan di terminal VS Code pada repository backend:

```powershell
Set-Location D:\izinkompe\back-end
composer install
if (!(Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
```

Tidak perlu `composer create-project` karena struktur aplikasi sudah tersedia. Setelah instalasi pertama, simpan `composer.lock` ke Git bersama source untuk mengunci versi dependency. Jangan commit `.env` atau `vendor`.

Buat database kosong menggunakan administrator MySQL/phpMyAdmin:

```sql
CREATE DATABASE pangantani CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Edit `.env`:

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pangantani
DB_USERNAME=root
DB_PASSWORD=
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000
MIDTRANS_SERVER_KEY=isi_server_key_sandbox
MIDTRANS_CLIENT_KEY=isi_client_key_sandbox
MIDTRANS_IS_PRODUCTION=false
ADMIN_EMAIL=admin@domain-kamu.id
ADMIN_PASSWORD=buat_kata_sandi_unik_minimal_12_karakter
```

Lalu jalankan sendiri:

```powershell
php artisan migrate
php artisan db:seed
php artisan route:list --path=api
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```

Seeder membuat enam kategori. Akun admin dibuat hanya jika email belum ada dan kedua variabel admin terisi. Seeder tidak mempromosikan akun yang sudah terdaftar. Setelah berhasil, kosongkan `ADMIN_PASSWORD` dalam konfigurasi server. Tidak ada akun admin bawaan atau password tetap di source.

Buka terminal kedua untuk scheduler lokal:

```powershell
Set-Location D:\izinkompe\back-end
php artisan schedule:work
```

Scheduler wajib berjalan agar tenggat, pemeriksaan status, dan pengingat diproses. Default queue adalah sinkron; tidak memerlukan queue worker. Notifikasi berada di database, belum push/WebSocket.

## Email reset

Default `MAIL_MAILER=log` menulis email ke log hanya untuk pengembangan. Untuk mengirim sungguhan, ubah `MAIL_MAILER=smtp` dan isi host, port, username, password dan alamat pengirim yang sudah diverifikasi oleh penyedia email. Tautan mengarah ke `FRONTEND_URL/reset-password`; Next.js perlu membuat halaman tersebut.

## Database SQL

Jalur utama yang disarankan adalah migration. `database/sql/schema.mysql.sql` disediakan sebagai referensi/alternatif import manual ke database kosong. Pilih satu jalur. SQL mencakup metadata migration baseline; jangan import SQL di atas tabel yang telah dibuat migration. Jalankan `db:seed` setelah salah satu jalur selesai. Tidak ada SQL atau migration yang dijalankan saat penulisan source.

Jika `migrate` gagal dengan `Table 'users' already exists`, ikuti [pemulihan konflik tabel](TROUBLESHOOTING.md). Jangan menambahkan `hasTable` untuk sekadar melewati tabel: tabel yang sudah ada belum tentu memiliki kolom, indeks dan foreign key yang sesuai.

## Produksi

1. Atur document root web server ke folder `public`, gunakan HTTPS dan PHP-FPM/Apache yang benar.
2. Gunakan user database terbatas, isi konfigurasi produksi dan `APP_DEBUG=false`.
3. Atur `MIDTRANS_IS_PRODUCTION=true` dan kedua key produksi. Jangan mencampur key sandbox dan produksi.
4. Batasi CORS ke origin frontend yang tepat tanpa slash akhir. `.env` mendukung beberapa origin dipisah koma.
5. Pastikan `storage` dan `bootstrap/cache` dapat ditulis proses PHP. Folder upload tetap privat; tidak perlu `storage:link`.
6. Jalankan sendiri `php artisan config:cache`, `php artisan route:cache` dan `php artisan migrate --force` setelah backup dan pengujian staging.
7. Jalankan `php artisan schedule:run` setiap menit menggunakan cron atau Task Scheduler; cegah instance scheduler ganda. Jangan jalankan scheduler lokal dan produksi terhadap database yang sama.
8. Aktifkan SMTP, monitoring `/up`, log error, backup database dan storage privat harian. Uji pemulihan terpisah sebelum peluncuran.

Lakukan uji transaksi sandbox dan konkurensi MySQL sebelum deployment. Status `/up` hanya menunjukkan aplikasi dapat bootstrap, bukan audit kesehatan seluruh layanan eksternal.
