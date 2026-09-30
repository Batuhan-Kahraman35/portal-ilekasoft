<?php
/**
 * Zimmet Onay Servisi
 *
 * Sirket hatlarinin (Urun_Hizmet) personel dogrulamasini yonetir.
 * Panelden manuel tetiklenir; ileride cron istenirse ayni sinif cagrilir.
 *
 * Akis:
 *   1) donemBaslat()  -> aday hatlar tespit edilir, Zimmet_Onay kayitlari acilir
 *   2) gonderPartisi()-> parti parti SMS/WhatsApp gonderilir (AJAX ile tekrarlanir)
 *   3) yanitIsle()    -> public sayfadan gelen TC dogrulanir
 *   4) havuzaAl() / devret() -> tespit edilen hatlar icin duzeltme hareketi
 *
 * @author Batuhan Kahraman
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/SmsSender.php';

class ZimmetOnay
{
    /** @var Database */
    private $db;

    /** @var array|null Ayar onbellegi */
    private $ayarlar = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Ayarlar
    // ------------------------------------------------------------------

    /**
     * Tum ayarlari anahtar => deger dizisi olarak dondurur.
     */
    public function ayarlar()
    {
        if ($this->ayarlar === null) {
            $this->ayarlar = [];
            $rows = $this->db->fetchAll("
                SELECT zimmet_onay_ayar_anahtar, zimmet_onay_ayar_deger
                FROM tanim_zimmet_onay_ayarlari
                WHERE zimmet_onay_ayar_durum = 1
            ");
            foreach ($rows as $row) {
                $this->ayarlar[$row['zimmet_onay_ayar_anahtar']] = $row['zimmet_onay_ayar_deger'];
            }
        }
        return $this->ayarlar;
    }

    /**
     * Tek bir ayari okur.
     */
    public function ayar($anahtar, $varsayilan = null)
    {
        $ayarlar = $this->ayarlar();
        return isset($ayarlar[$anahtar]) && $ayarlar[$anahtar] !== '' ? $ayarlar[$anahtar] : $varsayilan;
    }

    /**
     * Ayarlari toplu gunceller (panelden).
     */
    public function ayarKaydet(array $degerler, $kullaniciId)
    {
        foreach ($degerler as $anahtar => $deger) {
            $this->db->update(
                'tanim_zimmet_onay_ayarlari',
                [
                    'zimmet_onay_ayar_deger'                   => (string)$deger,
                    'zimmet_onay_ayar_guncelleyen_kullanici_id' => $kullaniciId,
                    'zimmet_onay_ayar_guncelleme_tarihi'       => date('Y-m-d H:i:s'),
                ],
                ['zimmet_onay_ayar_anahtar' => $anahtar]
            );
        }
        $this->ayarlar = null; // onbellegi bosalt
        return true;
    }

    /**
     * Aktif donem kodu (2026-07).
     */
    public function aktifDonem()
    {
        return date('Y-m');
    }

    // ------------------------------------------------------------------
    // Aday hat tespiti
    // ------------------------------------------------------------------

    /**
     * Urune ait TUM hat numaralari, her biri icin en son stok hareketiyle.
     *
     * Kapsam bilerek genistir: yalnizca zimmetli gorunen hatlar degil, sistemde
     * havuzda gorunen hatlar da listeye girer. Amac, teslim kaydi girilmeyi
     * unutulmus hatlari yanitlardan tespit etmektir.
     *
     * mevcut_durum:
     *   ZIMMETLI -> son hareket cikis (tipi=1), sistemde bir personelde gorunuyor
     *   HAVUZDA  -> son hareket giris (tipi=0) veya alis; sistemde sahipsiz gorunuyor
     *
     * HAVUZDA olan hatlarda kayit sahipsiz acilir (kullanici_id = 0). Boylece kim
     * TC girerse girsin yanit FARKLI_PERSONEL olarak duser ve IK devir yapabilir.
     *
     * @return array Aday hat listesi (her biri gonderilebilirlik bilgisiyle)
     */
    public function adayHatlar()
    {
        $urunId = (int)$this->ayar('urun_hizmet_id', 5);

        $sql = "
            WITH SonHareket AS (
                SELECT
                    sh.stok_hareket_id,
                    sh.stok_hareket_seri_no,
                    sh.stok_hareket_tipi,
                    sh.stok_hareket_belge_tipi,
                    sh.stok_hareket_tarihi,
                    sh.stok_hareket_personel_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY sh.stok_hareket_seri_no
                        ORDER BY sh.stok_hareket_tarihi DESC, sh.stok_hareket_id DESC
                    ) AS sira
                FROM Stok_Hareket sh
                WHERE sh.urun_hizmet_id = ?
                  AND sh.stok_hareket_durum = 1
                  AND sh.stok_hareket_seri_no IS NOT NULL
                  AND sh.stok_hareket_seri_no <> ''
            )
            SELECT
                s.stok_hareket_id,
                s.stok_hareket_seri_no,
                s.stok_hareket_tipi,
                s.stok_hareket_belge_tipi,
                s.stok_hareket_personel_id,
                CONVERT(VARCHAR(19), s.stok_hareket_tarihi, 120) AS teslim_tarihi,
                k.kullanici_id,
                k.kullanici_ad,
                k.kullanici_soyad,
                k.kullanici_tc_kimlik_no,
                k.kullanici_durum,
                d.departman_adi
            FROM SonHareket s
            LEFT JOIN kullanicilar k ON s.stok_hareket_personel_id = k.kullanici_id
            LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
            WHERE s.sira = 1
            ORDER BY s.stok_hareket_tipi DESC, k.kullanici_ad, k.kullanici_soyad
        ";

        $rows = $this->db->fetchAll($sql, [$urunId]);

        $adaylar = [];
        foreach ($rows as $row) {
            $hatNo    = self::telefonNormalize($row['stok_hareket_seri_no']);
            $zimmetli = ((int)$row['stok_hareket_tipi'] === 1);

            // Tek engel: numaraya teknik olarak mesaj gonderilememesi.
            // Personelin pasif olmasi, TC eksikligi veya hattin havuzda gorunmesi
            // gonderime engel DEGILDIR; amac hattin fiilen kimde oldugunu tespit etmektir.
            $neden = ($hatNo === null)
                ? 'Hat numarasi gecersiz: ' . $row['stok_hareket_seri_no']
                : null;

            // Yanit degerlendirmesinde dikkat edilecek durumlar (gonderimi engellemez)
            $uyarilar = [];

            if (!$zimmetli) {
                // Havuzda gorunuyor: son hareket iade veya alis girisi.
                // Yanit gelirse teslim kaydi girilmeyi unutulmus demektir.
                $iadeEden = trim(($row['kullanici_ad'] ?? '') . ' ' . ($row['kullanici_soyad'] ?? ''));
                $uyarilar[] = $iadeEden !== ''
                    ? 'Havuzda gorunuyor (son islem: ' . $iadeEden . ' iadesi)'
                    : 'Havuzda gorunuyor - hic zimmetlenmemis';
            } elseif (empty($row['kullanici_id'])) {
                $uyarilar[] = 'Zimmet kaydinda personel tanimli degil';
            } else {
                if ((int)$row['kullanici_durum'] === 0) {
                    $uyarilar[] = 'Personel pasif (isten ayrilmis)';
                }
                if (empty($row['kullanici_tc_kimlik_no'])) {
                    $uyarilar[] = 'Personelin TC kaydi eksik - kendi TC si ile onaylayamaz';
                }
            }

            $row['hat_no']         = $hatNo;
            $row['mevcut_durum']   = $zimmetli ? 'ZIMMETLI' : 'HAVUZDA';
            $row['gonderilebilir'] = ($neden === null);
            $row['atlama_nedeni']  = $neden;
            $row['uyari']          = $uyarilar ? implode(' / ', $uyarilar) : null;

            // Havuzdaki hat sahipsiz kabul edilir; yanit veren kisi FARKLI_PERSONEL olarak duser
            $row['kullanici_id'] = $zimmetli ? $row['kullanici_id'] : null;
            $row['personel_adi'] = $zimmetli
                ? trim(($row['kullanici_ad'] ?? '') . ' ' . ($row['kullanici_soyad'] ?? ''))
                : '';

            $adaylar[] = $row;
        }

        return $adaylar;
    }

    // ------------------------------------------------------------------
    // Donem baslatma
    // ------------------------------------------------------------------

    /**
     * Donem kayitlarini olusturur. Ayni hat + donem icin ikinci kayit acilmaz
     * (UX_Zimmet_Onay_Hat_Donem benzersiz indeksi ile DB seviyesinde de garanti).
     *
     * @return array ['eklenen'=>int, 'mevcut'=>int, 'gonderilecek'=>int, 'atlanan'=>int, 'toplam'=>int]
     */
    public function donemBaslat($donem, $kullaniciId)
    {
        $adaylar = $this->adayHatlar();
        $kanal   = $this->ayar('kanal', 'SMS');

        $eklenen = 0;
        $mevcut  = 0;
        $atlanan = 0;

        foreach ($adaylar as $aday) {
            // Hat numarasi cozulemiyorsa kayit acamayiz (benzersiz anahtar hat_no)
            if ($aday['hat_no'] === null) {
                $atlanan++;
                continue;
            }

            $var = $this->db->fetchOne("
                SELECT zimmet_onay_id
                FROM Zimmet_Onay
                WHERE zimmet_onay_hat_no = ? AND zimmet_onay_donem = ?
            ", [$aday['hat_no'], $donem]);

            if ($var) {
                $mevcut++;
                continue;
            }

            $gonderilebilir = $aday['gonderilebilir'];

            try {
                $this->db->insert('Zimmet_Onay', [
                    'zimmet_onay_stok_hareket_id'       => $aday['stok_hareket_id'],
                    'zimmet_onay_kullanici_id'          => $aday['kullanici_id'] ?: 0,
                    'zimmet_onay_hat_no'                => $aday['hat_no'],
                    'zimmet_onay_token'                 => self::tokenUret(),
                    'zimmet_onay_donem'                 => $donem,
                    'zimmet_onay_kanal'                 => $kanal,
                    'zimmet_onay_gonderildi'            => 0,
                    'zimmet_onay_gonderim_hata'         => $gonderilebilir ? null : $aday['atlama_nedeni'],
                    'zimmet_onay_durum_kodu'            => $gonderilebilir ? 'BEKLIYOR' : 'GONDERILMEDI',
                    'zimmet_onay_olusturan_kullanici_id' => $kullaniciId,
                    'zimmet_onay_durum'                 => 1,
                ]);
                $eklenen++;
                if (!$gonderilebilir) {
                    $atlanan++;
                }
            } catch (Exception $e) {
                // Yaris durumunda benzersiz indeks devreye girer, mukerrer olusmaz
                $mevcut++;
            }
        }

        return [
            'toplam'       => count($adaylar),
            'eklenen'      => $eklenen,
            'mevcut'       => $mevcut,
            'atlanan'      => $atlanan,
            'gonderilecek' => $this->bekleyenSayisi($donem),
        ];
    }

    // ------------------------------------------------------------------
    // Gonderim
    // ------------------------------------------------------------------

    /**
     * Henuz mesaj gonderilmemis kayit sayisi.
     */
    public function bekleyenSayisi($donem)
    {
        $row = $this->db->fetchOne("
            SELECT COUNT(*) AS sayi
            FROM Zimmet_Onay
            WHERE zimmet_onay_donem = ?
              AND zimmet_onay_durum = 1
              AND zimmet_onay_gonderildi = 0
              AND zimmet_onay_durum_kodu <> 'GONDERILMEDI'
        ", [$donem]);
        return (int)($row['sayi'] ?? 0);
    }

    /**
     * Bir parti mesaj gonderir. Panelden AJAX ile tekrar tekrar cagrilir.
     *
     * @param string $donem
     * @param int    $limit    Bu partide islenecek kayit sayisi
     * @param bool   $testMode true ise gercek gonderim yapilmaz
     * @param int    $kullaniciId
     * @return array ['islenen'=>int,'basarili'=>int,'basarisiz'=>int,'kalan'=>int,'detay'=>array]
     */
    public function gonderPartisi($donem, $limit, $testMode, $kullaniciId)
    {
        $limit = max(1, min(50, (int)$limit));

        $kayitlar = $this->db->fetchAll("
            SELECT TOP ($limit)
                z.zimmet_onay_id,
                z.zimmet_onay_hat_no,
                z.zimmet_onay_token,
                z.zimmet_onay_kullanici_id,
                z.zimmet_onay_kanal,
                k.kullanici_ad,
                k.kullanici_soyad
            FROM Zimmet_Onay z
            LEFT JOIN kullanicilar k ON z.zimmet_onay_kullanici_id = k.kullanici_id
            WHERE z.zimmet_onay_donem = ?
              AND z.zimmet_onay_durum = 1
              AND z.zimmet_onay_gonderildi = 0
              AND z.zimmet_onay_durum_kodu <> 'GONDERILMEDI'
            ORDER BY z.zimmet_onay_id
        ", [$donem]);

        $provider = null;
        if (!$testMode) {
            $provider = ornek_sms_kanal($this->db);
            if (!$provider) {
                return [
                    'islenen'   => 0,
                    'basarili'  => 0,
                    'basarisiz' => 0,
                    'kalan'     => $this->bekleyenSayisi($donem),
                    'hata'      => 'Aktif SMS kanali bulunamadi. Entegrasyon Yonetimi sayfasindan SMS kanali tanimlayin.',
                    'detay'     => [],
                ];
            }
        }

        $basarili  = 0;
        $basarisiz = 0;
        $detay     = [];

        foreach ($kayitlar as $kayit) {
            $adSoyad = trim(($kayit['kullanici_ad'] ?? '') . ' ' . ($kayit['kullanici_soyad'] ?? ''));
            $mesaj   = $this->mesajOlustur($kayit['zimmet_onay_token'], $adSoyad, $kayit['zimmet_onay_hat_no'], $donem);
            $hatNo   = $kayit['zimmet_onay_hat_no'];

            if ($testMode) {
                $this->db->update('Zimmet_Onay', [
                    'zimmet_onay_gonderildi'        => 1,
                    'zimmet_onay_gonderim_tarihi'   => date('Y-m-d H:i:s'),
                    'zimmet_onay_gonderim_hata'     => 'TEST MODU - gercek gonderim yapilmadi',
                    'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
                    'zimmet_onay_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

                $this->smsLog($hatNo, $mesaj, $kayit['zimmet_onay_kullanici_id'], true, 'TEST_MODE', null);
                $basarili++;
                $detay[] = ['hat_no' => $hatNo, 'personel' => $adSoyad, 'durum' => 'test', 'mesaj' => $mesaj];
                continue;
            }

            $sonuc = ornek_sms_send($hatNo, $mesaj, $provider);

            if (!empty($sonuc['success'])) {
                $this->db->update('Zimmet_Onay', [
                    'zimmet_onay_gonderildi'        => 1,
                    'zimmet_onay_gonderim_tarihi'   => date('Y-m-d H:i:s'),
                    'zimmet_onay_gonderim_hata'     => null,
                    'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
                    'zimmet_onay_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

                $this->smsLog($hatNo, $mesaj, $kayit['zimmet_onay_kullanici_id'], true, substr((string)$sonuc['response'], 0, 500), null, $provider['id'] ?? null);
                $basarili++;
                $detay[] = ['hat_no' => $hatNo, 'personel' => $adSoyad, 'durum' => 'basarili'];
            } else {
                $hata = $sonuc['error'] ?? $sonuc['response'] ?? 'Bilinmeyen hata';

                // gonderildi=0 kalir; butona tekrar basildiginda yeniden denenir
                $this->db->update('Zimmet_Onay', [
                    'zimmet_onay_gonderim_hata'     => substr((string)$hata, 0, 500),
                    'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
                    'zimmet_onay_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

                $this->smsLog($hatNo, $mesaj, $kayit['zimmet_onay_kullanici_id'], false, null, substr((string)$hata, 0, 500), $provider['id'] ?? null);
                $basarisiz++;
                $detay[] = ['hat_no' => $hatNo, 'personel' => $adSoyad, 'durum' => 'basarisiz', 'hata' => $hata];
            }

            // Saglayici rate limit
            usleep(700000);
        }

        return [
            'islenen'   => count($kayitlar),
            'basarili'  => $basarili,
            'basarisiz' => $basarisiz,
            'kalan'     => $this->bekleyenSayisi($donem),
            'detay'     => $detay,
        ];
    }

    /**
     * TEST gonderimi: secilen numaraya, secilen personelin TC'siyle dogrulanabilen
     * gercek bir onay linki gonderir.
     *
     * Kayit 'TEST' doneminde acilir, boylece gercek donem istatistiklerini kirletmez.
     * Ayni numaraya tekrar gonderilirse kayit sifirlanir (yeni token uretilir).
     *
     * @return array ['success'=>bool, 'message'=>string, 'link'=>?string]
     */
    public function testGonderimi($hedefNumara, $personelId, $kullaniciId)
    {
        $hatNo = self::telefonNormalize($hedefNumara);
        if ($hatNo === null) {
            return ['success' => false, 'message' => 'Geçersiz numara. 10 haneli ve 5 ile başlamalı (örn: 5551234567).'];
        }

        $personel = $this->db->fetchOne("
            SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_tc_kimlik_no
            FROM kullanicilar WHERE kullanici_id = ?
        ", [$personelId]);

        if (!$personel) {
            return ['success' => false, 'message' => 'Personel bulunamadı.'];
        }
        if (empty($personel['kullanici_tc_kimlik_no'])) {
            return ['success' => false, 'message' => 'Seçilen personelin TC kimlik no kaydı yok, doğrulama test edilemez.'];
        }

        $provider = ornek_sms_kanal($this->db);
        if (!$provider) {
            return ['success' => false, 'message' => 'Aktif SMS kanalı bulunamadı.'];
        }

        $donem = 'TEST';
        $token = self::tokenUret();
        $simdi = date('Y-m-d H:i:s');

        $mevcut = $this->db->fetchOne("
            SELECT zimmet_onay_id FROM Zimmet_Onay
            WHERE zimmet_onay_hat_no = ? AND zimmet_onay_donem = ?
        ", [$hatNo, $donem]);

        if ($mevcut) {
            // Onceki testi sifirla
            $this->db->update('Zimmet_Onay', [
                'zimmet_onay_kullanici_id'         => $personel['kullanici_id'],
                'zimmet_onay_token'                => $token,
                'zimmet_onay_durum_kodu'           => 'BEKLIYOR',
                'zimmet_onay_gonderildi'           => 0,
                'zimmet_onay_gonderim_hata'        => null,
                'zimmet_onay_yanit_tarihi'         => null,
                'zimmet_onay_yanit_ip'             => null,
                'zimmet_onay_girilen_kullanici_id' => null,
                'zimmet_onay_girilen_tc'           => null,
                'zimmet_onay_deneme_sayisi'        => 0,
                'zimmet_onay_kilit'                => 0,
                'zimmet_onay_aksiyon'              => 'YOK',
                'zimmet_onay_durum'                => 1,
                'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
                'zimmet_onay_guncelleme_tarihi'    => $simdi,
            ], ['zimmet_onay_id' => $mevcut['zimmet_onay_id']]);
            $kayitId = $mevcut['zimmet_onay_id'];
        } else {
            $kayitId = $this->db->insert('Zimmet_Onay', [
                'zimmet_onay_stok_hareket_id' => 0,
                'zimmet_onay_kullanici_id'    => $personel['kullanici_id'],
                'zimmet_onay_hat_no'          => $hatNo,
                'zimmet_onay_token'           => $token,
                'zimmet_onay_donem'           => $donem,
                'zimmet_onay_kanal'           => $this->ayar('kanal', 'SMS'),
                'zimmet_onay_gonderildi'      => 0,
                'zimmet_onay_durum_kodu'      => 'BEKLIYOR',
                'zimmet_onay_olusturan_kullanici_id' => $kullaniciId,
                'zimmet_onay_durum'           => 1,
            ]);
        }

        $adSoyad = trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']);
        $mesaj   = $this->mesajOlustur($token, $adSoyad, $hatNo, $donem);
        $link    = rtrim((string)$this->ayar('link_base', ''), '?&') . '?t=' . $token;

        $sonuc = ornek_sms_send($hatNo, $mesaj, $provider);

        if (!empty($sonuc['success'])) {
            $this->db->update('Zimmet_Onay', [
                'zimmet_onay_gonderildi'      => 1,
                'zimmet_onay_gonderim_tarihi' => $simdi,
                'zimmet_onay_gonderim_hata'   => 'TEST GONDERIMI',
            ], ['zimmet_onay_id' => $kayitId]);

            $this->smsLog($hatNo, $mesaj, $personel['kullanici_id'], true, substr((string)$sonuc['response'], 0, 500), null, $provider['id'] ?? null);

            return [
                'success' => true,
                'message' => $hatNo . ' numarasına test mesajı gönderildi.',
                'link'    => $link,
                'mesaj'   => $mesaj,
                'tc_ipucu' => substr($personel['kullanici_tc_kimlik_no'], 0, 3) . '****' . substr($personel['kullanici_tc_kimlik_no'], -2),
            ];
        }

        $hata = $sonuc['error'] ?? $sonuc['response'] ?? 'Bilinmeyen hata';
        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_gonderim_hata' => substr((string)$hata, 0, 500),
        ], ['zimmet_onay_id' => $kayitId]);
        $this->smsLog($hatNo, $mesaj, $personel['kullanici_id'], false, null, substr((string)$hata, 0, 500), $provider['id'] ?? null);

        return ['success' => false, 'message' => 'Gönderilemedi: ' . $hata, 'link' => $link, 'mesaj' => $mesaj];
    }

    /**
     * Tek bir kaydin mesajini yeniden gondermek icin gonderim bayragini sifirlar.
     */
    public function tekrarGonderilecekIsaretle($zimmetOnayId, $kullaniciId)
    {
        return $this->db->update('Zimmet_Onay', [
            'zimmet_onay_gonderildi'        => 0,
            'zimmet_onay_gonderim_hata'     => null,
            'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleme_tarihi' => date('Y-m-d H:i:s'),
        ], ['zimmet_onay_id' => $zimmetOnayId]);
    }

    /**
     * Kilitlenen kaydin kilidini acar ve deneme sayacini sifirlar.
     * Personelin TC kaydi duzeltildikten sonra kullanilir.
     */
    public function kilitAc($zimmetOnayId, $kullaniciId)
    {
        $kayit = $this->kayitGetir($zimmetOnayId);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayıt bulunamadı.'];
        }

        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_kilit'             => 0,
            'zimmet_onay_deneme_sayisi'     => 0,
            'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleme_tarihi' => date('Y-m-d H:i:s'),
        ], ['zimmet_onay_id' => $zimmetOnayId]);

        return ['success' => true, 'message' => 'Kilit açıldı. Personel aynı linkten tekrar deneyebilir.'];
    }

    /**
     * IK yetkilisi tarafindan panelden manuel onay.
     * Personel linke giremeyen (telefonu yok, mesaj ulasmadi, TC kaydi eksik)
     * durumlarda kullanilir. Onaylayan kullanici guncelleyen alaninda tutulur.
     */
    public function manuelOnayla($zimmetOnayId, $kullaniciId)
    {
        $kayit = $this->kayitGetir($zimmetOnayId);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayıt bulunamadı.'];
        }

        if (in_array($kayit['zimmet_onay_durum_kodu'], ['ONAYLANDI', 'FARKLI_PERSONEL', 'KULLANILMIYOR'], true)) {
            return ['success' => false, 'message' => 'Bu hat için zaten yanıt verilmiş.'];
        }

        if ($kayit['zimmet_onay_aksiyon'] !== 'YOK') {
            return ['success' => false, 'message' => 'Bu kayıt için havuza alma / devir işlemi yapılmış.'];
        }

        $simdi = date('Y-m-d H:i:s');
        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_durum_kodu'        => 'ONAYLANDI',
            'zimmet_onay_yanit_tarihi'      => $simdi,
            'zimmet_onay_yanit_ip'          => 'MANUEL',
            'zimmet_onay_kilit'             => 0,
            'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleme_tarihi' => $simdi,
        ], ['zimmet_onay_id' => $zimmetOnayId]);

        return ['success' => true, 'message' => 'Hat manuel olarak onaylandı.'];
    }

    /**
     * Sablondan mesaj metnini uretir.
     */
    public function mesajOlustur($token, $adSoyad, $hatNo, $donem)
    {
        $sablon  = $this->ayar('mesaj_sablonu', 'Sirket hattinizin zimmet kontrolu yapilmaktadir. Onaylamak icin: {link}');
        $linkBase = rtrim((string)$this->ayar('link_base', 'https://portal.ornekfirma.com/zimmet-onay.php'), '?&');
        $link    = $linkBase . '?t=' . $token;

        return str_replace(
            ['{link}', '{ad}', '{hat_no}', '{donem}'],
            [$link, $adSoyad, $hatNo, $donem],
            $sablon
        );
    }

    // ------------------------------------------------------------------
    // Public sayfa - yanit isleme
    // ------------------------------------------------------------------

    /**
     * Token ile kaydi getirir (public sayfa icin, personel bilgisi ACIKLANMAZ).
     */
    public function tokenIleBul($token)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) {
            return null;
        }

        return $this->db->fetchOne("
            SELECT
                z.zimmet_onay_id,
                z.zimmet_onay_token,
                z.zimmet_onay_hat_no,
                z.zimmet_onay_donem,
                z.zimmet_onay_kullanici_id,
                z.zimmet_onay_durum_kodu,
                z.zimmet_onay_deneme_sayisi,
                z.zimmet_onay_kilit,
                CONVERT(VARCHAR(19), z.zimmet_onay_yanit_tarihi, 120) AS yanit_tarihi
            FROM Zimmet_Onay z
            WHERE z.zimmet_onay_token = ? AND z.zimmet_onay_durum = 1
        ", [$token]);
    }

    /**
     * TC dogrulamasini isler.
     *
     * @return array ['sonuc'=>string, 'mesaj'=>string]
     *   sonuc: onaylandi | farkli_personel | hatali_tc | kilitli | gecersiz | zaten_yanitlandi
     */
    public function yanitIsle($token, $tc, $ip)
    {
        $kayit = $this->tokenIleBul($token);
        if (!$kayit) {
            return ['sonuc' => 'gecersiz', 'mesaj' => 'Bağlantı geçersiz veya süresi dolmuş.'];
        }

        if ((int)$kayit['zimmet_onay_kilit'] === 1) {
            return ['sonuc' => 'kilitli', 'mesaj' => 'Çok fazla hatalı deneme yapıldı. Lütfen İnsan Kaynakları ile iletişime geçin.'];
        }

        if (in_array($kayit['zimmet_onay_durum_kodu'], ['ONAYLANDI', 'FARKLI_PERSONEL', 'KULLANILMIYOR'], true)) {
            return ['sonuc' => 'zaten_yanitlandi', 'mesaj' => 'Bu hat için daha önce yanıt verilmiş.'];
        }

        $tc = preg_replace('/[^0-9]/', '', (string)$tc);
        if (strlen($tc) !== 11) {
            return ['sonuc' => 'hatali_tc', 'mesaj' => 'T.C. Kimlik No 11 haneli olmalıdır.'];
        }

        $simdi = date('Y-m-d H:i:s');

        // 1) Kayittaki personelin TC'si mi?
        $sahip = $this->db->fetchOne("
            SELECT kullanici_id, kullanici_tc_kimlik_no
            FROM kullanicilar
            WHERE kullanici_id = ?
        ", [$kayit['zimmet_onay_kullanici_id']]);

        if ($sahip && (string)$sahip['kullanici_tc_kimlik_no'] === $tc) {
            $this->db->update('Zimmet_Onay', [
                'zimmet_onay_durum_kodu'    => 'ONAYLANDI',
                'zimmet_onay_yanit_tarihi'  => $simdi,
                'zimmet_onay_yanit_ip'      => $ip,
                'zimmet_onay_guncelleme_tarihi' => $simdi,
            ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

            return ['sonuc' => 'onaylandi', 'mesaj' => 'Zimmetiniz onaylandı. Teşekkür ederiz.'];
        }

        // 2) Baska bir personelin TC'si mi? -> hat el degistirmis
        $diger = $this->db->fetchOne("
            SELECT kullanici_id
            FROM kullanicilar
            WHERE kullanici_tc_kimlik_no = ? AND kullanici_durum = 1
        ", [$tc]);

        if ($diger) {
            $this->db->update('Zimmet_Onay', [
                'zimmet_onay_durum_kodu'          => 'FARKLI_PERSONEL',
                'zimmet_onay_girilen_kullanici_id' => $diger['kullanici_id'],
                'zimmet_onay_yanit_tarihi'        => $simdi,
                'zimmet_onay_yanit_ip'            => $ip,
                'zimmet_onay_guncelleme_tarihi'   => $simdi,
            ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

            return ['sonuc' => 'farkli_personel', 'mesaj' => 'Kaydınız alındı. Hat kaydınız üzerinize aktarılmak üzere İnsan Kaynakları tarafından incelenecektir.'];
        }

        // 3) Hicbir personele ait degil -> hatali deneme
        $deneme    = (int)$kayit['zimmet_onay_deneme_sayisi'] + 1;
        $maxDeneme = (int)$this->ayar('max_deneme', 3);
        $kilit     = $deneme >= $maxDeneme ? 1 : 0;

        // Eslesmeyen TC saklanir: hatti kullanan kisi sistemde kayitli degilse
        // (taseron, yeni baslayan, TC kaydi eksik personel) IK bunu panelden gorup
        // personel kaydini duzeltebilir ve kilidi acabilir.
        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_deneme_sayisi'     => $deneme,
            'zimmet_onay_kilit'             => $kilit,
            'zimmet_onay_girilen_tc'        => $tc,
            'zimmet_onay_guncelleme_tarihi' => $simdi,
        ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

        if ($kilit) {
            return ['sonuc' => 'kilitli', 'mesaj' => 'Çok fazla hatalı deneme yapıldı. Lütfen İnsan Kaynakları ile iletişime geçin.'];
        }

        $kalan = $maxDeneme - $deneme;
        return ['sonuc' => 'hatali_tc', 'mesaj' => "Girdiğiniz T.C. Kimlik No sistemde bulunamadı. Kalan deneme hakkı: $kalan"];
    }

    /**
     * "Bu hatti kullanmiyorum" yaniti - atil hat tespiti.
     */
    public function kullanilmiyorIsaretle($token, $ip)
    {
        $kayit = $this->tokenIleBul($token);
        if (!$kayit) {
            return ['sonuc' => 'gecersiz', 'mesaj' => 'Bağlantı geçersiz veya süresi dolmuş.'];
        }

        if (in_array($kayit['zimmet_onay_durum_kodu'], ['ONAYLANDI', 'FARKLI_PERSONEL', 'KULLANILMIYOR'], true)) {
            return ['sonuc' => 'zaten_yanitlandi', 'mesaj' => 'Bu hat için daha önce yanıt verilmiş.'];
        }

        $simdi = date('Y-m-d H:i:s');
        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_durum_kodu'        => 'KULLANILMIYOR',
            'zimmet_onay_yanit_tarihi'      => $simdi,
            'zimmet_onay_yanit_ip'          => $ip,
            'zimmet_onay_guncelleme_tarihi' => $simdi,
        ], ['zimmet_onay_id' => $kayit['zimmet_onay_id']]);

        return ['sonuc' => 'kullanilmiyor', 'mesaj' => 'Bildiriminiz alındı. Hattın iadesi için sizinle iletişime geçilecektir.'];
    }

    // ------------------------------------------------------------------
    // Aksiyonlar
    // ------------------------------------------------------------------

    /**
     * Bir hattin su anki durumunu son stok hareketinden okur.
     *
     * @return string ZIMMETLI | HAVUZDA
     */
    private function hatDurumu($hatNo)
    {
        $urunId = (int)$this->ayar('urun_hizmet_id', 5);

        $row = $this->db->fetchOne("
            SELECT TOP 1 stok_hareket_tipi
            FROM Stok_Hareket
            WHERE urun_hizmet_id = ?
              AND stok_hareket_seri_no = ?
              AND stok_hareket_durum = 1
            ORDER BY stok_hareket_tarihi DESC, stok_hareket_id DESC
        ", [$urunId, $hatNo]);

        return ((int)($row['stok_hareket_tipi'] ?? 0) === 1) ? 'ZIMMETLI' : 'HAVUZDA';
    }

    /**
     * Hatti havuza alir: Stok_Hareket'e iade (giris) satiri yazar.
     * Hat zaten havuzda gorunuyorsa mukerrer iade satiri yazilmaz.
     */
    public function havuzaAl($zimmetOnayId, $kullaniciId)
    {
        $kayit = $this->kayitGetir($zimmetOnayId);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayit bulunamadi.'];
        }
        if ($kayit['zimmet_onay_aksiyon'] !== 'YOK') {
            return ['success' => false, 'message' => 'Bu kayit icin zaten islem yapilmis.'];
        }
        if ($this->hatDurumu($kayit['zimmet_onay_hat_no']) === 'HAVUZDA') {
            return ['success' => false, 'message' => 'Bu hat zaten havuzda gorunuyor, iade kaydi gerekmiyor.'];
        }

        $aciklama = 'Zimmet onay donemi ' . $kayit['zimmet_onay_donem'] . ' - yanit alinamadi, havuza alindi';
        $this->stokHareketiEkle($kayit, 0, $kayit['zimmet_onay_kullanici_id'], $aciklama, $kullaniciId);

        $this->aksiyonIsle($zimmetOnayId, 'HAVUZA_ALINDI', $kullaniciId);

        return ['success' => true, 'message' => 'Hat havuza alindi. Zimmet Yonetimi ekranindan yeni personele teslim edebilirsiniz.'];
    }

    /**
     * Hatti, TC'sini giren gercek kullaniciya devreder (iade + teslim).
     */
    public function devret($zimmetOnayId, $kullaniciId)
    {
        $kayit = $this->kayitGetir($zimmetOnayId);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayit bulunamadi.'];
        }
        if ($kayit['zimmet_onay_durum_kodu'] !== 'FARKLI_PERSONEL' || empty($kayit['zimmet_onay_girilen_kullanici_id'])) {
            return ['success' => false, 'message' => 'Devir yalnizca "Farkli Personelde" durumundaki kayitlar icin yapilabilir.'];
        }
        if ($kayit['zimmet_onay_aksiyon'] !== 'YOK') {
            return ['success' => false, 'message' => 'Bu kayit icin zaten islem yapilmis.'];
        }

        $donem = $kayit['zimmet_onay_donem'];

        // 1) Eski personelden iade - yalnizca hat halen zimmetli gorunuyorsa.
        // Havuzda gorunen hatta iade satiri yazmak stok bakiyesini bozar; bu durumda
        // zaten teslim kaydi hic girilmemis demektir, dogrudan teslim satiri yeterlidir.
        $zimmetliydi = ($this->hatDurumu($kayit['zimmet_onay_hat_no']) === 'ZIMMETLI');

        if ($zimmetliydi) {
            $this->stokHareketiEkle(
                $kayit, 0, $kayit['zimmet_onay_kullanici_id'],
                'Zimmet onay donemi ' . $donem . ' - hat el degistirmis, eski personelden iade',
                $kullaniciId
            );
        }

        // 2) Gercek kullaniciya teslim
        $this->stokHareketiEkle(
            $kayit, 1, $kayit['zimmet_onay_girilen_kullanici_id'],
            $zimmetliydi
                ? 'Zimmet onay donemi ' . $donem . ' - hat kullanicisi dogrulandi, devir yapildi'
                : 'Zimmet onay donemi ' . $donem . ' - havuzda gorunen hat, eksik teslim kaydi tamamlandi',
            $kullaniciId
        );

        $this->aksiyonIsle($zimmetOnayId, 'DEVREDILDI', $kullaniciId);

        return [
            'success' => true,
            'message' => $zimmetliydi
                ? 'Hat, dogrulanan personele devredildi.'
                : 'Havuzda gorunen hat icin eksik teslim kaydi olusturuldu, hat dogrulanan personele zimmetlendi.'
        ];
    }

    /**
     * IK yetkilisi hatti, panelden sectigi personele devreder ve kaydi
     * ONAYLANDI olarak isaretler.
     *
     * devret()'ten farki: personel yanitina bagli degildir. Yanit gelmemis,
     * cevapsiz kalmis veya sahibi yanlis bilinen hatlar icin kullanilir.
     *
     * @param int $hedefPersonelId Hattin devredilecegi personel
     */
    public function secilenPersoneleDevret($zimmetOnayId, $hedefPersonelId, $kullaniciId)
    {
        $kayit = $this->kayitGetir($zimmetOnayId);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayıt bulunamadı.'];
        }
        if ($kayit['zimmet_onay_aksiyon'] !== 'YOK') {
            return ['success' => false, 'message' => 'Bu kayıt için zaten havuza alma / devir işlemi yapılmış.'];
        }

        $hedefPersonelId = (int)$hedefPersonelId;
        $hedef = $this->db->fetchOne("
            SELECT kullanici_id, kullanici_ad, kullanici_soyad
            FROM kullanicilar WHERE kullanici_id = ?
        ", [$hedefPersonelId]);

        if (!$hedef) {
            return ['success' => false, 'message' => 'Seçilen personel bulunamadı.'];
        }

        $donem       = $kayit['zimmet_onay_donem'];
        $eskiSahipId = (int)$kayit['zimmet_onay_kullanici_id'];
        $zimmetli    = ($this->hatDurumu($kayit['zimmet_onay_hat_no']) === 'ZIMMETLI');
        $adSoyad     = trim($hedef['kullanici_ad'] . ' ' . $hedef['kullanici_soyad']);

        // Hat zaten dogru personelde gorunuyorsa stok hareketi yazilmaz, yalnizca onaylanir
        $stokDegisti = !($zimmetli && $eskiSahipId === $hedefPersonelId);

        if ($stokDegisti) {
            // 1) Halen zimmetliyse once eski personelden iade
            if ($zimmetli) {
                $this->stokHareketiEkle(
                    $kayit, 0, $eskiSahipId,
                    'Zimmet onay donemi ' . $donem . ' - panelden devir, eski personelden iade',
                    $kullaniciId
                );
            }

            // 2) Secilen personele teslim
            $this->stokHareketiEkle(
                $kayit, 1, $hedefPersonelId,
                $zimmetli
                    ? 'Zimmet onay donemi ' . $donem . ' - panelden secilen personele devredildi'
                    : 'Zimmet onay donemi ' . $donem . ' - havuzda gorunen hat, panelden teslim kaydi olusturuldu',
                $kullaniciId
            );
        }

        $simdi = date('Y-m-d H:i:s');
        $this->db->update('Zimmet_Onay', [
            'zimmet_onay_kullanici_id'      => $hedefPersonelId,
            'zimmet_onay_durum_kodu'        => 'ONAYLANDI',
            'zimmet_onay_yanit_tarihi'      => $simdi,
            'zimmet_onay_yanit_ip'          => 'PANEL_DEVIR',
            'zimmet_onay_kilit'             => 0,
            'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleme_tarihi' => $simdi,
        ], ['zimmet_onay_id' => $zimmetOnayId]);

        $this->aksiyonIsle($zimmetOnayId, 'DEVREDILDI', $kullaniciId);

        return [
            'success' => true,
            'message' => $stokDegisti
                ? $kayit['zimmet_onay_hat_no'] . ' numaralı hat ' . $adSoyad . ' adına zimmetlendi ve onaylandı.'
                : $kayit['zimmet_onay_hat_no'] . ' numaralı hat zaten ' . $adSoyad . ' adına zimmetliydi, kayıt onaylandı.'
        ];
    }

    /**
     * Stok_Hareket'e zimmet satiri ekler.
     *
     * @param array $kayit    Zimmet_Onay kaydi
     * @param int   $tipi     1: Cikis (teslim), 0: Giris (iade)
     * @param int   $personelId
     */
    private function stokHareketiEkle($kayit, $tipi, $personelId, $aciklama, $kullaniciId)
    {
        $urunId = (int)$this->ayar('urun_hizmet_id', 5);

        return $this->db->insert('Stok_Hareket', [
            'stok_hareket_fatura_no'   => 'ZMT-' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'stok_hareket_tipi'        => $tipi,
            'stok_hareket_belge_tipi'  => 'ZIMMET',
            'stok_hareket_tarihi'      => date('Y-m-d H:i:s'),
            'stok_hareket_cari_id'     => null,
            'stok_hareket_personel_id' => $personelId,
            'urun_hizmet_id'           => $urunId,
            'stok_hareket_miktar'      => 1,
            'stok_hareket_birim'       => 'ADET',
            'stok_hareket_birim_fiyat' => 0,
            'stok_hareket_kdv_id'      => 1,
            'stok_hareket_kdv_tutari'  => 0,
            'stok_hareket_ara_toplam'  => 0,
            'stok_hareket_satir_toplam' => 0,
            'stok_hareket_seri_no'     => $kayit['zimmet_onay_hat_no'],
            'stok_hareket_aciklama'    => $aciklama,
            'stok_hareket_durum'       => 1,
            'stok_hareket_olusturan_kullanici_id' => $kullaniciId,
        ]);
    }

    private function aksiyonIsle($zimmetOnayId, $aksiyon, $kullaniciId)
    {
        return $this->db->update('Zimmet_Onay', [
            'zimmet_onay_aksiyon'            => $aksiyon,
            'zimmet_onay_aksiyon_tarihi'     => date('Y-m-d H:i:s'),
            'zimmet_onay_aksiyon_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleyen_kullanici_id' => $kullaniciId,
            'zimmet_onay_guncelleme_tarihi'  => date('Y-m-d H:i:s'),
        ], ['zimmet_onay_id' => $zimmetOnayId]);
    }

    // ------------------------------------------------------------------
    // Listeleme / istatistik
    // ------------------------------------------------------------------

    public function kayitGetir($zimmetOnayId)
    {
        return $this->db->fetchOne("
            SELECT * FROM Zimmet_Onay WHERE zimmet_onay_id = ? AND zimmet_onay_durum = 1
        ", [$zimmetOnayId]);
    }

    /**
     * Donem listesi (DataTable icin).
     */
    public function liste($filtre = [])
    {
        $sql = "
            SELECT
                z.zimmet_onay_id,
                z.zimmet_onay_hat_no,
                z.zimmet_onay_donem,
                z.zimmet_onay_kanal,
                z.zimmet_onay_gonderildi,
                z.zimmet_onay_gonderim_hata,
                z.zimmet_onay_durum_kodu,
                z.zimmet_onay_deneme_sayisi,
                z.zimmet_onay_kilit,
                z.zimmet_onay_girilen_tc,
                z.zimmet_onay_aksiyon,
                z.zimmet_onay_kullanici_id,
                z.zimmet_onay_girilen_kullanici_id,
                CONVERT(VARCHAR(19), z.zimmet_onay_gonderim_tarihi, 120) AS gonderim_tarihi,
                CONVERT(VARCHAR(19), z.zimmet_onay_yanit_tarihi, 120)    AS yanit_tarihi,
                d.zimmet_onay_durum_adi,
                d.zimmet_onay_durum_renk,
                k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi,
                k.kullanici_durum AS personel_durum,
                CASE WHEN k.kullanici_tc_kimlik_no IS NULL OR k.kullanici_tc_kimlik_no = '' THEN 1 ELSE 0 END AS tc_eksik,
                dep.departman_adi,
                g.kullanici_ad + ' ' + g.kullanici_soyad AS gercek_kullanici_adi,
                DATEDIFF(DAY, z.zimmet_onay_gonderim_tarihi, GETDATE()) AS gecen_gun,
                -- Kayit acilirken hattin sistemdeki durumu (kaynak hareketin tipi)
                CASE WHEN sh.stok_hareket_tipi = 1 THEN 'ZIMMETLI' ELSE 'HAVUZDA' END AS mevcut_durum
            FROM Zimmet_Onay z
            LEFT JOIN tanim_zimmet_onay_durumlari d ON z.zimmet_onay_durum_kodu = d.zimmet_onay_durum_kodu
            LEFT JOIN kullanicilar k   ON z.zimmet_onay_kullanici_id = k.kullanici_id
            LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
            LEFT JOIN kullanicilar g   ON z.zimmet_onay_girilen_kullanici_id = g.kullanici_id
            LEFT JOIN Stok_Hareket sh  ON z.zimmet_onay_stok_hareket_id = sh.stok_hareket_id
            WHERE z.zimmet_onay_durum = 1
        ";
        $params = [];

        if (!empty($filtre['donem'])) {
            $sql .= " AND z.zimmet_onay_donem = ?";
            $params[] = $filtre['donem'];
        }
        if (!empty($filtre['durum_kodu'])) {
            $sql .= " AND z.zimmet_onay_durum_kodu = ?";
            $params[] = $filtre['durum_kodu'];
        }
        if (!empty($filtre['personel_id'])) {
            $sql .= " AND z.zimmet_onay_kullanici_id = ?";
            $params[] = $filtre['personel_id'];
        }
        if (!empty($filtre['aksiyon'])) {
            $sql .= " AND z.zimmet_onay_aksiyon = ?";
            $params[] = $filtre['aksiyon'];
        }
        if (!empty($filtre['arama'])) {
            $sql .= " AND (z.zimmet_onay_hat_no LIKE ? OR k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ?)";
            $params[] = '%' . $filtre['arama'] . '%';
            $params[] = '%' . $filtre['arama'] . '%';
            $params[] = '%' . $filtre['arama'] . '%';
        }
        // Cevapsiz: gonderilmis, bekleme suresi dolmus, hala yanit yok
        if (!empty($filtre['cevapsiz'])) {
            $beklemeGun = (int)$this->ayar('bekleme_gun', 7);
            $sql .= " AND z.zimmet_onay_durum_kodu = 'BEKLIYOR'
                      AND z.zimmet_onay_gonderildi = 1
                      AND DATEDIFF(DAY, z.zimmet_onay_gonderim_tarihi, GETDATE()) >= " . $beklemeGun;
        }

        $sql .= " ORDER BY z.zimmet_onay_donem DESC, z.zimmet_onay_id DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * InfoBox istatistikleri.
     */
    public function istatistik($donem)
    {
        $beklemeGun = (int)$this->ayar('bekleme_gun', 7);

        $row = $this->db->fetchOne("
            SELECT
                COUNT(*) AS toplam,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'ONAYLANDI'       THEN 1 ELSE 0 END) AS onaylandi,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'BEKLIYOR'        THEN 1 ELSE 0 END) AS bekliyor,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'FARKLI_PERSONEL' THEN 1 ELSE 0 END) AS farkli_personel,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'KULLANILMIYOR'   THEN 1 ELSE 0 END) AS kullanilmiyor,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'GONDERILMEDI'    THEN 1 ELSE 0 END) AS gonderilmedi,
                SUM(CASE WHEN zimmet_onay_gonderildi = 1                 THEN 1 ELSE 0 END) AS gonderilen,
                SUM(CASE WHEN zimmet_onay_durum_kodu = 'BEKLIYOR'
                          AND zimmet_onay_gonderildi = 1
                          AND DATEDIFF(DAY, zimmet_onay_gonderim_tarihi, GETDATE()) >= ?
                         THEN 1 ELSE 0 END) AS cevapsiz
            FROM Zimmet_Onay
            WHERE zimmet_onay_donem = ? AND zimmet_onay_durum = 1
        ", [$beklemeGun, $donem]);

        return [
            'toplam'          => (int)($row['toplam'] ?? 0),
            'onaylandi'       => (int)($row['onaylandi'] ?? 0),
            'bekliyor'        => (int)($row['bekliyor'] ?? 0),
            'farkli_personel' => (int)($row['farkli_personel'] ?? 0),
            'kullanilmiyor'   => (int)($row['kullanilmiyor'] ?? 0),
            'gonderilmedi'    => (int)($row['gonderilmedi'] ?? 0),
            'gonderilen'      => (int)($row['gonderilen'] ?? 0),
            'cevapsiz'        => (int)($row['cevapsiz'] ?? 0),
            // Sirada bekleyen gercek gonderim sayisi (GONDERILMEDI olanlar haric)
            'gonderilecek'    => $this->bekleyenSayisi($donem),
        ];
    }

    /**
     * Kayitli donem listesi (filtre dropdown'i icin).
     */
    public function donemler()
    {
        return $this->db->fetchAll("
            SELECT DISTINCT zimmet_onay_donem
            FROM Zimmet_Onay
            WHERE zimmet_onay_durum = 1
            ORDER BY zimmet_onay_donem DESC
        ");
    }

    public function durumTanimlari()
    {
        return $this->db->fetchAll("
            SELECT zimmet_onay_durum_kodu, zimmet_onay_durum_adi, zimmet_onay_durum_renk
            FROM tanim_zimmet_onay_durumlari
            WHERE zimmet_onay_durum_durum = 1
            ORDER BY zimmet_onay_durum_sira
        ");
    }

    // ------------------------------------------------------------------
    // Yardimcilar
    // ------------------------------------------------------------------

    /**
     * 32 haneli benzersiz token.
     */
    private static function tokenUret()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Telefonu 10 haneye normalize eder (5xxxxxxxxx). Gecersizse null.
     */
    public static function telefonNormalize($numara)
    {
        $numara = preg_replace('/[^0-9]/', '', (string)$numara);
        if (strlen($numara) > 10 && substr($numara, 0, 2) === '90') {
            $numara = substr($numara, 2);
        }
        if (strlen($numara) === 11 && substr($numara, 0, 1) === '0') {
            $numara = substr($numara, 1);
        }
        if (strlen($numara) !== 10 || substr($numara, 0, 1) !== '5') {
            return null;
        }
        return $numara;
    }

    private function smsLog($telefon, $mesaj, $kullaniciId, $basarili, $apiYanit, $hata, $kanalId = null)
    {
        try {
            // Test modunda kanal secilmemis olabilir; varsayilan kanala baglanir
            if (!$kanalId) {
                $kanal   = ornek_sms_kanal($this->db);
                $kanalId = $kanal['id'] ?? 0;
            }

            ornek_entegrasyon_log_toplu($this->db, [[
                'kanal_id'     => $kanalId,
                'islem_tipi'   => 'SMS_ZIMMET_ONAY',
                'istek'        => $mesaj,
                'cevap'        => $apiYanit,
                'durum'        => $basarili,
                'hata'         => $hata,
                'hedef'        => $telefon,
                'kullanici_id' => $kullaniciId ?: null,
                'ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'kaynak'       => 'PANEL',
                'olusturan'    => 0,
            ]]);
        } catch (Exception $e) {
            error_log('Zimmet onay SMS log hatasi: ' . $e->getMessage());
        }
    }
}
