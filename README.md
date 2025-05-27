📄 Deskripsi Adapter Paydia SNAP

Terima pembayaran QRIS dinamis melalui layanan Paydia SNAP. Adapter ini menghubungkan invoice pelanggan dengan sistem pembayaran Paydia secara langsung dan real-time. Mendukung mode produksi dan sandbox, serta proses transaksi otomatis dengan notifikasi ke sistem FOSSBilling.

Fitur Utama:

    Mendukung QRIS Dinamis via Paydia SNAP

    Pengaturan client_id, client_secret, dan merchant_id

    Mode sandbox untuk uji coba pembayaran

    Transaksi ditandai otomatis sebagai “Paid” saat status sukses diterima

    Kompatibel dengan iframe & banklink tampilan

Prasyarat:

    Akun aktif Paydia

    Akses ke API Key dan Merchant ID

    Whitelist IP untuk webhook (jika diperlukan oleh Paydia)

Catatan Teknis:

    Signature ditangani otomatis oleh SDK Paydia

    Timestamp dikirim dalam format Asia/Jakarta (GMT+7)

    Hanya mendukung pembayaran one-time (bukan langganan berulang)
