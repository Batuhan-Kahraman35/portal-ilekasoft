<?php
/**
 * Mail Gönderim Helper Sınıfı (PHPMailer wrapper)
 *
 * Bağlantı bilgileri config/mail.php yerine Entegrasyon yapısından okunur:
 *   Entegrasyonlar         → 'E-Posta (SMTP)'
 *   EntegrasyonKanallari   → KullaniciAdi = SMTP kullanıcı, Sifre = SMTP şifre,
 *                            AyarJSON = host / port / encryption / timeout /
 *                                       from_address / from_name / varsayilan
 *
 * Gönderim kayıtları EntegrasyonLoglari tablosuna MAIL_ önekiyle yazılır.
 */

// PHPMailer'ı yükle
require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    const ENTEGRASYON_ADI = 'E-Posta (SMTP)';

    private $mail;
    private $kanal;
    private $db;

    /**
     * @param int $kanalId Kanal id'si; 0 ise varsayılan (yoksa ilk aktif) kanal
     * @throws Exception Kanal bulunamazsa
     */
    public function __construct($kanalId = 0)
    {
        $this->db = Database::getInstance();

        $kanal = self::kanal($kanalId);
        if (!$kanal) {
            throw new Exception('Aktif SMTP kanalı bulunamadı. Entegrasyon Yönetimi → Kanallar bölümünden tanımlayın.');
        }

        $this->kanal = $kanal;
        $this->initMailer();
    }

    // ============================================================
    // Kanal çözümleme
    // ============================================================

    /**
     * Kanal satırını sade bir diziye çevirir.
     */
    private static function kanalNormalize(array $row, array $entAyar = [])
    {
        $ayar = [];
        if (!empty($row['EntegrasyonKanallari_AyarJSON'])) {
            $ayar = json_decode($row['EntegrasyonKanallari_AyarJSON'], true) ?: [];
        }

        $kullanici = (string)($row['EntegrasyonKanallari_KullaniciAdi'] ?? '');

        return [
            'id'           => (int)$row['EntegrasyonKanallari_id'],
            'ad'           => (string)$row['EntegrasyonKanallari_Ad'],
            'host'         => (string)($ayar['host'] ?? $entAyar['Host'] ?? ''),
            'port'         => (int)($ayar['port'] ?? $entAyar['Port'] ?? 587),
            'encryption'   => strtolower((string)($ayar['encryption'] ?? $entAyar['Encryption'] ?? 'tls')),
            'username'     => $kullanici,
            'password'     => (string)($row['EntegrasyonKanallari_Sifre'] ?? ''),
            'timeout'      => (int)($ayar['timeout'] ?? $entAyar['Timeout'] ?? 60),
            'from_address' => (string)($ayar['from_address'] ?? $kullanici),
            'from_name'    => (string)($ayar['from_name'] ?? $row['EntegrasyonKanallari_Ad']),
            'varsayilan'   => !empty($ayar['varsayilan']),
            'durum'        => (int)$row['EntegrasyonKanallari_Durum'],
        ];
    }

    /**
     * SMTP entegrasyonuna bağlı kanalları döner (normalize edilmiş).
     *
     * @param bool $sadeceAktif Sadece Durum = 1 olanlar
     * @return array
     */
    public static function kanallar($sadeceAktif = true)
    {
        $db = Database::getInstance();

        $ent = $db->fetchOne("
            SELECT Entegrasyonlar_id,
                   CAST(Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS ayar
            FROM Entegrasyonlar
            WHERE Entegrasyonlar_Ad = ? AND Entegrasyonlar_Durum = 1
        ", [self::ENTEGRASYON_ADI]);

        if (!$ent) return [];

        $entAyar = json_decode($ent['ayar'] ?? '{}', true) ?: [];

        $sql = "SELECT * FROM EntegrasyonKanallari
                WHERE EntegrasyonKanallari_Entegrasyonlar_id = ?";
        if ($sadeceAktif) {
            $sql .= " AND EntegrasyonKanallari_Durum = 1";
        }
        $sql .= " ORDER BY EntegrasyonKanallari_id";

        $rows = $db->fetchAll($sql, [$ent['Entegrasyonlar_id']]) ?: [];

        return array_map(function ($r) use ($entAyar) {
            return self::kanalNormalize($r, $entAyar);
        }, $rows);
    }

    /**
     * Tek kanal döner. $id verilirse o kanal, verilmezse varsayılan kanal.
     * Varsayılan işaretli kanal yoksa ilk aktif kanal döner.
     *
     * @return array|null
     */
    public static function kanal($id = 0)
    {
        $kanallar = self::kanallar(true);
        if (empty($kanallar)) return null;

        $id = (int)$id;
        if ($id > 0) {
            foreach ($kanallar as $k) {
                if ($k['id'] === $id) return $k;
            }
            return null;
        }

        foreach ($kanallar as $k) {
            if ($k['varsayilan']) return $k;
        }

        return $kanallar[0];
    }

    /**
     * Varsayılan aktif kanal.
     * @return array|null
     */
    public static function varsayilanKanal()
    {
        return self::kanal(0);
    }

    /**
     * Bu örneğin bağlı olduğu kanal id'si (log kayıtları için).
     */
    public function getKanalId()
    {
        return $this->kanal['id'];
    }

    // ============================================================
    // PHPMailer
    // ============================================================

    /**
     * PHPMailer'ı başlat
     */
    private function initMailer()
    {
        $this->mail = new PHPMailer(true);

        // SMTP ayarları
        $this->mail->isSMTP();
        $this->mail->Host     = $this->kanal['host'];
        $this->mail->Port     = $this->kanal['port'];
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $this->kanal['username'];
        $this->mail->Password = $this->kanal['password'];

        // Şifreleme
        if ($this->kanal['encryption'] === 'ssl') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($this->kanal['encryption'] === 'tls') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // Gönderen bilgileri
        $this->mail->setFrom($this->kanal['from_address'], $this->kanal['from_name']);

        // Karakter seti
        $this->mail->CharSet = 'UTF-8';
        $this->mail->Encoding = 'base64';

        // Timeout
        $this->mail->Timeout = $this->kanal['timeout'] ?: 60;
    }

    /**
     * Mail gönder
     * @param string|array $to Alıcı e-posta adresi veya adresleri
     * @param string $subject Konu
     * @param string $body HTML içerik
     * @param array $options Ek seçenekler (cc, bcc, attachments, replyTo, islem_tipi)
     * @return array ['success' => bool, 'message' => string]
     */
    public function send($to, $subject, $body, $options = [])
    {
        $islemTipi = $options['islem_tipi'] ?? 'GONDER';

        try {
            // Alıcıları ekle
            if (is_array($to)) {
                foreach ($to as $email) {
                    $this->mail->addAddress($email);
                }
            } else {
                $this->mail->addAddress($to);
            }

            // CC
            if (!empty($options['cc'])) {
                foreach ((array)$options['cc'] as $cc) {
                    $this->mail->addCC($cc);
                }
            }

            // BCC
            if (!empty($options['bcc'])) {
                foreach ((array)$options['bcc'] as $bcc) {
                    $this->mail->addBCC($bcc);
                }
            }

            // Reply-To
            if (!empty($options['replyTo'])) {
                $this->mail->addReplyTo($options['replyTo']);
            }

            // Ekler
            if (!empty($options['attachments'])) {
                foreach ($options['attachments'] as $attachment) {
                    if (is_array($attachment)) {
                        $this->mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                    } else {
                        $this->mail->addAttachment($attachment);
                    }
                }
            }

            // İçerik
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = strip_tags($body);

            // Gönder
            $this->mail->send();

            // Log kaydet
            $this->logMail($to, $subject, $body, 1, null, $islemTipi);

            // Temizle
            $this->mail->clearAddresses();
            $this->mail->clearCCs();
            $this->mail->clearBCCs();
            $this->mail->clearAttachments();

            return ['success' => true, 'message' => 'Mail başarıyla gönderildi!'];

        } catch (Exception $e) {
            $hata = $this->mail->ErrorInfo ?: $e->getMessage();

            // Log kaydet
            $this->logMail($to, $subject, $body, 0, $hata, $islemTipi);

            // Sonraki gönderimleri etkilememesi için alıcıları temizle
            $this->mail->clearAddresses();
            $this->mail->clearCCs();
            $this->mail->clearBCCs();
            $this->mail->clearAttachments();

            return ['success' => false, 'message' => 'Mail gönderilemedi: ' . $hata];
        }
    }

    /**
     * Test maili gönder
     * @param string $to Alıcı e-posta
     * @return array
     */
    public function sendTest($to)
    {
        $subject = 'Örnek Soft Portal - Test Maili';
        $body = $this->getTestMailTemplate();

        return $this->send($to, $subject, $body, ['islem_tipi' => 'TEST']);
    }

    /**
     * SMTP bağlantı testi (salt okunur, mail göndermez)
     * @return array
     */
    public function testConnection()
    {
        try {
            $smtp = new SMTP();
            $smtp->setTimeout($this->kanal['timeout'] ?: 60);

            $host = $this->kanal['host'];
            if ($this->kanal['encryption'] === 'ssl') {
                $host = 'ssl://' . $host;
            }

            // Bağlan
            if (!$smtp->connect($host, $this->kanal['port'])) {
                return ['success' => false, 'message' => 'SMTP sunucusuna bağlanılamadı!'];
            }

            // EHLO
            if (!$smtp->hello(gethostname())) {
                return ['success' => false, 'message' => 'SMTP EHLO başarısız!'];
            }

            // STARTTLS
            if ($this->kanal['encryption'] === 'tls') {
                if (!$smtp->startTLS()) {
                    return ['success' => false, 'message' => 'STARTTLS başlatılamadı!'];
                }
                $smtp->hello(gethostname());
            }

            // Auth
            if (!$smtp->authenticate($this->kanal['username'], $this->kanal['password'])) {
                return ['success' => false, 'message' => 'SMTP kimlik doğrulama başarısız!'];
            }

            $smtp->quit();

            return ['success' => true, 'message' => 'SMTP bağlantısı başarılı!'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Bağlantı hatası: ' . $e->getMessage()];
        }
    }

    /**
     * Gönderim kaydını EntegrasyonLoglari tablosuna yaz
     */
    private function logMail($to, $subject, $body, $status, $error = null, $islemTipi = 'GONDER')
    {
        try {
            $toStr = is_array($to) ? implode(', ', $to) : (string)$to;

            // Kullanıcı ID'yi session'dan al (CronJob'da null olacak)
            $kullaniciId = $_SESSION['user_id'] ?? $_SESSION['kullanici_id'] ?? null;

            // Kaynak (global değişken varsa kullan)
            global $MAIL_LOG_SOURCE;
            $kaynak = $MAIL_LOG_SOURCE ?? 'MAILER';

            $istek = json_encode([
                'kanal'  => $this->kanal['ad'],
                'from'   => $this->kanal['from_address'],
                'alici'  => $toStr,
                'konu'   => $subject,
                'icerik' => mb_substr((string)$body, 0, 4000),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $this->db->execute("
                INSERT INTO EntegrasyonLoglari (
                    EntegrasyonLoglari_EntegrasyonKanallari_id, EntegrasyonLoglari_IslemTipi,
                    EntegrasyonLoglari_Istek, EntegrasyonLoglari_Cevap, EntegrasyonLoglari_BasariliMi,
                    EntegrasyonLoglari_HataMesaji, EntegrasyonLoglari_Hedef, EntegrasyonLoglari_KullaniciId,
                    EntegrasyonLoglari_IP, EntegrasyonLoglari_Kaynak,
                    EntegrasyonLoglari_OlusturanKullanici, EntegrasyonLoglari_OlusturmaTarihi,
                    EntegrasyonLoglari_Durum
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
            ", [
                $this->kanal['id'],
                'MAIL_' . $islemTipi,
                $istek,
                $status ? 'Gönderildi' : null,
                $status,
                $error,
                mb_substr($toStr, 0, 200),
                $kullaniciId,
                $ip ? mb_substr($ip, 0, 50) : null,
                $kaynak,
                $kullaniciId ?: 1,
            ]);
        } catch (Exception $e) {
            // Log kaydedilemezse sessizce geç
        }
    }

    /**
     * Test mail şablonu
     */
    private function getTestMailTemplate()
    {
        $siteTitle = 'Örnek Soft Portal';
        try {
            $site = $this->db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari");
            $siteTitle = $site['site_ayarlari_site_title'] ?? $siteTitle;
        } catch (Exception $e) {
            // Varsayılan başlık kullanılır
        }

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0d6efd; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border: 1px solid #dee2e6; }
                .footer { background: #e9ecef; padding: 15px; text-align: center; font-size: 12px; color: #6c757d; border-radius: 0 0 8px 8px; }
                .success { color: #198754; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . htmlspecialchars($siteTitle) . '</h1>
                </div>
                <div class="content">
                    <h2 class="success">✅ Mail Gönderimi Başarılı!</h2>
                    <p>Bu bir test mailidir. Mail sisteminiz düzgün çalışıyor.</p>
                    <p><strong>Kanal:</strong> ' . htmlspecialchars($this->kanal['ad']) . '</p>
                    <p><strong>Gönderim Zamanı:</strong> ' . date('d.m.Y H:i:s') . '</p>
                    <p><strong>Sunucu:</strong> ' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '</p>
                </div>
                <div class="footer">
                    <p>Bu mail otomatik olarak gönderilmiştir.</p>
                    <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($siteTitle) . '</p>
                </div>
            </div>
        </body>
        </html>';
    }

    /**
     * Ayarları getir (şifre gizli)
     */
    public function getSettings()
    {
        return [
            'kanal_id'     => $this->kanal['id'],
            'kanal_ad'     => $this->kanal['ad'],
            'host'         => $this->kanal['host'],
            'port'         => $this->kanal['port'],
            'encryption'   => $this->kanal['encryption'],
            'username'     => $this->kanal['username'],
            'password'     => str_repeat('*', strlen($this->kanal['password'])),
            'from_address' => $this->kanal['from_address'],
            'from_name'    => $this->kanal['from_name'],
        ];
    }

    /**
     * PHPMailer kurulu mu kontrol et
     */
    public static function isInstalled()
    {
        return file_exists(__DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php');
    }
}
