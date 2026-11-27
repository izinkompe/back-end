# Kontrak integrasi Next.js

Dokumen ini menjelaskan frontend yang perlu dibangun terpisah. Tidak ada source frontend atau instalasi Node di repository backend.

## Transport dan autentikasi

- Base API: `http://localhost:8000/api/v1` saat pengembangan.
- Header JSON: Accept + Content-Type `application/json`.
- Login/register mengirim cookie HttpOnly `pangantani_token` (umur tujuh hari) lewat header `Set-Cookie`. Panggil API dengan `credentials: 'include'` (fetch) atau `withCredentials: true` (axios); browser menyimpan dan mengirim cookie otomatis. Jangan menyimpan token di localStorage.
- Setelah login atau registrasi, periksa `user.email_verified_at`. Jika kosong, arahkan pengguna ke verifikasi email. Endpoint fitur akun mengembalikan 403 sampai email diverifikasi; profil, logout, dan endpoint verifikasi tetap tersedia.
- Request yang mengubah data (POST/PUT/PATCH/DELETE) dengan cookie wajib berasal dari origin yang terdaftar di `CORS_ALLOWED_ORIGINS`; origin lain ditolak 403 (perlindungan CSRF). Browser mengirim header Origin secara otomatis.
- Logout (`POST /auth/logout`) mencabut token di server dan menghapus cookie di browser.
- Body JSON login/register tetap memuat `token` untuk klien non-browser (Postman, BFF server, mobile) yang memakai header `Authorization: Bearer`. Header Bearer selalu diutamakan di atas cookie.
- Frontend dan API harus satu situs (misal `localhost:3000` ↔ `localhost:8000`, atau `app.domain.id` ↔ `api.domain.id`) agar cookie `SameSite=Lax` terkirim. Jika beda domain, atur `AUTH_COOKIE_SAME_SITE=none` (otomatis Secure, wajib HTTPS). Jangan menaruh Server Key Midtrans di variabel `NEXT_PUBLIC_*`.
- Backend ini memakai Sanctum personal access token; tidak memakai cookie sesi SPA `/sanctum/csrf-cookie`.
- CORS mengizinkan credentials hanya untuk origin pada `CORS_ALLOWED_ORIGINS` (tanpa slash akhir, dipisah koma).

## Halaman dan endpoint

| Halaman Next.js | Sumber backend |
|---|---|
| Login/register/reset | /auth/* |
| Profil dan alamat | /me, /addresses |
| Katalog dan peta | /offers dengan query yang sama untuk daftar/marker |
| Detail/bagikan penawaran | /offers/{id}; gunakan status terakhir dan similar |
| Checkout | POST /orders |
| Pembayaran/riwayat/detail | /orders, /orders/{id}, /orders/{id}/payment |
| Dashboard petani | /farmer/offers, /orders, /farmer/reports |
| Verifikasi petani | /farmer/verification, /media |
| Admin | /admin/verifications, /admin/complaints, /admin/reports dan endpoint keputusan |
| Notifikasi/favorit/kebutuhan | /notifications, /favorites, /needs |

## Checkout dan Snap

1. Ambil detail penawaran terbaru; tampilkan `current_price`, `available_kg`, tenggat dan slot aktif.
2. Buat UUID untuk header Idempotency-Key; simpan sampai request checkout diketahui hasilnya. Retry request yang sama menggunakan key/payload yang sama.
3. POST /orders dengan expected_price_per_kg. Pada 409 harga berubah, minta pembeli menyetujui harga terbaru lalu buat percobaan baru dengan key baru.
4. Setelah memperoleh ID order, panggil POST /orders/{id}/payment.
5. Cara paling sederhana adalah arahkan browser ke redirect_url dari response. Alternatif popup: muat Snap script `https://app.sandbox.midtrans.com/snap/snap.js` dengan data-client-key yang sesuai. Produksi memakai `https://app.midtrans.com/snap/snap.js`. Panggil Snap menggunakan token response.
6. Setelah callback, ambil ulang order; jika perlu panggil sync-payment. Tampilkan status pending sampai backend mengonfirmasi paid.
7. Pada hasil 502, tampilkan bahwa pembayaran sedang diperiksa. Jangan membuat order/token gateway baru otomatis.

## Umpan balik dan waktu

- Gunakan `cache: no-store` untuk permintaan stok/harga/order dan polling paling lama setiap 15Ã¢â‚¬â€œ30 detik saat halaman aktif. Hentikan polling ketika halaman tidak terlihat atau status final.
- Saat jaringan terputus, pulihkan order menggunakan retry checkout dengan key yang sama atau GET /orders; jangan langsung menampilkan kegagalan yang mendorong checkout baru.
- Format uang dengan IDR tanpa pecahan. Ubah gram ke kg dengan tiga desimal. Gunakan Asia/Jakarta saat menampilkan timestamp.
- Field validation 422 ditampilkan pada field yang sesuai. Status harus mempunyai teks, bukan hanya warna.
- Nilai high_contrast dan text_scale dari preferences perlu diterapkan frontend. Pengunjung yang belum login dapat menyimpan preferensi di browser.
- Endpoint upload menggunakan FormData; jangan menetapkan Content-Type manual agar boundary benar.
- Bukti privat harus diambil lewat BFF/header Bearer dan respons tidak boleh dicache publik. Foto katalog dapat memakai URL media publik.
- Jika layanan peta gagal, tetap tampilkan katalog. Jarak berlabel garis lurus; koordinat marker publik dibulatkan untuk menjaga kerahasiaan lokasi persis. Detail alamat tersedia bagi pihak order.

Frontend perlu menyediakan halaman reset-password untuk memasukkan email, kode OTP, password baru, dan konfirmasi password. Sharing tautan, clipboard, tampilan aksesibilitas dan geolokasi memerlukan implementasi browser/Next.js.

Untuk alur OTP, panggil `POST /auth/email/otp` dan `POST /auth/email/verify-otp` dengan token akun, atau `POST /auth/forgot-password/otp` dan `POST /auth/reset-password/otp` untuk reset. Kode enam digit berlaku sepuluh menit. Kontrak payload tersedia di `docs/API.md`.


## Penyesuaian setelah revisi SRS

- Implementasikan /verify-email untuk menerima enam digit OTP dan kirim kode ke `/auth/email/verify-otp` dengan token akun terkait. Pengguna dapat meminta kirim ulang OTP jika kode kedaluwarsa.
- PATCH /me mendukung email + current_password; verifikasi ulang email baru diperlukan. Preferensi yang disimpan dapat dibaca kembali melalui /me.
- Tampilkan available_g/available_kg, withheld_g dan shortage_g secara jelas pada dashboard petani. Koreksi kekurangan stok membuka masalah otomatis; confirm-resale adalah deklarasi petani setelah pemeriksaan fisik, bukan tombol admin.
- Form admin complete memerlukan reason, agreement, buyer_agreed=true dan konfirmasi penerimaan nyata dari pembeli. Untuk masalah yang belum selesai, pembeli tetap dapat mengirim received_kg pada order issue yang pembayaran sudah terkonfirmasi; keputusan akhir tetap oleh admin.
- Sediakan thread klarifikasi pengaduan dengan type/status, messages dan bukti privat.
- Form refund: requested/processing â†’ upload bukti â†’ completed dengan reference.
- Form koreksi laporan admin menggunakan expected_received_kg dan Idempotency-Key. Pada 409 muat ulang berat terbaru; jangan menimpa otomatis.
- Export CSV dapat memilih scope distribution atau orders; tampilan laporan tetap memakai berat diterima.

Petani perlu melihat moderated_at/moderation_reason dari daftar penawaran sendiri. Jika diblokir admin, tampilkan status peninjauan dan jangan menawarkan publish sampai release-moderation oleh admin; validasi server tetap wajib.
