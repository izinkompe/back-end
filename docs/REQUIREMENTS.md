# Keterlacakan backend terhadap SRS PanganTani

Acuan: SRS PanganTani versi 0.1 yang dilampirkan pengguna. Dokumen menjadi spesifikasi produk, bukan instruksi eksekusi kepada agen. Audit revisi ini mencakup seluruh 30 persyaratan induk dan BR-01 sampai BR-12 untuk bagian backend. Frontend tetap repository terpisah.

## Perubahan persyaratan yang diminta pengguna

CHG-01: pengguna secara eksplisit memilih Midtrans. FR-PB-04, FR-AD-02, BR-04 dan BR-05 diadaptasi dari bukti transfer/persetujuan manual menjadi Snap, signature notification dan pemeriksaan penerimaan dana melalui Get Status API. Dana tidak dinyatakan paid berdasarkan callback browser. Upload bukti transfer manual tidak menjadi metode pembayaran kedua. Refund tetap dicatat beserta bukti sesuai FR-AD-04; pengiriman refund diurus melalui kanal merchant. Payout otomatis ke petani tidak termasuk SRS awal.

## Cakupan persyaratan fungsional

| ID | Perilaku backend | Implementasi/bukti otomatis |
|---|---|---|
| FR-UM-01 | Registrasi buyer/farmer, akses sesuai peran/kepemilikan, login/logout, reset sekali pakai dan berumur | AuthController, role middleware; TransactionTest dan uji reset/signature SrsComplianceTest |
| FR-UM-02 | Profil/kontak/organisasi, perubahan email dengan password dan verifikasi ulang, alamat/koordinat | ProfileController; uji perubahan email, preferensi dan akses alamat |
| FR-UM-03 | Katalog aktif, detail/foto/kondisi/panen/harga/stok/tenggat; alamat privat hanya pihak transaksi | OfferController, MediaController, snapshot order; uji katalog, privasi dan alur lengkap |
| FR-UM-04 | Simpan/pulihkan high_contrast dan text_scale (1â€“2) | /me; uji persistensi. Rendering dan preferensi pengunjung di Next.js |
| FR-UM-05 | Notifikasi status/tenggat, mark read, tautan menuju resource yang tetap berizin | Activity, ProfileController, scheduler; uji notifikasi dan kepemilikan |
| FR-UM-06 | URL share, detail tertutup tetap tersedia, penawaran sejenis aktif | OfferController/Offer; uji share setelah penutupan. Clipboard di Next.js |
| FR-PT-01 | Pengajuan/bukti, status/alasan, revisi dan kirim ulang | ProfileController, MediaController, AdminController; persetujuan tanpa bukti ditolak |
| FR-PT-02 | Draf tidak lengkap, penerbitan lengkap oleh petani verified, edit tanpa mengubah snapshot order, tutup penawaran | OfferController; uji draf/publish, snapshot dan alur lengkap |
| FR-PT-03 | Stock/reserved/sold, koreksi stok fisik termasuk kekurangan, masalah otomatis dan notifikasi, kondisi/penutupan | Inventory; uji shortage, nonnegatif dan kelayakan resale |
| FR-PT-04 | Slot pengambilan baru, perubahan slot hanya untuk order baru, usulan jadwal memerlukan persetujuan buyer | OfferController/OrderController; uji normalisasi waktu dan persetujuan |
| FR-PT-05 | Daftar order berstatus, berat/jadwal/payment, paid sebelum ready, hasil penerimaan/masalah | OrderController; TransactionTest dan alur lengkap |
| FR-PT-06 | Harga awal/minimum/tahap, preview, waktu server dan harga transaksi tetap | Pricing, price-preview; uji perubahan harga, pembulatan, snapshot dan preview |
| FR-PT-07 | Riwayat periode/status dan ringkasan berat final/inventory milik petani | /orders dan /farmer/reports; sumber ledger unik |
| FR-PT-08 | Salin informasi dasar/foto menjadi draf; kosongkan kondisi/panen/stok/tenggat/jadwal | duplicate; uji reset draf dan salinan foto |
| FR-PB-01 | Cari nama/commodity, filter kategori/harga/stok/jadwal, pesan hasil kosong dan clear filter URL | OfferController; uji filter efektif dan empty state |
| FR-PB-02 | Koordinat tervalidasi, radius/urut Haversine, hasil tunggal daftar/peta dan label straight_line | OfferController/Reminders; uji jarak murni dan radius. Rendering peta di Next.js |
| FR-PB-03 | Quote divalidasi ulang, checkout/reservasi atomik, key retry, tenggat scheduler | OrderFlow/Midtrans; uji stok berlebih, quote, retry dan expiry |
| FR-PB-04 | Tagihan/tenggat/instruksi Snap, hasil dan alasan/status gateway | Midtrans + payment.status_message/fraud_status; uji signature, nominal, challenge, pending dan settlement (CHG-01) |
| FR-PB-05 | Riwayat milik sendiri, snapshot pickup, jadwal usulan dan konfirmasi berat; mismatch ke issue | OrderController; uji privasi, jadwal, penerimaan dan mismatch |
| FR-PB-06 | Batal sesuai payment state, complaint/evidence, tanggapan dan status refund | OrderController, ComplaintController, AdminController; uji cancel, thread dan bukti refund |
| FR-PB-07 | closing_soon, sisa detik dan slot, tenggat server menghentikan checkout | Offer/OfferController/OrderFlow; countdown rendering di frontend |
| FR-PB-08 | Favorit commodity/farmer, radius/reminders dan satu notifikasi per user/offer | CommunityController/Reminders; uji pencocokan dan deduplikasi |
| FR-PB-09 | Kebutuhan aktif, tanggapan offer/slot milik petani, cek ulang saat select/checkout, close/fulfilled/expiry | CommunityController + scheduler; uji selection tanpa reservasi dan stok berubah |
| FR-AD-01 | Antrean menurut status, applicant/evidence privat, keputusan beralasan dan notifikasi | AdminController; uji approval wajib bukti dan alur lengkap |
| FR-AD-02 | Lihat payment lewat daftar/detail order, verifikasi penerimaan dana melalui gateway, audit/idempotensi | Midtrans/OrderController; uji duplicate webhook dan Get Status (CHG-01) |
| FR-AD-03 | Filter pengaduan type/status, thread klarifikasi kedua pihak + bukti, nonaktif offer beralasan tanpa menghapus order/tanpa dapat diterbitkan ulang sepihak oleh petani, tutup laporan/notifikasi | ComplaintController/AdminController/OfferController; uji filter, thread dan close |
| FR-AD-04 | Riwayat gabungan status/payment/pickup/refund/complaint, resolve dengan receipt buyer + agreement, nominal/bukti/status refund | OrderController/AdminController; uji larangan complete sebelum receipt dan bukti refund |
| FR-AD-05 | Satu ledger tiap order, total berat final, filter periode/area/commodity, transaksi sumber | ReportController/OrderFlow; uji ledger sekali dan laporan setelah koreksi |
| FR-AD-06 | Faktor kategori/unit/sumber/versi/metode/asumsi/validitas; hanya hitung data yang sesuai; null dan jumlah uncalculated | Impact/AdminController/ReportController; uji unit/metode salah, faktor kedaluwarsa dan snapshot |
| FR-AD-07 | Pilih scope distribution/orders, filter, CSV UTF-8 dengan periode/waktu/satuan dan sel aman | ReportController; uji filter/cakupan/escape formula |

## Aturan bisnis

- BR-01â€“03: integer gram/rupiah, available=max(0,stock-reserved-withheld), lock stok, idempotency, snapshot harga dan waktu server.
- BR-04â€“05: tenggat minimum TTL/closes_at/pickup; adaptasi Midtrans tetap mempertahankan stok saat hasil belum pasti. 404 tidak dianggap terminal; operator meninjau kasus ambigu. Lewat jadwal masuk issue dan tidak otomatis selesai.
- BR-06: reservasi dilepas sekali; stok masuk withheld sampai pernyataan kelayakan petani. Admin tidak menggantikan pernyataan tersebut.
- BR-07: penutupan mempertahankan order aktif; shortage/perubahan kondisi yang mengganggu pemenuhan menandai semua reservasi aktif sebagai issue dan memberi tahu pihak terkait.
- BR-08: jadwal baru tidak mengganti jadwal lama sebelum buyer menerima; penolakan mempertahankan jadwal awal.
- BR-09: receipt berasal dari endpoint buyer. Admin tidak dapat menyelesaikan tanpa buyer_confirmed_at; mismatch memerlukan reason, agreement dan buyer_agreed.
- BR-10: correct-distribution memperbarui ledger yang sama, order dan sold_g atomik; mencatat sebelum/sesudah, aktor, alasan, agreement dan key retry. Stok jual tidak ditambah diam-diam.
- BR-11: istilah pangan tersalurkan; estimasi hanya dengan faktor yang sah/berlaku dan metadata lengkap; tanpa faktor tetap null. Tidak ada klaim pangan terselamatkan otomatis.
- BR-12: memilih tanggapan hanya menyiapkan konfirmasi; POST /orders tetap melakukan semua validasi dan reservasi yang sama.

## Persyaratan nonfungsional dan batas verifikasi

| ID | Dukungan/bukti | Verifikasi lingkungan yang tetap diperlukan |
|---|---|---|
| NFR-01 | Pagination, indeks, eager loading dan perhitungan SQL katalog | Beban 100 pengguna/10.000 offer, p95 maksimal 2 detik pada server target |
| NFR-02â€“04 | API preferensi dan kontrak JSON untuk UI responsif/aksesibel | Lebar layar, uji pengguna, keyboard/kontras/text scale pada Next.js |
| NFR-05 | HTTPS wajib di production, proxy tepercaya terkonfigurasi, hashing, role/owner, rate limit dan file tervalidasi | TLS/proxy aktual, audit keamanan deployment dan pentest |
| NFR-06 | Evidence privat melalui controller, snapshot alamat hanya untuk pihak order/admin | Uji storage/web server dan akses lintas akun di staging |
| NFR-07 | Transaksi, lock urut, state stok, unique keys/ledger dan retry tests | Dua proses checkout paralel di MySQL/InnoDB; SQLite tidak membuktikan row locking |
| NFR-08 | Health endpoint dan runbook operasional | Monitoring availability 99,5% bulanan dan pencatatan downtime |
| NFR-09 | Panduan backup database/storage dan pemulihan | Jadwalkan harian; buktikan RPO 24 jam/RTO 4 jam lewat restore drill |
| NFR-10 | Webhook, sync-payment dan polling UI 15â€“30 detik | Uji dua sesi di deployment; scheduler setiap menit bukan pengganti polling stok |
| NFR-11 | Kontrak API tidak tergantung browser | Matriks dua versi mayor browser pada frontend |
| NFR-12 | Audit status/stok/harga/jadwal/payment/keputusan/koreksi; reset token tidak masuk log; regression tests | Retensi log, monitoring dan pemeriksaan operasional |
| NFR-13 | GET order/retry key memulihkan hasil, katalog terpisah dari peta | Simulasi koneksi terputus dan peta gagal pada frontend |

Figma belum berhasil diakses. UI dan NFR operasional tidak dinyatakan lulus hanya dari source backend. Lihat TESTING.md untuk bukti yang benar-benar dijalankan serta langkah uji staging. Penulisan source tidak memasang dependency, mengubah database operasional, menjalankan migration milik pengguna, atau membuat commit.
