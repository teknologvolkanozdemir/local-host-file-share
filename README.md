# Yerel Ağ Ortak Çalışma Alanı

PHP ve MySQL tabanlı bu uygulama, aynı yerel ağa bağlı kullanıcıların tarayıcıdan dosya paylaşmasını sağlar. Tüm dosya uzantıları yüklenip indirilebilir; metin dosyaları, `.txt` ve `.rtf` dahil, düzenlenebilir. Yeni `.html` notları için kalın, italik, altı çizili ve liste biçimlendirmeleri bulunan bir editör vardır.

## Gereksinimler

- PHP 8.1 veya üzeri; PDO MySQL desteği etkin olmalı
- MySQL 8 veya uyumlu MariaDB
- PHP dosya yükleme sınırları uygulamanın 100 MB sınırına eşit veya daha yüksek olmalı (`upload_max_filesize`, `post_max_size`)
- Uygulamanın yazma izni olan, web kök dizininin **dışında** özel bir depolama dizini

## Kurulum

1. Depoyu sunucuda web sunucusunun PHP çalıştırdığı bir dizine yerleştirin. Apache kullanıyorsanız `.htaccess` korumasını etkinleştirin; Nginx için `config.php` ve `database.sql` dosyalarına HTTP erişimini ayrıca engelleyin.
2. `database.sql` dosyasını MySQL'e içe aktarın. Üretim ortamında uygulama için yalnız gerekli veritabanı yetkileri olan ayrı bir MySQL kullanıcısı oluşturun.
3. Uygulama ayarlarını ortam değişkenleriyle verin:

   ```sh
   export LHS_DB_HOST=127.0.0.1
   export LHS_DB_NAME=local_host_file_share
   export LHS_DB_USER=local_host_file_share
   export LHS_DB_PASSWORD='güçlü-veritabanı-parolası'
   export LHS_STORAGE_PATH=/var/lib/local-host-file-share/files
   ```

   `LHS_STORAGE_PATH` uygulamanın/web sunucusunun belge kökünün dışında olmalıdır. Varsayılanı, depo dizininin bir üstündeki `local-host-file-share-data` dizinidir. Bu alan yalnızca uygulama sistem kullanıcısınca erişilebilir olmalıdır.
4. Uygulama adresini açıp ilk ana kullanıcı hesabını oluşturun. İlk hesap tüm yetkilere sahiptir; kullanıcı hesaplarını ve dosya yükleme, düzenleme, silme ve hesap yönetme yetkilerini uygulama içinden belirler.
5. Yerel ağdan erişim için sunucunun güvenlik duvarında yalnızca güvenilir yerel ağdan uygulama portuna izin verin. Geliştirme amaçlı PHP sunucusu depo dizininden `php -S 0.0.0.0:8000` komutuyla başlatılabilir; bu sunucu internete açık üretim sunucusu olarak kullanılmamalıdır.

## Güvenlik ve dosya desteği

Bu yazılım herkese açık web hosting için önerilmez. Uzak erişim zorunluysa, web kök dizinini ve özel dosya alanını barındıran diski/alanı beklemede şifreleyin; ayrıca HTTPS, güncel sunucu yazılımı, güçlü benzersiz parolalar ve ağ erişim kısıtlamaları kullanın. **Disk şifrelemesi, uygulama çalışırken gelen ağ saldırılarına karşı koruma değildir.**

Yüklenen dosyalar uzantılarına göre engellenmez; rastgele adlarla web kök dizini dışında saklanır ve indirme sırasında ek olarak sunulur, tarayıcıda çalıştırılmaz. Metin editörü metin tabanlı dosya uzantılarını ve RTF kaynak metnini düzenler; `.html` notları biçimlendirilmiş metin olarak saklanır. Düzenleme ve yeni not oluşturma 2 MB ile, dosya yükleme 100 MB ile sınırlıdır.
