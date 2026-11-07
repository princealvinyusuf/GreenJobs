# GreenJobs

## Menjalankan dashboard

1. Pastikan MySQL aktif dan database `job_admin_prod` berisi tabel `green_job`.
2. Jika kredensial berubah, perbarui `config.php`.
3. Jalankan web server dari folder proyek:

   ```bash
   php -S localhost:8080
   ```

4. Buka `http://localhost:8080/Dashboard%20IPK%20Hijau%202025%20(6).html`.

Dashboard harus dibuka melalui web server, bukan langsung dengan `file://`, karena
browser mengambil data dari endpoint PHP `dashboard-data.php`.
