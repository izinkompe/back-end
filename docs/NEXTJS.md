# Kontrak integrasi Next.js

Dokumen ini menjelaskan frontend yang perlu dibangun terpisah. Tidak ada source frontend atau instalasi Node di repository backend.

## Transport dan autentikasi

- Base API: `http://localhost:8000/api/v1` saat pengembangan.
- Header JSON: Accept + Content-Type `application/json`.
- Login/register mengembalikan Bearer token berumur tujuh hari. Sertakan token pada endpoint terlindungi.
- Untuk Next.js, simpan token di sisi server/BFF melalui cookie HttpOnly, Secure di produksi, dan SameSite yang sesuai. Route handler Next.js meneruskan token sebagai header Authorization ke Laravel. Tambahkan perlindungan CSRF/origin pada route BFF yang mengubah data. Jangan menaruh Server Key Midtrans di variabel `NEXT_PUBLIC_*`.
- Backend ini memakai Sanctum personal access token; tidak memakai cookie sesi SPA `/sanctum/csrf-cookie`.
- CORS perlu mencantumkan origin frontend saat request browser langsung. Integrasi BFF dapat memanggil backend dari server.

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

Frontend perlu menyediakan halaman reset-password yang membaca query email/token lalu mengirim password+confirmation ke API. Sharing tautan, clipboard, tampilan aksesibilitas dan geolokasi memerlukan implementasi browser/Next.js.


## Penyesuaian setelah revisi SRS

- Implementasikan /verify-email. Ambil verification_url dari query email, lalu panggil URL signed dengan Bearer token akun terkait melalui BFF. URL harus berasal dari host backend tepercaya yang dikonfigurasi; jangan menjadikan BFF proxy URL bebas. Saat signed URL tidak valid/kedaluwarsa, pengguna dapat meminta kirim ulang verifikasi.
- PATCH /me mendukung email + current_password; verifikasi ulang email baru diperlukan. Preferensi yang disimpan dapat dibaca kembali melalui /me.
- Tampilkan available_g/available_kg, withheld_g dan shortage_g secara jelas pada dashboard petani. Koreksi kekurangan stok membuka masalah otomatis; confirm-resale adalah deklarasi petani setelah pemeriksaan fisik, bukan tombol admin.
- Form admin complete memerlukan reason, agreement, buyer_agreed=true dan konfirmasi penerimaan nyata dari pembeli. Untuk masalah yang belum selesai, pembeli tetap dapat mengirim received_kg pada order issue yang pembayaran sudah terkonfirmasi; keputusan akhir tetap oleh admin.
- Sediakan thread klarifikasi pengaduan dengan type/status, messages dan bukti privat.
- Form refund: requested/processing â†’ upload bukti â†’ completed dengan reference.
- Form koreksi laporan admin menggunakan expected_received_kg dan Idempotency-Key. Pada 409 muat ulang berat terbaru; jangan menimpa otomatis.
- Export CSV dapat memilih scope distribution atau orders; tampilan laporan tetap memakai berat diterima.

Petani perlu melihat moderated_at/moderation_reason dari daftar penawaran sendiri. Jika diblokir admin, tampilkan status peninjauan dan jangan menawarkan publish sampai release-moderation oleh admin; validasi server tetap wajib.
