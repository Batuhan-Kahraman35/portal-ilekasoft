<?php
/**
 * Admin Panel - Gelir ve Gider Raporu (yalnız e-ticaret)
 *
 * Kapsam: gelir = pazaryeri satışları, gider = pazaryeri komisyonu. Banka masrafları ve
 * e-ticaretle ilişkisi kurulamayan kargo gönderileri rapora girmez.
 *
 * Gelir / gider kaynakları tanim_GelirGiderKaynaklari tablosundan okunur (Durum = 1, Sira).
 * Her kaynak Kodu için aşağıda bir sorgu fonksiyonu vardır (KAYNAK_SORGULARI haritası);
 * haritada karşılığı olmayan kod rapordan atlanır ve sayfada uyarı rozeti gösterilir.
 *
 * Her sorgu fonksiyonu (baslangic, bitis, firmaIdListesi|null) alır ve şu satırları döner:
 *   ay (YYYY-MM), firma_id, firma_adi, alt_kirilim, tutar, adet,
 *   detay_tur    (kırılım satırı tıklanınca açılacak detay türü: DETAY_TURLERI anahtarı),
 *   detay_anahtar(detay türünün kayıt anahtarı: pazaryeri id)
 *
 * Firma kapsamı: null → tüm firmalar (firmasız kayıtlar dahil), dizi → yalnız o firmalar.
 * Kullanıcı firma kısıtlıysa (PageAuth::firmaKisiti) liste daima kendi firmasıdır.
 *
 * Özet rapor: satır sayısı ay × kaynak kadar küçüktür → tek AJAX çağrısı, client-side görünüm.
 * Kırılım detayı: her kırılım satırı → 'detay' action (server-side DataTables, iki aşamalı sorgu).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Firma kısıtı: null → kısıt yok, >0 → yalnız o firma, 0 → hiç kayıt yok
$kisitFirmaId = PageAuth::firmaKisiti($pagePermissions, $user['kullanici_id']);

// Ayarlar (kaynak tanımları) tüm firmaların raporunu etkiler:
// yalnız firma kısıtı olmayan ve düzenleme yetkisi olan kullanıcı değiştirir.
$ayarYetki = $kisitFirmaId === null && !empty($pagePermissions['can_edit']);

/** Rapor kapsam notu: sayfada InfoBox altında, Excel'de en üst satırda */
const RAPOR_NOTU = 'Yalnız e-ticaret (pazaryeri). Tüm tutarlar KDV dahildir. Gelir: pazaryerinin bildirdiği sipariş tutarı (indirimler düşülmüş). Gider: pazaryeri komisyonu (KDV dahil). Net = Gelir − Komisyon; ürün maliyeti, kargo, KDV ve stopaj düşülmez, kâr değildir.';

/**
 * Pazaryerine göre tutar tanımları (Pazaryerleri_EntegrasyonKod → not). Kaynak: admin/includes/*Api.php
 * eşlemeleri; API kodu değişirse burası da güncellenir.
 *   gelir        : sipariş tutarının pazaryerindeki karşılığı
 *   komisyon     : komisyonun nereden / ne zaman geldiği
 *   kdvDahilGelir: true → API komisyonu zaten KDV dahil verir; KomisyonKdvOran ayarı çift KDV yapar
 *   uyari        : bilinen veri sorunu (kırmızı gösterilir)
 */
const PAZARYERI_TUTAR_NOTLARI = [
    'trendyol'    => ['gelir' => 'Paket tutarı (totalPrice), indirimli.',
                      'komisyon' => 'Cari ekstreden gelir; yalnız teslim edilen siparişlerde oluşur. API KDV hariç verir.'],
    'hepsiburada' => ['gelir' => 'Kalem tutarlarının toplamı (totalPrice), indirimli.',
                      'komisyon' => 'Sipariş servisinden, KDV hariç.'],
    'n11'         => ['gelir' => 'Mağaza fatura tutarı (sellerInvoiceAmount). n11\'in karşıladığı indirim düşülmez; müşterinin ödediğinden yüksek olabilir.',
                      'komisyon' => 'Sipariş servisinden yalnız tutar gelir (oran yok), KDV hariç.'],
    'pttavm'      => ['gelir' => 'Kalemlerin KDV dahil tutarı + müşteriden alınan kargo ücreti.',
                      'komisyon' => 'API yalnız oran verir (%12, %14); tutar = KDV dahil kalem tutarı × oran olarak hesaplanır. Oranın KDV dahil mi hariç mi uygulandığı doğrulanmadı.'],
    'koctas'      => ['gelir' => 'Sipariş toplamı (total_price).',
                      'komisyon' => 'API KDV dahil verir (total_commission); ek KDV uygulanmamalı.', 'kdvDahilGelir' => true],
    'ciceksepeti' => ['gelir' => 'Sipariş toplamı.',
                      'komisyon' => 'Komisyon verisi gelmiyor; ciro "Komisyonu Hesaplanmamış" kutusunda görünür.'],
];

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Gelir ve Gider';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// =====================================================================
// Yardımcılar
// =====================================================================

/** Y-m-d biçiminde ve geçerli bir tarih değilse boş döner; geçersiz tarih SQL'e gitmez */
function tarihGecerli($t)
{
    $t = trim((string)$t);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return '';
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $t : '';
}

/**
 * Firma kapsamı koşulu. Kolon adı sabit verilir (kullanıcı girdisi değil).
 * null → koşul yok (firmasız kayıtlar dahil), [] → hiç kayıt yok, dizi → IN (int).
 */
function firmaKosulu($firmaIdler, $kolon)
{
    if ($firmaIdler === null) {
        return '';
    }
    $idler = array_values(array_filter(array_map('intval', $firmaIdler), fn($i) => $i > 0));
    return $idler ? " AND $kolon IN (" . implode(',', $idler) . ") " : " AND 1 = 0 ";
}

/**
 * Tıklanan kırılım satırının firması (detay için). Kolon adı sabit verilir.
 * null → koşul yok, 0 → firmasız kayıtlar (IS NULL), >0 → yalnız o firma.
 */
function satirFirmaKosulu($firmaId, $kolon)
{
    if ($firmaId === null) {
        return '';
    }
    $firmaId = (int)$firmaId;
    return $firmaId > 0 ? " AND $kolon = $firmaId " : " AND $kolon IS NULL ";
}

/**
 * Detay anahtarı olan id listesi → koşul (int'e çevrilir). 0 → kolon NULL olan kayıtlar.
 * Boş liste hiç kayıt döndürmez.
 */
function anahtarIdKosulu($idler, $kolon)
{
    $idler = array_values(array_unique(array_map('intval', (array)$idler)));
    $parca = [];
    $dolu  = array_filter($idler, fn($i) => $i > 0);
    if ($dolu) {
        $parca[] = "$kolon IN (" . implode(',', $dolu) . ")";
    }
    if (in_array(0, $idler, true)) {
        $parca[] = "$kolon IS NULL";
    }
    return $parca ? ' AND (' . implode(' OR ', $parca) . ') ' : ' AND 1 = 0 ';
}

/** Pay / payda yüzdesi (2 hane). Pay yoksa veya payda 0 ise null (arayüzde "—") */
function yuzdeOran($pay, $payda)
{
    return ($pay !== null && (float)$payda != 0) ? round((float)$pay / (float)$payda * 100, 2) : null;
}

/** Renk / ikon desenleri: hem görüntülemede (renkGuvenli/ikonGuvenli) hem kayıtta aynı kural */
const RENK_DESENI = '/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,30})$/';
const IKON_DESENI = '/^[a-zA-Z0-9 _-]{1,60}$/';

/** Tanım tablosundaki renk CSS'e girer; yalnız #hex veya renk adı kabul edilir */
function renkGuvenli($renk)
{
    $renk = trim((string)$renk);
    return preg_match(RENK_DESENI, $renk) ? $renk : '#6c757d';
}

/** İkon sınıfı HTML'e girer; yalnız harf, rakam, boşluk ve tire */
function ikonGuvenli($ikon)
{
    $ikon = trim((string)$ikon);
    return preg_match(IKON_DESENI, $ikon) ? $ikon : 'bi bi-circle';
}

// =====================================================================
// Kaynak sorguları: Kod → fonksiyon(baslangic, bitis, firmaIdler|null)
// Ortak dönüş kolonları: ay, firma_id, firma_adi, alt_kirilim, tutar, adet,
//                        detay_tur, detay_anahtar
// FROM + WHERE yardımcısı (pazaryeriKosulu) kırılım detayında da aynen kullanılır:
// detay toplamı kırılım toplamıyla aynı kalır.
// =====================================================================

/**
 * Komisyon KDV oranı (kkdv.oran): pazaryerleri komisyonu KDV hariç bildirir, rapor KDV dahil gösterir.
 * Kaynak: PazaryeriAyarlari 'KomisyonKdvOran' (kanal → pazaryeri → genel). Ayar yoksa 0 (tutar olduğu gibi).
 * Gereken takma adlar: s = PazaryeriSiparisleri, k = EntegrasyonKanallari.
 */
const KOMISYON_KDV_APPLY = "
        OUTER APPLY (
            SELECT TOP 1 TRY_CAST(pa.PazaryeriAyarlari_Deger AS DECIMAL(5,2)) AS oran
            FROM dbo.PazaryeriAyarlari pa
            WHERE pa.PazaryeriAyarlari_Anahtar = 'KomisyonKdvOran' AND pa.PazaryeriAyarlari_Durum = 1
              AND (pa.PazaryeriAyarlari_EntegrasyonKanallari_id = k.EntegrasyonKanallari_id
                   OR (pa.PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND pa.PazaryeriAyarlari_Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id)
                   OR (pa.PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND pa.PazaryeriAyarlari_Pazaryerleri_id IS NULL))
            ORDER BY CASE WHEN pa.PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL THEN 1
                          WHEN pa.PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 2 ELSE 3 END
        ) kkdv
";

/** KDV dahil komisyon çarpanı (KOMISYON_KDV_APPLY ile birlikte kullanılır) */
const KOMISYON_KDV_CARPAN = '(1 + ISNULL(kkdv.oran, 0) / 100.0)';

/** Kalem komisyonu KDV dahil; komisyonu henüz gelmemiş kalemde NULL kalır */
const KOMISYON_KDV_DAHIL = '(c.PazaryeriSiparisKalemleri_KomisyonTutar * ' . KOMISYON_KDV_CARPAN . ')';

/** Komisyon kalemleri: kaynakKomisyon ve komisyon detayı aynı JOIN'i kullanır (c = kalem) */
const KOMISYON_KALEM_JOIN = "
        INNER JOIN dbo.PazaryeriSiparisKalemleri c
                ON c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
               AND c.PazaryeriSiparisKalemleri_Durum = 1
" . KOMISYON_KDV_APPLY;

/** Pazaryeri siparişleri ortak FROM + WHERE (iptal / iade hariç, sipariş tarihine göre) */
function pazaryeriKosulu($firmaIdler, $kalemJoin = '')
{
    return "
        FROM dbo.PazaryeriSiparisleri s
        INNER JOIN dbo.EntegrasyonKanallari k
                ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
        INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
        $kalemJoin
        LEFT JOIN dbo.Firmalar f
                ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE s.PazaryeriSiparisleri_Durum = 1
          AND ISNULL(s.PazaryeriSiparisleri_DurumKod, '') NOT IN ('iptal', 'iade')
          AND s.PazaryeriSiparisleri_SiparisTarihi >= CAST(? AS DATE)
          AND s.PazaryeriSiparisleri_SiparisTarihi <  DATEADD(DAY, 1, CAST(? AS DATE))
          " . firmaKosulu($firmaIdler, 'k.EntegrasyonKanallari_Firmalar_id') . "
    ";
}

function kaynakPazaryeriSatis($bas, $bit, $firmaIdler)
{
    return Database::getInstance()->fetchAll("
        SELECT
            CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS ay,
            k.EntegrasyonKanallari_Firmalar_id                         AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            p.Pazaryerleri_Ad                                          AS alt_kirilim,
            SUM(ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0))         AS tutar,
            COUNT(*)                                                   AS adet,
            -- Siparişin komisyonu (KDV dahil): kartta Komisyon Hariç = tutar − komisyon
            SUM(ISNULL(ko.komisyon, 0) * " . KOMISYON_KDV_CARPAN . ") AS komisyon,
            CAST('satis' AS VARCHAR(30))                               AS detay_tur,
            CAST(p.Pazaryerleri_id AS VARCHAR(50))                     AS detay_anahtar
        " . pazaryeriKosulu($firmaIdler, "
        OUTER APPLY (
            SELECT SUM(c.PazaryeriSiparisKalemleri_KomisyonTutar) AS komisyon
            FROM dbo.PazaryeriSiparisKalemleri c
            WHERE c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
              AND c.PazaryeriSiparisKalemleri_Durum = 1
        ) ko
" . KOMISYON_KDV_APPLY) . "
        GROUP BY CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120),
                 k.EntegrasyonKanallari_Firmalar_id, f.firma_adi, f.firma_unvan, p.Pazaryerleri_Ad, p.Pazaryerleri_id
    ", [$bas, $bit]);
}

/**
 * Komisyon giderleri: pazaryeri kalem komisyonları (KDV dahil), alt kırılım = pazaryeri adı.
 * detay_anahtar: Pazaryerleri_id.
 */
function kaynakKomisyon($bas, $bit, $firmaIdler)
{
    return Database::getInstance()->fetchAll("
        SELECT
            CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS ay,
            k.EntegrasyonKanallari_Firmalar_id                         AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            p.Pazaryerleri_Ad                                          AS alt_kirilim,
            SUM(ISNULL(" . KOMISYON_KDV_DAHIL . ", 0))                AS tutar,
            COUNT(DISTINCT s.PazaryeriSiparisleri_id)                  AS adet,
            -- Oran paydası: komisyonu gelmiş kalemlerin tutarı (Komisyon / Tutar = etkin oran, KDV dahil)
            SUM(CASE WHEN NOT " . KOMISYONSUZ_KOSUL . " THEN ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0) ELSE 0 END) AS baz,
            CAST('komisyon_pazaryeri' AS VARCHAR(30))                  AS detay_tur,
            CAST(p.Pazaryerleri_id AS VARCHAR(50))                     AS detay_anahtar
        " . pazaryeriKosulu($firmaIdler, KOMISYON_KALEM_JOIN) . "
        GROUP BY CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120),
                 k.EntegrasyonKanallari_Firmalar_id, f.firma_adi, f.firma_unvan, p.Pazaryerleri_Ad, p.Pazaryerleri_id
    ", [$bas, $bit]);
}

/**
 * Komisyonu hesaplanmamış kalem (c = kalem): oranı da tutarı da boş / 0.
 * Yalnız oran boşsa sayılmaz: bazı pazaryerleri (N11, PttAVM) oran vermeden tutar bildirir.
 */
const KOMISYONSUZ_KOSUL = '(ISNULL(c.PazaryeriSiparisKalemleri_KomisyonOran, 0) = 0
                            AND ISNULL(c.PazaryeriSiparisKalemleri_KomisyonTutar, 0) = 0)';

/**
 * Komisyonu hesaplanmamış kalemlerin FROM + WHERE'i (InfoBox ve detay aynı tanımı kullanır).
 * Kırılım seçiliyse yalnız seçili kırılımların pazaryerleri (iki kaynakta da alt kırılım = pazaryeri adı).
 * Dönüş: [sql, params] — params tarih ?'leri dahil, sıra sql'deki ? sırasıdır.
 */
function komisyonsuzTaban($bas, $bit, $firmaIdler, array $kirilimSecim = [])
{
    $params = [$bas, $bit];
    $pzSql  = '';
    $adlar  = array_values(array_unique(array_map(fn($k) => explode('|', $k, 2)[1], array_values($kirilimSecim))));
    if ($adlar) {
        $pzSql  = ' AND p.Pazaryerleri_Ad IN (' . implode(',', array_fill(0, count($adlar), '?')) . ') ';
        $params = array_merge($params, $adlar);
    }
    $sql = pazaryeriKosulu($firmaIdler, "
        INNER JOIN dbo.PazaryeriSiparisKalemleri c
                ON c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
               AND c.PazaryeriSiparisKalemleri_Durum = 1") . "
          AND " . KOMISYONSUZ_KOSUL . $pzSql;
    return [$sql, $params];
}

/** InfoBox: komisyonu hesaplanmamış ciro (kalem tutarı toplamı), raporun tarih + firma + kırılım filtresiyle */
function komisyonsuzOzet($bas, $bit, $firmaIdler, array $kirilimSecim = [])
{
    [$taban, $params] = komisyonsuzTaban($bas, $bit, $firmaIdler, $kirilimSecim);
    $r = Database::getInstance()->fetchOne("
        SELECT COUNT(DISTINCT s.PazaryeriSiparisleri_id)                         AS siparis,
               COUNT(*)                                                         AS kalem,
               ISNULL(SUM(ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0)), 0) AS tutar
        $taban", $params);

    return [
        'siparis' => (int)($r['siparis'] ?? 0),
        'kalem'   => (int)($r['kalem'] ?? 0),
        'tutar'   => round((float)($r['tutar'] ?? 0), 2),
    ];
}

/**
 * Yeni kaynak eklemek: tanım tablosuna Kod ile kayıt + buraya fonksiyon.
 * Kargo gideri şimdilik yok: kargo_gonderiler (firmanın Aras hesabı) pazaryeri siparişleriyle
 * eşleşmiyor, pazaryeri siparişlerinde kargo ücreti verisi yok. Veri gelince e-ticaret kargosu
 * olarak buraya eklenir.
 */
const KAYNAK_SORGULARI = [
    'pazaryeri_satis'    => 'kaynakPazaryeriSatis',
    'komisyon'           => 'kaynakKomisyon',
];

/** Aktif kaynaklar: gelirler önce, giderler sonra; her grup Sira'ya göre */
function aktifKaynaklar()
{
    $liste = Database::getInstance()->fetchAll("
        SELECT GelirGiderKaynaklari_id, GelirGiderKaynaklari_Kod, GelirGiderKaynaklari_Ad,
               GelirGiderKaynaklari_Tip, GelirGiderKaynaklari_Aciklama,
               GelirGiderKaynaklari_Renk, GelirGiderKaynaklari_Ikon, GelirGiderKaynaklari_Sira
        FROM dbo.tanim_GelirGiderKaynaklari
        WHERE GelirGiderKaynaklari_Durum = 1
        ORDER BY CASE GelirGiderKaynaklari_Tip WHEN 'gelir' THEN 0 ELSE 1 END,
                 GelirGiderKaynaklari_Sira, GelirGiderKaynaklari_id
    ");

    return array_map(fn($k) => [
        'kod'      => (string)$k['GelirGiderKaynaklari_Kod'],
        'ad'       => (string)$k['GelirGiderKaynaklari_Ad'],
        'tip'      => $k['GelirGiderKaynaklari_Tip'] === 'gelir' ? 'gelir' : 'gider',
        'aciklama' => (string)($k['GelirGiderKaynaklari_Aciklama'] ?? ''),
        'renk'     => renkGuvenli($k['GelirGiderKaynaklari_Renk'] ?? ''),
        'ikon'     => ikonGuvenli($k['GelirGiderKaynaklari_Ikon'] ?? ''),
        'destekli' => isset(KAYNAK_SORGULARI[$k['GelirGiderKaynaklari_Kod']]),
    ], $liste);
}

/**
 * Filtre girdisini çözer. Hata varsa ['hata' => mesaj] döner.
 * Firma: kısıtlı kullanıcıda daima kendi firması; aksi halde '' → tüm firmalar (null).
 */
function filtreCoz(array $girdi, $kisitFirmaId)
{
    $bas = tarihGecerli($girdi['bas_tarih'] ?? '') ?: date('Y-m-d');
    $bit = tarihGecerli($girdi['bit_tarih'] ?? '') ?: date('Y-m-d');
    if ($bas > $bit) {
        return ['hata' => 'Başlangıç tarihi bitiş tarihinden sonra olamaz.'];
    }

    $firmaId = (int)($girdi['firma_id'] ?? 0);
    if ($kisitFirmaId !== null) {
        $firmaIdler = [(int)$kisitFirmaId]; // 0 → firmaKosulu "1 = 0" üretir
    } else {
        $firmaIdler = $firmaId > 0 ? [$firmaId] : null;
    }

    $kirilim = kirilimCoz($girdi['kirilim'] ?? []);
    if (isset($kirilim['hata'])) {
        return $kirilim;
    }

    return [
        'bas'        => $bas,
        'bit'        => $bit,
        'firmaIdler' => $firmaIdler,
        'kirilim'    => $kirilim,
    ];
}

/** Kırılım filtresi üst sınırı (öğe sayısı / alt kırılım metni uzunluğu) */
const KIRILIM_EN_FAZLA = 50;
const KIRILIM_METIN_UZUNLUK = 150;

/**
 * Kırılım filtresi: 'kaynak_kod|alt_kirilim' dizisi → ['kod|metin' => 'kod|metin'].
 * Biçimi bozuk, kodu aktif kaynaklarda olmayan veya metni uzun öğeler yok sayılır
 * (raporHesapla ayrıca sonuçta bulunmayan çiftleri düşürür ve uygulanan seçimi döner).
 */
function kirilimCoz($girdi)
{
    if ($girdi === '' || $girdi === null) {
        return [];
    }
    $girdi = (array)$girdi;
    if (count($girdi) > KIRILIM_EN_FAZLA) {
        return ['hata' => 'En fazla ' . KIRILIM_EN_FAZLA . ' kırılım seçilebilir.'];
    }

    $kodlar = array_column(array_filter(aktifKaynaklar(), fn($k) => $k['destekli']), 'kod', 'kod');
    $secim  = [];
    foreach ($girdi as $o) {
        if (!is_string($o)) {
            continue;
        }
        $parca = explode('|', trim($o), 2);
        if (count($parca) !== 2 || !isset($kodlar[$parca[0]])) {
            continue;
        }
        $metin = $parca[1];
        if ($metin === '' || !mb_check_encoding($metin, 'UTF-8')
            || mb_strlen($metin, 'UTF-8') > KIRILIM_METIN_UZUNLUK || preg_match('/[\x00-\x1F\x7F]/', $metin)) {
            continue;
        }
        $anahtar = $parca[0] . '|' . $metin;
        $secim[$anahtar] = $anahtar;
    }
    return $secim;
}

/** bas..bit arasındaki tüm aylar (YYYY-MM); boş aylar da grafikte görünsün */
function aralikAylari($bas, $bit)
{
    $aylar = [];
    $d   = new DateTime(substr($bas, 0, 7) . '-01');
    $son = substr($bit, 0, 7);
    while (($a = $d->format('Y-m')) <= $son && count($aylar) < 240) {
        $aylar[] = $a;
        $d->modify('+1 month');
    }
    return $aylar;
}

/**
 * Tüm aktif kaynakları çalıştırıp rapor yapısını üretir.
 * $kirilimSecim ['kod|alt_kirilim' => ...] doluysa yalnız o kaynak + alt kırılım satırları toplanır.
 * Seçenek listesi (kirilimlar) kırılım filtresinden ÖNCEKİ sonuçtan (tarih + firma)
 * üretilir; seçimden bu listede olmayanlar yok sayılır, uygulanan seçim kirilimSecili ile döner.
 */
function raporHesapla($bas, $bit, $firmaIdler, array $kirilimSecim = [])
{
    $kaynaklar = aktifKaynaklar();
    $aylar     = aralikAylari($bas, $bit);

    $aylik     = [];   // [ay][kod] = tutar
    $kirilim   = [];   // [kod][] = {alt_kirilim, firma_id, firma_adi, tutar, adet, detay_tur, anahtarlar}
    $kaynakTop = [];   // [kod] = {tutar, adet}
    $atlanan   = [];
    $satirlar  = [];   // [kod] = sorgu satırları
    $secenek   = [];   // ['kod|alt' => {kaynak_kod, kaynak_ad, tip, alt_kirilim}]

    foreach ($aylar as $a) {
        $aylik[$a] = [];
    }

    // 1) Sorgular; kırılım seçenekleri bu kümeden
    foreach ($kaynaklar as $k) {
        $kod = $k['kod'];
        if (!$k['destekli']) {
            $atlanan[] = ['kod' => $kod, 'ad' => $k['ad']];
            continue;
        }
        $fonk = KAYNAK_SORGULARI[$kod];
        $satirlar[$kod] = [];
        foreach ($fonk($bas, $bit, $firmaIdler) as $r) {
            $r['alt_kirilim'] = (string)($r['alt_kirilim'] ?? '-');
            $satirlar[$kod][] = $r;
            $secenek[$kod . '|' . $r['alt_kirilim']] ??= [
                'kaynak_kod'  => $kod,
                'kaynak_ad'   => $k['ad'],
                'tip'         => $k['tip'],
                'alt_kirilim' => $r['alt_kirilim'],
            ];
        }
    }

    // Seçenekler: kaynak sırası (aktifKaynaklar) → alt kırılım adı
    $kaynakSira = array_flip(array_column($kaynaklar, 'kod'));
    $harmanla   = class_exists('Collator') ? new Collator('tr_TR') : null;
    $kirilimlar = array_values($secenek);
    usort($kirilimlar, fn($x, $y) => ($kaynakSira[$x['kaynak_kod']] <=> $kaynakSira[$y['kaynak_kod']])
        ?: ($harmanla ? $harmanla->compare($x['alt_kirilim'], $y['alt_kirilim']) : strcmp($x['alt_kirilim'], $y['alt_kirilim'])));

    // Uygulanan seçim: yalnız sonuçta bulunan çiftler (boşsa filtre yok)
    $uygulanan = array_values(array_intersect_key($kirilimSecim, $secenek));
    $uygSet    = array_flip($uygulanan);

    // 2) Toplama
    foreach ($satirlar as $kod => $satir) {
        $grup = [];
        $kaynakTop[$kod] = ['tutar' => 0.0, 'adet' => 0, 'baz' => null, 'komisyon' => null];

        foreach ($satir as $r) {
            if ($uygSet && !isset($uygSet[$kod . '|' . $r['alt_kirilim']])) {
                continue;
            }
            $ay    = (string)$r['ay'];
            $tutar = round((float)$r['tutar'], 2);
            $adet  = (int)$r['adet'];
            // baz: oran paydası (yalnız komisyon kaynağında); yoksa null kalır, arayüz oran göstermez
            $baz   = array_key_exists('baz', $r) ? round((float)$r['baz'], 2) : null;
            // komisyon: satışın komisyonu (yalnız satış kaynağında); yoksa null, arayüz "Komisyon Hariç" göstermez
            $kom   = array_key_exists('komisyon', $r) ? round((float)$r['komisyon'], 2) : null;

            $aylik[$ay][$kod] = ($aylik[$ay][$kod] ?? 0) + $tutar;
            $kaynakTop[$kod]['tutar'] += $tutar;
            $kaynakTop[$kod]['adet']  += $adet;
            if ($baz !== null) {
                $kaynakTop[$kod]['baz'] = ($kaynakTop[$kod]['baz'] ?? 0) + $baz;
            }
            if ($kom !== null) {
                $kaynakTop[$kod]['komisyon'] = ($kaynakTop[$kod]['komisyon'] ?? 0) + $kom;
            }

            $firmaAdi = $r['firma_adi'] !== null && $r['firma_adi'] !== '' ? $r['firma_adi'] : '(Firmasız)';
            $anahtar  = $r['alt_kirilim'] . "\x1F" . (int)$r['firma_id'];
            if (!isset($grup[$anahtar])) {
                $grup[$anahtar] = [
                    'alt_kirilim' => (string)($r['alt_kirilim'] ?? '-'),
                    'firma_id'    => (int)$r['firma_id'],   // 0 → firmasız (detayda IS NULL)
                    'firma_adi'   => (string)$firmaAdi,
                    'tutar'       => 0.0,
                    'adet'        => 0,
                    'baz'         => null,
                    'komisyon'    => null,
                    'detay_tur'   => $r['detay_tur'] ?? null,
                    'anahtarlar'  => [],
                ];
            }
            $grup[$anahtar]['tutar'] += $tutar;
            $grup[$anahtar]['adet']  += $adet;
            if ($baz !== null) {
                $grup[$anahtar]['baz'] = ($grup[$anahtar]['baz'] ?? 0) + $baz;
            }
            if ($kom !== null) {
                $grup[$anahtar]['komisyon'] = ($grup[$anahtar]['komisyon'] ?? 0) + $kom;
            }

            // Aynı adlı birden fazla kayıt tek kırılımda birleşebilir → anahtar listesi
            if (isset($r['detay_anahtar']) && $r['detay_anahtar'] !== '') {
                $grup[$anahtar]['anahtarlar'][(string)$r['detay_anahtar']] = (string)$r['detay_anahtar'];
            }
        }
        foreach ($grup as &$g) {
            $g['anahtarlar'] = array_values($g['anahtarlar']);
        }
        unset($g);

        $liste = array_map(function ($g) {
            $g['tutar'] = round($g['tutar'], 2);
            $g['baz']   = $g['baz'] !== null ? round($g['baz'], 2) : null;
            $g['komisyon'] = $g['komisyon'] !== null ? round($g['komisyon'], 2) : null;
            return $g;
        }, array_values($grup));
        usort($liste, fn($x, $y) => $y['tutar'] <=> $x['tutar']);
        $kaynakTop[$kod]['tutar'] = round($kaynakTop[$kod]['tutar'], 2);
        if ($kaynakTop[$kod]['baz'] !== null) {
            $kaynakTop[$kod]['baz'] = round($kaynakTop[$kod]['baz'], 2);
        }
        if ($kaynakTop[$kod]['komisyon'] !== null) {
            $kaynakTop[$kod]['komisyon'] = round($kaynakTop[$kod]['komisyon'], 2);
        }
        $kirilim[$kod] = $liste;
    }

    // Ay satırları: kaynak kırılımı + gelir / gider / net
    $tipler = array_column($kaynaklar, 'tip', 'kod');
    ksort($aylik);
    $aySatirlari = [];
    $topGelir = 0.0;
    $topGider = 0.0;

    foreach ($aylik as $ay => $kodlar) {
        $gelir = 0.0;
        $gider = 0.0;
        foreach ($kodlar as $kod => $t) {
            if (($tipler[$kod] ?? '') === 'gelir') { $gelir += $t; } else { $gider += $t; }
        }
        $aySatirlari[] = [
            'ay'       => $ay,
            'kaynaklar'=> (object)array_map(fn($t) => round($t, 2), $kodlar),
            'gelir'    => round($gelir, 2),
            'gider'    => round($gider, 2),
            'net'      => round($gelir - $gider, 2),
        ];
        $topGelir += $gelir;
        $topGider += $gider;
    }

    return [
        'filtre'    => ['bas' => $bas, 'bit' => $bit, 'kirilim' => $uygulanan],
        'kirilimlar'    => $kirilimlar,
        'kirilimSecili' => $uygulanan,
        'kaynaklar' => $kaynaklar,
        'atlanan'   => $atlanan,
        'aylar'     => $aySatirlari,
        'kaynakTop' => (object)$kaynakTop,
        'kirilim'   => (object)$kirilim,
        'ozet'      => [
            'gelir' => round($topGelir, 2),
            'gider' => round($topGider, 2),
            'net'   => round($topGelir - $topGider, 2),
            'marj'  => $topGelir > 0 ? round(($topGelir - $topGider) / $topGelir * 100, 2) : null,
        ],
    ];
}

/**
 * Firma dropdown'u: yalnız rapora veri sağlayan aktif firmalar (EXISTS ile ucuz):
 * entegrasyonu tanim_Pazaryerleri'ne bağlı kanalların firması.
 * Yeni kaynak (KAYNAK_SORGULARI) eklendiğinde firması buraya UNION ile eklenir.
 * Kısıtlı kullanıcıda listede olmasa bile kendi firması gösterilir (filtre boş kalmasın).
 */
function firmaListesi($kisitFirmaId)
{
    $db = Database::getInstance();
    if ($kisitFirmaId !== null) {
        [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'firma_id');
        return $db->fetchAll("
            SELECT firma_id, firma_adi, firma_unvan
            FROM dbo.Firmalar
            WHERE firma_durum = 1 {$kisitSql}
            ORDER BY firma_adi
        ", $kisitParams);
    }

    return $db->fetchAll("
        SELECT f.firma_id, f.firma_adi, f.firma_unvan
        FROM dbo.Firmalar f
        WHERE f.firma_durum = 1
          AND f.firma_id IN (
              -- pazaryeri kanalları
              SELECT k.EntegrasyonKanallari_Firmalar_id
              FROM dbo.EntegrasyonKanallari k
              INNER JOIN dbo.Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
              WHERE EXISTS (SELECT 1 FROM dbo.tanim_Pazaryerleri p WHERE p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod)
          )
        ORDER BY f.firma_adi
    ");
}

/**
 * "Tutar tanımları" paneli: aktif pazaryerleri + komisyona uygulanan KDV (PazaryeriAyarlari 'KomisyonKdvOran',
 * KOMISYON_KDV_APPLY ile aynı öncelik: pazaryeri → genel; kanal bazlı ayar ayrıca sayılır) + PAZARYERI_TUTAR_NOTLARI.
 */
function tutarTanimlari()
{
    $satirlar = Database::getInstance()->fetchAll("
        SELECT p.Pazaryerleri_Ad AS ad, p.Pazaryerleri_EntegrasyonKod AS kod,
               kd.oran AS kdv_oran,
               (SELECT COUNT(*) FROM dbo.PazaryeriAyarlari pa
                 WHERE pa.PazaryeriAyarlari_Anahtar = 'KomisyonKdvOran' AND pa.PazaryeriAyarlari_Durum = 1
                   AND pa.PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL
                   AND pa.PazaryeriAyarlari_Pazaryerleri_id = p.Pazaryerleri_id) AS kanal_ayar
        FROM dbo.tanim_Pazaryerleri p
        OUTER APPLY (
            SELECT TOP 1 TRY_CAST(pa.PazaryeriAyarlari_Deger AS DECIMAL(5,2)) AS oran
            FROM dbo.PazaryeriAyarlari pa
            WHERE pa.PazaryeriAyarlari_Anahtar = 'KomisyonKdvOran' AND pa.PazaryeriAyarlari_Durum = 1
              AND pa.PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL
              AND (pa.PazaryeriAyarlari_Pazaryerleri_id = p.Pazaryerleri_id OR pa.PazaryeriAyarlari_Pazaryerleri_id IS NULL)
            ORDER BY CASE WHEN pa.PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 1 ELSE 2 END
        ) kd
        WHERE p.Pazaryerleri_Durum = 1
          AND EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisleri s WHERE s.PazaryeriSiparisleri_Pazaryerleri_id = p.Pazaryerleri_id)
        ORDER BY p.Pazaryerleri_Sira, p.Pazaryerleri_Ad
    ");

    return array_map(function ($r) {
        $not  = PAZARYERI_TUTAR_NOTLARI[(string)$r['kod']] ?? [];
        $oran = $r['kdv_oran'] !== null ? (float)$r['kdv_oran'] : 0.0;
        $uyari = $not['uyari'] ?? '';
        if (!empty($not['kdvDahilGelir']) && $oran > 0) {
            $uyari = trim($uyari . ' Komisyon API\'den KDV dahil geldiği halde %' . rtrim(rtrim(number_format($oran, 2, ',', ''), '0'), ',')
                   . ' KDV ayarı var: çift KDV uygulanıyor.');
        }
        return [
            'ad'        => (string)$r['ad'],
            'gelir'     => $not['gelir'] ?? '—',
            'komisyon'  => $not['komisyon'] ?? '—',
            'kdv'       => $oran > 0 ? '+%' . rtrim(rtrim(number_format($oran, 2, ',', ''), '0'), ',') . ' eklenir'
                                     : (!empty($not['kdvDahilGelir']) ? 'Eklenmez (API KDV dahil)' : 'Eklenmez (ayar yok)'),
            'kanalAyar' => (int)$r['kanal_ayar'],
            'uyari'     => $uyari,
        ];
    }, $satirlar);
}

// =====================================================================
// Ayarlar: kaynak tanımları
// =====================================================================

/** LIKE araması için kullanıcı metnindeki joker karakterler etkisizleştirilir */
function likeKacis($s)
{
    return strtr((string)$s, ['[' => '[[]', '%' => '[%]', '_' => '[_]']);
}

// =====================================================================
// Kırılım detayı: tür → tanım. Kırılım satırının detay_tur değeri buradaki anahtardır.
//   ad       : kaynak toplamı detayında sekme / Excel bölüm adı. Anahtarlar id'dir (int).
//   anahtarsiz: true → anahtar listesi zorunlu değil (InfoBox detayı; kapsam rapor filtresinden)
// =====================================================================
const DETAY_TURLERI = [
    'satis'              => ['ad' => 'Pazaryeri satışları'],
    'komisyon_pazaryeri' => ['ad' => 'Pazaryeri komisyonu'],
    'komisyonsuz'        => ['ad' => 'Komisyonu hesaplanmamış ciro', 'anahtarsiz' => true],
];

/** Firma görünen adı (f = Firmalar takma adı; sıralama ve dış SELECT aynı ifadeyi kullanır) */
const FIRMA_AD_SQL = "ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)";

/**
 * Detay isteği doğrulaması. Hata varsa ['hata' => mesaj] döner.
 * tur whitelist; anahtarlar rakam (pazaryeri id); satır firması int.
 */
function detayGirdisi(array $g)
{
    $tur = (string)($g['tur'] ?? '');
    if (!isset(DETAY_TURLERI[$tur])) {
        return ['hata' => 'Geçersiz detay türü.'];
    }
    $anahtarlar = [];
    foreach (array_slice((array)($g['anahtarlar'] ?? []), 0, 50) as $a) {
        $a = trim((string)$a);
        if (!preg_match('/^\d{1,10}$/', $a)) {
            return ['hata' => 'Geçersiz detay anahtarı.'];
        }
        $anahtarlar[$a] = $a;
    }
    if (!$anahtarlar && empty(DETAY_TURLERI[$tur]['anahtarsiz'])) {
        return ['hata' => 'Detay anahtarı eksik.'];
    }
    // Satır firması: yoksa / negatifse koşul yok (raporun firma filtresi yine uygulanır)
    $sf = isset($g['satir_firma_id']) && $g['satir_firma_id'] !== '' ? (int)$g['satir_firma_id'] : -1;

    return ['tur' => $tur, 'anahtarlar' => array_values($anahtarlar), 'satirFirma' => $sf >= 0 ? $sf : null];
}

/**
 * Detay türünün sorgu parçaları:
 *   taban    FROM + WHERE (kaynak fonksiyonuyla aynı koşul + kırılım anahtarı + satır firması)
 *   params   tabandaki ? değerleri
 *   id       satır kimliği (CTE yalnız bunu döner)
 *   ozet     COUNT / SUM ifadeleri (kırılım kartındaki adet / tutar ile aynı tanım)
 *   ara      arama koşulu (her ? aynı LIKE değeriyle doldurulur)
 *   siralama DataTables kolon index → SQL ifadesi (whitelist; olmayan index → 0)
 *   dis      dış SELECT: CTE (sayfa x) satırlarına detay kolonları; takma adlar tabandakiyle aynı
 */
function detayTanim(array $d, array $f)
{
    $tur = $d['tur'];
    $p   = [$f['bas'], $f['bit']];

    switch ($tur) {
        case 'satis':
            return [
                'taban'  => pazaryeriKosulu($f['firmaIdler'])
                          . anahtarIdKosulu($d['anahtarlar'], 's.PazaryeriSiparisleri_Pazaryerleri_id')
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id'),
                'params' => $p,
                'id'     => 's.PazaryeriSiparisleri_id',
                'ozet'   => 'COUNT(*) AS adet, ISNULL(SUM(ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0)), 0) AS toplam',
                'ara'    => 's.PazaryeriSiparisleri_SiparisNo LIKE ? OR s.PazaryeriSiparisleri_MusteriAdi LIKE ?
                             OR EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisKalemleri ca
                                        WHERE ca.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                                          AND ca.PazaryeriSiparisKalemleri_Durum = 1
                                          AND (ca.PazaryeriSiparisKalemleri_UrunAdi LIKE ? OR ca.PazaryeriSiparisKalemleri_Barkod LIKE ?))',
                'siralama' => [
                    0 => 's.PazaryeriSiparisleri_SiparisTarihi',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 'p.Pazaryerleri_Ad',
                    3 => 'k.EntegrasyonKanallari_Ad',
                    4 => FIRMA_AD_SQL,
                    5 => 's.PazaryeriSiparisleri_MusteriAdi',
                    7 => 's.PazaryeriSiparisleri_DurumKod',
                    10 => 's.PazaryeriSiparisleri_ToplamTutar',
                ],
                // Kalem sayısı, komisyon ve ürün adları yalnız bu sayfanın satırlarına (ağır alt sorgu CTE'de yok)
                'dis' => "
                    SELECT s.PazaryeriSiparisleri_id                                  AS id,
                           CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS tarih,
                           s.PazaryeriSiparisleri_SiparisNo                           AS siparis_no,
                           p.Pazaryerleri_Ad                                          AS pazaryeri,
                           k.EntegrasyonKanallari_Ad                                  AS kanal,
                           " . FIRMA_AD_SQL . "                                       AS firma,
                           s.PazaryeriSiparisleri_MusteriAdi                          AS musteri,
                           s.PazaryeriSiparisleri_DurumKod                            AS durum,
                           kl.adet                                                    AS kalem,
                           ur.urunler                                                 AS urunler,
                           kl.komisyon                                                AS komisyon,
                           ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0)              AS tutar
                    FROM sayfa x
                    INNER JOIN dbo.PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = x.id
                    INNER JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    INNER JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f              ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    -- Komisyon (KDV dahil): hiçbir kalemde gelmemişse NULL kalır (teslim sonrası dolar), arayüzde tire gösterilir
                    " . KOMISYON_KDV_APPLY . "
                    OUTER APPLY (
                        SELECT COUNT(*) AS adet,
                               -- Çarpan SUM dışında: dış referans (kkdv) toplama ifadesine giremez (Msg 8124)
                               SUM(c.PazaryeriSiparisKalemleri_KomisyonTutar) * " . KOMISYON_KDV_CARPAN . " AS komisyon
                        FROM dbo.PazaryeriSiparisKalemleri c
                        WHERE c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                          AND c.PazaryeriSiparisKalemleri_Durum = 1
                    ) kl
                    -- Ürün adları (miktar ile): kalemler ' ¦ ' ile birleşir, arayüz satır satır gösterir
                    OUTER APPLY (
                        SELECT STUFF((
                            SELECT N' ¦ ' + CAST(c.PazaryeriSiparisKalemleri_Miktar AS NVARCHAR(10)) + N' x '
                                 + ISNULL(NULLIF(c.PazaryeriSiparisKalemleri_UrunAdi, N''), c.PazaryeriSiparisKalemleri_Barkod)
                            FROM dbo.PazaryeriSiparisKalemleri c
                            WHERE c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                              AND c.PazaryeriSiparisKalemleri_Durum = 1
                            ORDER BY c.PazaryeriSiparisKalemleri_id
                            FOR XML PATH(''), TYPE
                        ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS urunler
                    ) ur",
                'bicim' => fn($r) => [
                    'tarih'      => (string)$r['tarih'],
                    'siparis_no' => (string)($r['siparis_no'] ?? ''),
                    'pazaryeri'  => (string)($r['pazaryeri'] ?? ''),
                    'kanal'      => (string)($r['kanal'] ?? ''),
                    'firma'      => (string)($r['firma'] ?? ''),
                    'musteri'    => (string)($r['musteri'] ?? ''),
                    'durum'      => (string)($r['durum'] ?? ''),
                    'kalem'      => (int)$r['kalem'],
                    'urunler'    => (string)($r['urunler'] ?? ''),
                    'komisyon'   => $r['komisyon'] !== null ? round((float)$r['komisyon'], 2) : null,
                    'komisyon_yuzde' => yuzdeOran($r['komisyon'], $r['tutar']),
                    'tutar'      => round((float)$r['tutar'], 2),
                ],
            ];

        // Kalem seviyesi: adet = sipariş sayısı (kırılımdaki COUNT DISTINCT ile aynı), kalem ayrıca verilir
        case 'komisyon_pazaryeri':
            return [
                'taban'  => pazaryeriKosulu($f['firmaIdler'], KOMISYON_KALEM_JOIN)
                          . anahtarIdKosulu($d['anahtarlar'], 's.PazaryeriSiparisleri_Pazaryerleri_id')
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id'),
                'params' => $p,
                'id'     => 'c.PazaryeriSiparisKalemleri_id',
                'ozet'   => 'COUNT(DISTINCT s.PazaryeriSiparisleri_id) AS adet, COUNT(*) AS kalem,
                             SUM(CASE WHEN c.PazaryeriSiparisKalemleri_KomisyonTutar IS NULL THEN 1 ELSE 0 END) AS bos,
                             ISNULL(SUM(ISNULL(' . KOMISYON_KDV_DAHIL . ', 0)), 0) AS toplam,
                             ISNULL(SUM(CASE WHEN NOT ' . KOMISYONSUZ_KOSUL . ' THEN ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0) ELSE 0 END), 0) AS baz',
                'ara'    => 's.PazaryeriSiparisleri_SiparisNo LIKE ? OR c.PazaryeriSiparisKalemleri_UrunAdi LIKE ? OR c.PazaryeriSiparisKalemleri_Barkod LIKE ?',
                'siralama' => [
                    0 => 's.PazaryeriSiparisleri_SiparisTarihi',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 'p.Pazaryerleri_Ad',
                    3 => FIRMA_AD_SQL,
                    4 => 'c.PazaryeriSiparisKalemleri_UrunAdi',
                    5 => 'c.PazaryeriSiparisKalemleri_Barkod',
                    6 => 'c.PazaryeriSiparisKalemleri_Miktar',
                    7 => 'c.PazaryeriSiparisKalemleri_ToplamTutar',
                    8 => 'c.PazaryeriSiparisKalemleri_KomisyonOran',
                    11 => KOMISYON_KDV_DAHIL,
                ],
                'dis' => "
                    SELECT c.PazaryeriSiparisKalemleri_id                             AS id,
                           CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS tarih,
                           s.PazaryeriSiparisleri_SiparisNo                           AS siparis_no,
                           p.Pazaryerleri_Ad                                          AS pazaryeri,
                           " . FIRMA_AD_SQL . "                                       AS firma,
                           c.PazaryeriSiparisKalemleri_UrunAdi                        AS urun,
                           c.PazaryeriSiparisKalemleri_Barkod                         AS barkod,
                           c.PazaryeriSiparisKalemleri_Miktar                         AS miktar,
                           c.PazaryeriSiparisKalemleri_ToplamTutar                    AS kalem_tutar,
                           c.PazaryeriSiparisKalemleri_KomisyonOran                   AS komisyon_oran,
                           c.PazaryeriSiparisKalemleri_KomisyonTutar                  AS komisyon_kdvsiz,
                           kkdv.oran                                                  AS komisyon_kdv_oran,
                           " . KOMISYON_KDV_DAHIL . "                                 AS komisyon_tutar
                    FROM sayfa x
                    INNER JOIN dbo.PazaryeriSiparisKalemleri c ON c.PazaryeriSiparisKalemleri_id = x.id
                    INNER JOIN dbo.PazaryeriSiparisleri s      ON s.PazaryeriSiparisleri_id = c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id
                    INNER JOIN dbo.EntegrasyonKanallari k      ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    INNER JOIN dbo.tanim_Pazaryerleri p        ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f                   ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    " . KOMISYON_KDV_APPLY,
                // Komisyonu henüz gelmemiş kalem (teslim sonrası dolar): NULL korunur, arayüzde "—"
                'bicim' => fn($r) => [
                    'tarih'          => (string)$r['tarih'],
                    'siparis_no'     => (string)($r['siparis_no'] ?? ''),
                    'pazaryeri'      => (string)($r['pazaryeri'] ?? ''),
                    'firma'          => (string)($r['firma'] ?? ''),
                    'urun'           => (string)($r['urun'] ?? ''),
                    'barkod'         => (string)($r['barkod'] ?? ''),
                    'miktar'         => (int)$r['miktar'],
                    'kalem_tutar'    => round((float)$r['kalem_tutar'], 2),
                    'komisyon_oran'  => $r['komisyon_oran'] !== null ? round((float)$r['komisyon_oran'], 2) : null,
                    'komisyon_kdvsiz'   => $r['komisyon_kdvsiz'] !== null ? round((float)$r['komisyon_kdvsiz'], 2) : null,
                    'komisyon_kdv_oran' => $r['komisyon_kdv_oran'] !== null ? round((float)$r['komisyon_kdv_oran'], 2) : 0,
                    'komisyon_tutar'    => $r['komisyon_tutar'] !== null ? round((float)$r['komisyon_tutar'], 2) : null,
                    // Etkin oran: KDV dahil komisyon / kalem tutarı (API oranı KDV hariç olduğundan farklı olabilir)
                    'etkin_oran'        => yuzdeOran($r['komisyon_tutar'], $r['kalem_tutar']),
                ],
            ];

        // InfoBox detayı: komisyonu hesaplanmamış kalemler (komisyonsuzOzet ile aynı taban)
        case 'komisyonsuz':
            [$taban, $tp] = komisyonsuzTaban($f['bas'], $f['bit'], $f['firmaIdler'], $f['kirilim']);
            return [
                'taban'  => $taban
                          . ($d['anahtarlar'] ? anahtarIdKosulu($d['anahtarlar'], 's.PazaryeriSiparisleri_Pazaryerleri_id') : '')
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id'),
                'params' => $tp,
                'id'     => 'c.PazaryeriSiparisKalemleri_id',
                'ozet'   => 'COUNT(DISTINCT s.PazaryeriSiparisleri_id) AS adet, COUNT(*) AS kalem,
                             ISNULL(SUM(ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0)), 0) AS toplam',
                'ara'    => 's.PazaryeriSiparisleri_SiparisNo LIKE ? OR c.PazaryeriSiparisKalemleri_UrunAdi LIKE ? OR c.PazaryeriSiparisKalemleri_Barkod LIKE ?',
                'siralama' => [
                    0 => 's.PazaryeriSiparisleri_SiparisTarihi',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 'p.Pazaryerleri_Ad',
                    3 => FIRMA_AD_SQL,
                    4 => 'c.PazaryeriSiparisKalemleri_UrunAdi',
                    5 => 'c.PazaryeriSiparisKalemleri_Barkod',
                    6 => 'c.PazaryeriSiparisKalemleri_Miktar',
                    7 => 's.PazaryeriSiparisleri_DurumKod',
                    8 => 'c.PazaryeriSiparisKalemleri_ToplamTutar',
                ],
                'dis' => "
                    SELECT c.PazaryeriSiparisKalemleri_id                             AS id,
                           CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS tarih,
                           s.PazaryeriSiparisleri_SiparisNo                           AS siparis_no,
                           p.Pazaryerleri_Ad                                          AS pazaryeri,
                           " . FIRMA_AD_SQL . "                                       AS firma,
                           c.PazaryeriSiparisKalemleri_UrunAdi                        AS urun,
                           c.PazaryeriSiparisKalemleri_Barkod                         AS barkod,
                           c.PazaryeriSiparisKalemleri_Miktar                         AS miktar,
                           s.PazaryeriSiparisleri_DurumKod                            AS durum,
                           ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0)         AS kalem_tutar
                    FROM sayfa x
                    INNER JOIN dbo.PazaryeriSiparisKalemleri c ON c.PazaryeriSiparisKalemleri_id = x.id
                    INNER JOIN dbo.PazaryeriSiparisleri s      ON s.PazaryeriSiparisleri_id = c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id
                    INNER JOIN dbo.EntegrasyonKanallari k      ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    INNER JOIN dbo.tanim_Pazaryerleri p        ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f                   ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id",
                'bicim' => fn($r) => [
                    'tarih'       => (string)$r['tarih'],
                    'siparis_no'  => (string)($r['siparis_no'] ?? ''),
                    'pazaryeri'   => (string)($r['pazaryeri'] ?? ''),
                    'firma'       => (string)($r['firma'] ?? ''),
                    'urun'        => (string)($r['urun'] ?? ''),
                    'barkod'      => (string)($r['barkod'] ?? ''),
                    'miktar'      => (int)$r['miktar'],
                    'durum'       => (string)($r['durum'] ?? ''),
                    'kalem_tutar' => round((float)$r['kalem_tutar'], 2),
                ],
            ];
    }
    return ['hata' => 'Geçersiz detay türü.'];
}

/** Özet satırı (COUNT / SUM) → sayısal dizi */
function detayOzet($r)
{
    $o = [
        'adet'   => (int)($r['adet'] ?? 0),
        'toplam' => round((float)($r['toplam'] ?? 0), 2),
    ];
    if (isset($r['kalem'])) {
        $o['kalem'] = (int)$r['kalem'];
        $o['bos']   = (int)($r['bos'] ?? 0);
    }
    if (isset($r['baz'])) {
        $o['baz'] = round((float)$r['baz'], 2);
    }
    return $o;
}

/**
 * Server-side detay (iki aşamalı):
 *  1) Ucuz CTE: filtre + sıralama + OFFSET/FETCH → yalnız ID
 *  2) Dış SELECT: o sayfanın satırlarına detay kolonları / ağır alt sorgular
 * Sıralama kolonu whitelist'ten, yön ASC/DESC normalize. Sayım tek taban sorgusundan.
 */
function detayCalistir(array $t, array $istek, $enFazla = 500)
{
    $db = Database::getInstance();

    $ara    = mb_substr(trim((string)($istek['ara'] ?? '')), 0, 100, 'UTF-8');
    $araSql = '';
    $pAra   = $t['params'];
    if ($ara !== '') {
        $araSql = ' AND (' . $t['ara'] . ') ';
        $deger  = '%' . likeKacis($ara) . '%';
        for ($i = substr_count($t['ara'], '?'); $i > 0; $i--) {
            $pAra[] = $deger;
        }
    }

    $top  = $db->fetchOne("SELECT {$t['ozet']} {$t['taban']}", $t['params']);
    $filt = $ara !== '' ? $db->fetchOne("SELECT {$t['ozet']} {$t['taban']} $araSql", $pAra) : $top;

    $kolonNo = (int)($istek['order'][0]['column'] ?? 0);
    $kolon   = $t['siralama'][$kolonNo] ?? $t['siralama'][0];
    $yon     = strtolower((string)($istek['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $start   = max(0, (int)($istek['start'] ?? 0));
    $len     = (int)($istek['length'] ?? 25);
    if ($len < 1 || $len > $enFazla) {
        $len = 25;
    }

    $satirlar = $db->fetchAll("
        WITH sayfa AS (
            SELECT {$t['id']} AS id
            {$t['taban']} $araSql
            ORDER BY $kolon $yon, {$t['id']} $yon
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
        )
        {$t['dis']}
        ORDER BY $kolon $yon, {$t['id']} $yon
    ", array_merge($pAra, [$start, $len]));

    $ozetTop  = detayOzet($top);
    $kayitTop = $ozetTop['kalem'] ?? $ozetTop['adet'];       // komisyonda satır = kalem
    $ozetFilt = detayOzet($filt);
    $kayitFilt = $ozetFilt['kalem'] ?? $ozetFilt['adet'];

    return [
        'recordsTotal'    => $kayitTop,
        'recordsFiltered' => $kayitFilt,
        'ozet'            => $ozetTop,
        'ozet_filtre'     => $ara !== '' ? $ozetFilt : null,
        'data'            => array_map(fn($r) => ['id' => (int)$r['id']] + ($t['bicim'])($r), $satirlar),
    ];
}

/** Detay Excel'inde en fazla satır (tek istekte bellek sınırı) */
const DETAY_EXCEL_EN_FAZLA = 50000;

/**
 * Detay Excel kolonları: tür → [başlık, alan, hücre türü]. Sıra arayüzdeki DETAY_KOLONLARI ile aynı.
 * Alanlar bicim çıktısıdır. NULL değer boş hücre yazılır.
 */
const DETAY_EXCEL_KOLONLARI = [
    'satis' => [
        ['Sipariş Tarihi', 'tarih', 'metin'], ['Sipariş No', 'siparis_no', 'metin'], ['Pazaryeri', 'pazaryeri', 'metin'],
        ['Kanal', 'kanal', 'metin'], ['Firma', 'firma', 'metin'], ['Müşteri', 'musteri', 'metin'],
        ['Ürün', 'urunler', 'metin'], ['Durum', 'durum', 'metin'], ['Kalem', 'kalem', 'sayi'], ['Komisyon (KDV Dahil)', 'komisyon', 'para'],
        ['Tutar (KDV Dahil)', 'tutar', 'para'], ['Komisyon / Tutar (%)', 'komisyon_yuzde', 'sayi'],
    ],
    'komisyon_pazaryeri' => [
        ['Sipariş Tarihi', 'tarih', 'metin'], ['Sipariş No', 'siparis_no', 'metin'], ['Pazaryeri', 'pazaryeri', 'metin'],
        ['Firma', 'firma', 'metin'], ['Ürün Adı', 'urun', 'metin'], ['Barkod', 'barkod', 'metin'],
        ['Miktar', 'miktar', 'sayi'], ['Kalem Tutarı', 'kalem_tutar', 'para'],
        ['Komisyon Oranı (%)', 'komisyon_oran', 'sayi'], ['Komisyon (KDV Hariç)', 'komisyon_kdvsiz', 'para'],
        ['Komisyon KDV (%)', 'komisyon_kdv_oran', 'sayi'], ['Komisyon (KDV Dahil)', 'komisyon_tutar', 'para'],
        ['Komisyon / Tutar (%)', 'etkin_oran', 'sayi'],
    ],
    'komisyonsuz' => [
        ['Sipariş Tarihi', 'tarih', 'metin'], ['Sipariş No', 'siparis_no', 'metin'], ['Pazaryeri', 'pazaryeri', 'metin'],
        ['Firma', 'firma', 'metin'], ['Ürün Adı', 'urun', 'metin'], ['Barkod', 'barkod', 'metin'],
        ['Miktar', 'miktar', 'sayi'], ['Durum', 'durum', 'metin'], ['Kalem Tutarı', 'kalem_tutar', 'para'],
    ],
];

/** Detay satırı → Excel hücreleri */
function detayExcelSatir($tur, array $s)
{
    return array_map(fn($k) => ($s[$k[1]] ?? null) === null ? '' : ['deger' => $s[$k[1]], 'tur' => $k[2]], DETAY_EXCEL_KOLONLARI[$tur]);
}

/** Ayar modalı verisi: tüm kaynak tanımları */
function ayarVerisi()
{
    $db = Database::getInstance();
    return [
        'kaynaklar' => $db->fetchAll("
            SELECT GelirGiderKaynaklari_id AS id, GelirGiderKaynaklari_Kod AS kod, GelirGiderKaynaklari_Ad AS ad,
                   GelirGiderKaynaklari_Tip AS tip, GelirGiderKaynaklari_Aciklama AS aciklama,
                   GelirGiderKaynaklari_Renk AS renk, GelirGiderKaynaklari_Ikon AS ikon,
                   GelirGiderKaynaklari_Sira AS sira, CAST(GelirGiderKaynaklari_Durum AS INT) AS durum
            FROM dbo.tanim_GelirGiderKaynaklari
            ORDER BY CASE GelirGiderKaynaklari_Tip WHEN 'gelir' THEN 0 ELSE 1 END,
                     GelirGiderKaynaklari_Sira, GelirGiderKaynaklari_id
        "),
    ];
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    // Ayar işlemleri: yalnız ayar yetkisi olan kullanıcı (buton gizli olsa da sunucuda da denetlenir)
    $ayarAksiyonlari = ['ayar_liste', 'kaynak_kaydet', 'kaynak_durum'];
    if (in_array($action, $ayarAksiyonlari, true) && !$ayarYetki) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Ayarları değiştirme yetkiniz yok.'], $jsonBayrak);
        exit;
    }
    $simdi = date('Y-m-d H:i:s');

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // Ayar modalı: kaynaklar
            case 'ayar_liste':
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'data' => ayarVerisi()], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kaynak tanımı: Kod ve Tip değişmez (sorgu fonksiyonuna bağlı), yeni kaynak eklenmez
            case 'kaynak_kaydet':
                header('Content-Type: application/json; charset=utf-8');
                $id       = (int)($_POST['id'] ?? 0);
                $ad       = trim((string)($_POST['ad'] ?? ''));
                $renk     = trim((string)($_POST['renk'] ?? ''));
                $ikon     = trim((string)($_POST['ikon'] ?? ''));
                $sira     = (int)($_POST['sira'] ?? 0);
                $aciklama = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 300, 'UTF-8');

                $hata = null;
                if ($ad === '' || mb_strlen($ad, 'UTF-8') > 100) {
                    $hata = 'Ad zorunludur (en fazla 100 karakter).';
                } elseif (strlen($renk) > 20 || !preg_match(RENK_DESENI, $renk)) {
                    $hata = 'Renk #hex veya renk adı olmalı.';
                } elseif (!preg_match(IKON_DESENI, $ikon)) {
                    $hata = 'İkon yalnız harf, rakam, boşluk ve tire içerebilir (ör. bi bi-truck).';
                } elseif ($sira < 0 || $sira > 9999) {
                    $hata = 'Sıra 0 ile 9999 arasında olmalı.';
                } elseif (!$db->fetchOne("SELECT GelirGiderKaynaklari_id FROM dbo.tanim_GelirGiderKaynaklari WHERE GelirGiderKaynaklari_id = ?", [$id])) {
                    $hata = 'Kaynak bulunamadı.';
                }
                if ($hata) {
                    echo json_encode(['success' => false, 'message' => $hata], $jsonBayrak);
                    exit;
                }
                $ok = $db->execute("
                    UPDATE dbo.tanim_GelirGiderKaynaklari
                    SET GelirGiderKaynaklari_Ad = ?, GelirGiderKaynaklari_Renk = ?, GelirGiderKaynaklari_Ikon = ?,
                        GelirGiderKaynaklari_Sira = ?, GelirGiderKaynaklari_Aciklama = ?,
                        GelirGiderKaynaklari_GuncelleyenKullanici = ?, GelirGiderKaynaklari_GuncellemeTarihi = ?
                    WHERE GelirGiderKaynaklari_id = ?
                ", [$ad, $renk, $ikon, $sira, $aciklama !== '' ? $aciklama : null, (int)$user['kullanici_id'], $simdi, $id]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kaynak güncellendi.']
                                     : ['success' => false, 'message' => 'Kaynak kaydedilemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kaynak_durum':
                header('Content-Type: application/json; charset=utf-8');
                $ok = $db->execute("
                    UPDATE dbo.tanim_GelirGiderKaynaklari
                    SET GelirGiderKaynaklari_Durum = ?, GelirGiderKaynaklari_GuncelleyenKullanici = ?, GelirGiderKaynaklari_GuncellemeTarihi = ?
                    WHERE GelirGiderKaynaklari_id = ?
                ", [!empty($_POST['durum']) ? 1 : 0, (int)$user['kullanici_id'], $simdi, (int)($_POST['id'] ?? 0)]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kaynak durumu güncellendi.']
                                     : ['success' => false, 'message' => 'Durum güncellenemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kırılım detayı (server-side DataTables). Sayfanın görüntüleme yetkisi yeterli;
            // firma kısıtı filtreCoz ile uygulanır. tur: satis | komisyon_pazaryeri.
            // Sorgu: detayCalistir (iki aşamalı).
            case 'detay':
                header('Content-Type: application/json; charset=utf-8');
                $draw = (int)($_POST['draw'] ?? 0);
                $f = filtreCoz($_POST, $kisitFirmaId);
                $d = isset($f['hata']) ? $f : detayGirdisi($_POST);
                $t = isset($d['hata']) ? $d : detayTanim($d, $f);
                if (isset($t['hata'])) {
                    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $t['hata']], $jsonBayrak);
                    exit;
                }
                echo json_encode(['draw' => $draw] + detayCalistir($t, $_POST), $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // InfoBox + grafik + aylık tablo + kırılımlar tek çağrıda
            case 'rapor':
                header('Content-Type: application/json; charset=utf-8');
                $f = filtreCoz($_POST, $kisitFirmaId);
                if (isset($f['hata'])) {
                    echo json_encode(['success' => false, 'message' => $f['hata']], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => raporHesapla($f['bas'], $f['bit'], $f['firmaIdler'], $f['kirilim'])], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // InfoBox: komisyonu hesaplanmamış ciro (rapordan sonra ayrı çağrı)
            case 'komisyonsuz':
                header('Content-Type: application/json; charset=utf-8');
                $f = filtreCoz($_POST, $kisitFirmaId);
                if (isset($f['hata'])) {
                    echo json_encode(['success' => false, 'message' => $f['hata']], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => komisyonsuzOzet($f['bas'], $f['bit'], $f['firmaIdler'], $f['kirilim'])], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Excel: aylık tablo + altında kaynak kırılımları (tek sayfa)
            //   bolum: '' → ikisi birden · 'aylik' → yalnız aylık döküm · 'kirilim' → yalnız 'kaynak' kodunun kırılım kartı
            case 'excel':
                $f = filtreCoz($_POST, $kisitFirmaId);
                $bolum = (string)($_POST['bolum'] ?? '');
                if (!isset($f['hata']) && !in_array($bolum, ['', 'aylik', 'kirilim'], true)) {
                    $f = ['hata' => 'Geçersiz Excel bölümü.'];
                }
                if (isset($f['hata'])) {
                    http_response_code(400);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $f['hata'];
                    exit;
                }

                require_once __DIR__ . '/../includes/XlsxYazici.php';
                $r = raporHesapla($f['bas'], $f['bit'], $f['firmaIdler'], $f['kirilim']);
                $destekli = array_values(array_filter($r['kaynaklar'], fn($k) => $k['destekli']));

                // Kırılım seçiliyse yalnız seçili kırılımı olan kaynak kolonları (sayfadaki görünümle aynı)
                $secili = $r['kirilimSecili'];
                if ($secili) {
                    $seciliKod = array_flip(array_map(fn($s) => explode('|', $s, 2)[0], $secili));
                    $destekli  = array_values(array_filter($destekli, fn($k) => isset($seciliKod[$k['kod']])));
                    $kirilimAd = [];
                    foreach ($r['kirilimlar'] as $s) {
                        $kirilimAd[$s['kaynak_kod'] . '|' . $s['alt_kirilim']] = $s['kaynak_ad'] . ': ' . $s['alt_kirilim'];
                    }
                    $kirilimMetin = count($secili) > 5
                        ? count($secili) . ' kırılım'
                        : implode(', ', array_map(fn($s) => $kirilimAd[$s] ?? $s, $secili));
                }

                // Tek kırılım kartı: yalnız o kaynak (görünür kaynaklar arasında olmalı)
                $kirilimKaynaklari = $bolum === 'aylik' ? [] : $destekli;
                if ($bolum === 'kirilim') {
                    $kod = (string)($_POST['kaynak'] ?? '');
                    $kirilimKaynaklari = array_values(array_filter($destekli, fn($k) => $k['kod'] === $kod));
                    if (!$kirilimKaynaklari) {
                        http_response_code(400);
                        header('Content-Type: text/plain; charset=utf-8');
                        echo 'Kaynak bulunamadı.';
                        exit;
                    }
                }

                // basliklar() satırı en üste alır; not ve filtre bilgisi en üstte kalsın diye başlık satırı satir() ile yazılır
                $xlsx = new XlsxYazici($bolum === 'kirilim' ? $kirilimKaynaklari[0]['ad'] : 'Gelir ve Gider');
                $xlsx->satir([RAPOR_NOTU]);
                $xlsx->satir(['Dönem: ' . $f['bas'] . ' – ' . $f['bit']
                    . ' · Kırılım: ' . ($secili ? $kirilimMetin : 'Tümü')]);

                $kaynakTop = (array)$r['kaynakTop'];

                if ($bolum !== 'kirilim') {
                    $xlsx->satir(array_map(fn($b) => ['deger' => $b, 'tur' => 'baslik'], array_merge(
                        ['Ay'],
                        array_map(fn($k) => $k['ad'] . ' (' . ($k['tip'] === 'gelir' ? 'Gelir' : 'Gider') . ')', $destekli),
                        ['Toplam Gelir', 'Toplam Gider', 'Net']
                    )));

                    foreach ($r['aylar'] as $a) {
                        $kaynakTutar = (array)$a['kaynaklar'];
                        $xlsx->satir(array_merge(
                            [$a['ay']],
                            array_map(fn($k) => ['deger' => $kaynakTutar[$k['kod']] ?? 0, 'tur' => 'para'], $destekli),
                            [
                                ['deger' => $a['gelir'], 'tur' => 'para'],
                                ['deger' => $a['gider'], 'tur' => 'para'],
                                ['deger' => $a['net'],   'tur' => 'para'],
                            ]
                        ));
                    }
                    $xlsx->satir(array_merge(
                        [['deger' => 'GENEL TOPLAM', 'tur' => 'baslik']],
                        array_map(fn($k) => ['deger' => $kaynakTop[$k['kod']]['tutar'] ?? 0, 'tur' => 'para'], $destekli),
                        [
                            ['deger' => $r['ozet']['gelir'], 'tur' => 'para'],
                            ['deger' => $r['ozet']['gider'], 'tur' => 'para'],
                            ['deger' => $r['ozet']['net'],   'tur' => 'para'],
                        ]
                    ));
                }

                // Kırılımlar
                if ($kirilimKaynaklari) {
                    $kirilim = (array)$r['kirilim'];
                    if ($bolum === '') {
                        $xlsx->satir(['']);
                    }
                    $xlsx->satir([
                        ['deger' => 'Kaynak', 'tur' => 'baslik'],
                        ['deger' => 'Tip', 'tur' => 'baslik'],
                        ['deger' => 'Alt Kırılım', 'tur' => 'baslik'],
                        ['deger' => 'Firma', 'tur' => 'baslik'],
                        ['deger' => 'Adet', 'tur' => 'baslik'],
                        ['deger' => 'Tutar', 'tur' => 'baslik'],
                        ['deger' => 'Komisyon Hariç', 'tur' => 'baslik'],
                        ['deger' => 'Komisyon / Tutar (%)', 'tur' => 'baslik'],
                    ]);
                    // Oran yalnız paydası (baz) olan kaynakta (komisyon); boşsa hücre boş
                    $oranHucre = fn($x) => ($o = yuzdeOran($x['tutar'] ?? null, $x['baz'] ?? 0)) !== null && ($x['baz'] ?? null) !== null
                        ? ['deger' => $o, 'tur' => 'sayi'] : '';
                    // Komisyon hariç yalnız komisyonu taşıyan kaynakta (satış); boşsa hücre boş
                    $komHaricHucre = fn($x) => ($x['komisyon'] ?? null) !== null
                        ? ['deger' => round($x['tutar'] - $x['komisyon'], 2), 'tur' => 'para'] : '';
                    foreach ($kirilimKaynaklari as $k) {
                        foreach ($kirilim[$k['kod']] ?? [] as $s) {
                            $xlsx->satir([
                                $k['ad'],
                                $k['tip'] === 'gelir' ? 'Gelir' : 'Gider',
                                $s['alt_kirilim'],
                                $s['firma_adi'],
                                ['deger' => $s['adet'], 'tur' => 'sayi'],
                                ['deger' => round($s['tutar'], 2), 'tur' => 'para'],
                                $komHaricHucre($s),
                                $oranHucre($s),
                            ]);
                        }
                    }
                    // Tek kart: karttaki toplam satırı
                    if ($bolum === 'kirilim') {
                        $t = $kaynakTop[$kirilimKaynaklari[0]['kod']] ?? ['adet' => 0, 'tutar' => 0];
                        $xlsx->satir([
                            ['deger' => 'TOPLAM', 'tur' => 'baslik'], '', '', '',
                            ['deger' => $t['adet'], 'tur' => 'sayi'],
                            ['deger' => round($t['tutar'], 2), 'tur' => 'para'],
                            $komHaricHucre($t),
                            $oranHucre($t),
                        ]);
                    }
                }

                $dosyaEk = $bolum === 'aylik' ? '-aylik' : ($bolum === 'kirilim' ? '-' . $kirilimKaynaklari[0]['kod'] : '');
                $xlsx->indir('gelir-gider' . $dosyaEk . '-' . $f['bas'] . '_' . $f['bit'] . '.xlsx');
                exit;

            // ---------------------------------------------------------
            // Detay Excel: kırılım detay modalındaki liste (arama + sıralama dahil, sayfalamasız).
            // Firma kısıtı filtreCoz ile uygulanır (detay action'ıyla aynı).
            // Kaynak toplamı birden fazla türse 'parcalar' (JSON: [{tur, anahtarlar, satir_firma_id}])
            // ile her tür ayrı bölüm yazılır; arama + sıralama yalnız açık sekmeye ('tur') uygulanır.
            case 'detay_excel':
                $f = filtreCoz($_POST, $kisitFirmaId);
                $girdiler = isset($_POST['parcalar']) ? json_decode((string)$_POST['parcalar'], true) : [$_POST];
                if (!isset($f['hata']) && (!is_array($girdiler) || !$girdiler || count($girdiler) > count(DETAY_TURLERI))) {
                    $f = ['hata' => 'Geçersiz detay isteği.'];
                }
                $parcalar = [];
                foreach (isset($f['hata']) ? [] : $girdiler as $g) {
                    $d = is_array($g) ? detayGirdisi($g) : ['hata' => 'Geçersiz detay isteği.'];
                    $t = isset($d['hata']) ? $d : detayTanim($d, $f);
                    if (isset($t['hata'])) {
                        $f = $t;
                        break;
                    }
                    $parcalar[] = ['d' => $d, 't' => $t];
                }
                if (isset($f['hata'])) {
                    http_response_code(400);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $f['hata'];
                    exit;
                }

                require_once __DIR__ . '/../includes/XlsxYazici.php';
                $baslik   = mb_substr(trim((string)($_POST['baslik'] ?? '')), 0, 300, 'UTF-8');
                $ara      = mb_substr(trim((string)($_POST['ara'] ?? '')), 0, 100, 'UTF-8');
                $aktifTur = (string)($_POST['tur'] ?? '');
                $cokParca = count($parcalar) > 1;

                $xlsx = new XlsxYazici('Detay');
                $xlsx->satir([RAPOR_NOTU]);
                $xlsx->satir([$baslik . (!$cokParca && $ara !== '' ? ' · Arama: ' . $ara : '')]);

                foreach ($parcalar as ['d' => $d, 't' => $t]) {
                    $aktif = !$cokParca || $d['tur'] === $aktifTur;
                    $istek = ['start' => 0, 'length' => DETAY_EXCEL_EN_FAZLA] + ($aktif ? $_POST : []);
                    $sonuc = detayCalistir($t, $istek, DETAY_EXCEL_EN_FAZLA);

                    if ($cokParca) {
                        $xlsx->satir(['']);
                        $xlsx->satir([['deger' => DETAY_TURLERI[$d['tur']]['ad'] . ' · '
                            . number_format($sonuc['recordsFiltered'], 0, ',', '.') . ' kayıt · Toplam: '
                            . number_format(($sonuc['ozet_filtre'] ?? $sonuc['ozet'])['toplam'], 2, ',', '.') . ' ₺'
                            . ($aktif && $ara !== '' ? ' · Arama: ' . $ara : ''), 'tur' => 'baslik']]);
                    }
                    if ($sonuc['recordsFiltered'] > DETAY_EXCEL_EN_FAZLA) {
                        $xlsx->satir(['Not: ' . number_format($sonuc['recordsFiltered'], 0, ',', '.') . ' kaydın ilk '
                            . number_format(DETAY_EXCEL_EN_FAZLA, 0, ',', '.') . ' tanesi yazıldı; aralığı daraltın.']);
                    }
                    $xlsx->satir(array_map(fn($k) => ['deger' => $k[0], 'tur' => 'baslik'], DETAY_EXCEL_KOLONLARI[$d['tur']]));
                    foreach ($sonuc['data'] as $satir) {
                        $xlsx->satir(detayExcelSatir($d['tur'], $satir));
                    }
                }

                $xlsx->indir('gelir-gider-detay-' . implode('-', array_map(fn($p) => $p['d']['tur'], $parcalar))
                    . '-' . $f['bas'] . '_' . $f['bit'] . '.xlsx');
                exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Geçersiz istek.'], $jsonBayrak);
        exit;

    } catch (Throwable $e) {
        error_log('gelir-gider-raporu.php: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], $jsonBayrak);
        exit;
    }
}

$firmalar       = firmaListesi($kisitFirmaId);
$tutarTanimlari = tutarTanimlari();
$tanimUyariVar  = (bool)array_filter($tutarTanimlari, fn($t) => $t['uyari'] !== '');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .filtre-basligi { cursor: pointer; }
        .tutar { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .grafik-kutu { position: relative; height: 340px; }
        #tblAylik th, #tblAylik td { white-space: nowrap; vertical-align: middle; }
        #tblAylik thead th.kaynak-baslik { color: #fff; }
        #tblAylik tfoot td { font-weight: 700; border-top: 2px solid #adb5bd; }
        .kirilim-tablo td, .kirilim-tablo th { font-size: .85rem; }
        .kaynak-ikon { width: 2rem; height: 2rem; border-radius: .5rem; display: inline-flex;
                       align-items: center; justify-content: center; color: #fff; }
        .kirilim-tablo tbody tr.kirilim-satir { cursor: pointer; }
        .kirilim-tablo tbody tr.kirilim-satir td:first-child span { border-bottom: 1px dashed currentColor; }
        /* Kaynak toplamı (kart altı / aylık Genel Toplam) → kaynağın tüm kırılımlarının detayı */
        .kaynak-toplam { cursor: pointer; }
        .kaynak-toplam .toplam-tutar { border-bottom: 1px dashed currentColor; }
        #tblKaynaklar td { vertical-align: middle; }
        #tblKaynaklar input[type=color] { width: 3rem; padding: .15rem; }
        #tblDetay td { vertical-align: middle; }
        #tblDetay td.aciklama-hucre { white-space: normal; min-width: 260px; }
        /* Komisyonu henüz gelmemiş kalem (teslim sonrası dolar) */
        #tblDetay tr.satir-soluk td { opacity: .5; }
        /* InfoBox → detay modalı */
        .ib-detay { cursor: pointer; transition: box-shadow .15s; }
        .ib-detay:hover { box-shadow: 0 0 0 .2rem rgba(13,110,253,.25); }
        /* Geniş modallar ekrana sığsın: genişlik görüntü alanına göre, taşan tablo modal içinde kayar */
        .modal-genis { max-width: min(1600px, calc(100vw - 2rem)); margin-left: auto; margin-right: auto; }
        .modal-genis .modal-body { overflow-x: hidden; }
        .modal-genis .dataTables_wrapper, .modal-genis .table-responsive { max-width: 100%; }
        #detayModal .dataTables_wrapper .row { margin-left: 0; margin-right: 0; }
        #detayModal .dataTables_wrapper .row > * { padding-left: 0; padding-right: 0; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center gap-2">
                                <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                                <?php if ($ayarYetki): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAyarlar" title="Gelir / gider kaynak tanımları">
                                    <i class="bi bi-gear"></i> Ayarlar
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="info-box ib-detay" data-ib="gelir" title="Gelir detayını göster">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-arrow-down-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Gelir <small class="text-muted">· KDV dahil</small></span>
                                    <span class="info-box-number tutar" id="ibGelir">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="info-box ib-detay" data-ib="gider" title="Gider detayını göster">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-arrow-up-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Gider <small class="text-muted">· Komisyon, KDV dahil</small></span>
                                    <span class="info-box-number tutar" id="ibGider">-</span>
                                    <small class="text-muted" id="ibGiderAlt" title="Komisyon (KDV dahil) / komisyonu gelmiş kalemlerin tutarı: pazaryerlerinin fiilen kestiği ortalama oran"></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="info-box ib-detay" data-ib="net" title="Gelir ve gider detayını göster">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-wallet2"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" title="Ürün maliyeti, kargo, KDV ve stopaj düşülmez; kâr değildir">Net (Gelir − Komisyon) <small class="text-muted">· KDV dahil, maliyet hariç</small></span>
                                    <span class="info-box-number tutar" id="ibNet">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="info-box ib-detay" data-ib="net" title="Gelir ve gider detayını göster">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" title="Net / Gelir. Ürün maliyeti düşülmediği için kâr marjı değildir">Komisyon Sonrası Marj</span>
                                    <span class="info-box-number" id="ibMarj">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="info-box ib-detay" data-ib="komisyonsuz" title="Detay için tıklayın. Komisyon oranı ve tutarı boş / 0 olan kalemlerin tutarı (pazaryeri komisyonu henüz bildirmedi). Bu ciroya ait komisyon gidere girmedi.">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Komisyonu Hesaplanmamış Ciro <small class="text-muted">· KDV dahil</small></span>
                                    <span class="info-box-number tutar" id="ibKomisyonsuz">-</span>
                                    <small class="text-muted" id="ibKomisyonsuzAlt"></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Tutar tanımları: KDV / komisyon durumu pazaryerine göre -->
                    <div class="alert <?= $tanimUyariVar ? 'alert-warning' : 'alert-light border' ?> small py-2 mt-n2 mb-3">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi <?= $tanimUyariVar ? 'bi-exclamation-triangle' : 'bi-info-circle' ?> mt-1"></i>
                            <div class="flex-grow-1">
                                <?= htmlspecialchars(RAPOR_NOTU) ?>
                                <?php if ($tanimUyariVar): ?>
                                <b class="text-danger">Bazı pazaryerlerinde tutar sorunu var, ayrıntı için tanımlara bakın.</b>
                                <?php endif; ?>
                                <a class="ms-1 text-nowrap" data-bs-toggle="collapse" href="#tutarTanimlari" role="button" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i> Pazaryerine göre tutar tanımları
                                </a>
                            </div>
                        </div>
                        <div class="collapse" id="tutarTanimlari">
                            <div class="table-responsive mt-2">
                                <table class="table table-sm table-bordered bg-white mb-1 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Pazaryeri</th>
                                            <th>Gelir (sipariş tutarı, KDV dahil)</th>
                                            <th>Komisyon kaynağı</th>
                                            <th class="text-nowrap">Komisyona KDV</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tutarTanimlari as $tt): ?>
                                        <tr<?= $tt['uyari'] !== '' ? ' class="table-danger"' : '' ?>>
                                            <td class="fw-semibold text-nowrap"><?= htmlspecialchars($tt['ad']) ?></td>
                                            <td><?= htmlspecialchars($tt['gelir']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($tt['komisyon']) ?>
                                                <?php if ($tt['uyari'] !== ''): ?>
                                                <div class="text-danger fw-semibold"><i class="bi bi-exclamation-octagon me-1"></i><?= htmlspecialchars($tt['uyari']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <?= htmlspecialchars($tt['kdv']) ?>
                                                <?php if ($tt['kanalAyar']): ?>
                                                <div class="text-muted">+ <?= (int)$tt['kanalAyar'] ?> kanal ayarı</div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-muted">
                                Komisyon KDV oranı Pazaryeri Ayarları'ndaki <code>KomisyonKdvOran</code> ayarından okunur (kanal → pazaryeri → genel).
                                Komisyonu henüz gelmemiş siparişlerin cirosu gelire girer ama gideri yoktur; bu tutar "Komisyonu Hesaplanmamış Ciro" kutusunda gösterilir.
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card card-secondary card-outline mb-3">
                        <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                        </div>
                        <div class="collapse show" id="filtrePanel">
                            <div class="card-body">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Firma</label>
                                        <select class="form-select form-select-sm" id="fFirma">
                                            <?php if ($kisitFirmaId === null): ?>
                                            <option value="">Tüm firmalar</option>
                                            <?php endif; ?>
                                            <?php foreach ($firmalar as $f): ?>
                                            <option value="<?= (int)$f['firma_id'] ?>"><?= htmlspecialchars($f['firma_adi'] ?: $f['firma_unvan']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small mb-1" title="Seçenekler tarih ve firma filtresine göre rapordan gelir; seçiliyse yalnız seçilen kırılımlar toplanır">Kırılım</label>
                                        <select class="form-select form-select-sm" id="fKirilim" multiple></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="fBas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="fBit">
                                    </div>
                                    <div class="col-12 d-flex gap-2 justify-content-end">
                                        <button class="btn btn-sm btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                        <button class="btn btn-sm btn-outline-success" id="btnExcel"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                                    </div>
                                </div>
                                <div class="mt-2" id="atlananKutu"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Grafik -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bar-chart-line"></i> Aylık Gelir / Gider</h3>
                        </div>
                        <div class="card-body">
                            <div class="grafik-kutu"><canvas id="grafikAylik"></canvas></div>
                        </div>
                    </div>

                    <!-- Aylık tablo -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Aylık Döküm</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-success" id="btnExcelAylik" title="Aylık dökümü Excel'e aktar"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle w-100 mb-0" id="tblAylik">
                                    <thead></thead>
                                    <tbody><tr><td class="text-muted">Yükleniyor...</td></tr></tbody>
                                    <tfoot></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Kırılım kartları -->
                    <div class="row" id="kirilimKartlari"></div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Kırılım detayı: kolonlar detay türüne göre JS'te kurulur (DETAY_KOLONLARI) -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-genis modal-fullscreen-md-down modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-list-ul me-1" id="detayIkon"></i> <span id="detayBaslik"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <!-- Kaynak toplamı birden fazla detay türü içeriyorsa -->
                    <ul class="nav nav-pills mb-2 d-none" id="detaySekmeler"></ul>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="detayAra" id="detayAraEtiket">Ara</label>
                            <input type="text" class="form-control form-control-sm" id="detayAra" maxlength="100">
                        </div>
                        <div class="col-md-5 text-md-end small text-muted" id="detayKapsam"></div>
                        <div class="col-md-2 text-end">
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnDetayExcel" title="Listeyi arama ve sıralamasıyla Excel'e aktar"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                        </div>
                    </div>
                    <table class="table table-sm table-striped table-hover w-100" id="tblDetay"></table>
                </div>
                <div class="modal-footer justify-content-start small" id="detayOzet">-</div>
            </div>
        </div>
    </div>

    <?php if ($ayarYetki): ?>
    <!-- Ayarlar -->
    <div class="modal fade" id="ayarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-genis modal-fullscreen-md-down modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear me-1"></i> Gelir / Gider Ayarları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-2">
                        <i class="bi bi-info-circle me-1"></i> Kod ve tip sayfadaki sorgu fonksiyonuna bağlıdır, değiştirilemez.
                        Yeni kaynak türü geliştirme gerektirir.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="tblKaynaklar">
                            <thead>
                                <tr>
                                    <th>Kod</th>
                                    <th>Tip</th>
                                    <th>Ad</th>
                                    <th>Renk</th>
                                    <th>İkon</th>
                                    <th style="width:90px">Sıra</th>
                                    <th>Açıklama</th>
                                    <th class="text-center">Durum</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const paraBicim = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const para = (t) => paraBicim.format(Number(t) || 0);
        /** Komisyon / Tutar oranı metni; pay veya payda yok ya da payda 0 ise '—' */
        const oranYazi = (pay, payda) => (payda === null || payda === undefined || Number(payda) === 0 || pay === null || pay === undefined) ? '—'
            : '%' + (Number(pay) / Number(payda) * 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const ORAN_IPUCU = 'Komisyon (KDV dahil) / komisyonu gelmiş kalemlerin tutarı. Pazaryerinin fiilen kestiği etkin oran; sözleşme oranıyla karşılaştırın.';
        const ayAdi = (ay) => {
            const [y, m] = ay.split('-').map(Number);
            return new Date(y, m - 1, 1).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
        };
        const yerelTarih = (d) => {
            const p = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        };

        // custom.js tüm .form-select kutularına "Seçiniz..." yer tutuculu Select2 kurar; kendi etiketiyle yeniden kurulur
        [['#fFirma', 'Tüm firmalar']].forEach(([sec, etiket]) => {
            const $s = $(sec);
            if ($s.hasClass('select2-hidden-accessible')) { $s.select2('destroy'); }
            initSelect2(sec, { placeholder: etiket, minimumResultsForSearch: 0 });
        });

        // Kırılım: çoklu seçim; seçenekler her rapor yanıtında yenilenir (kirilimSecenekCiz)
        if ($('#fKirilim').hasClass('select2-hidden-accessible')) { $('#fKirilim').select2('destroy'); }
        initSelect2('#fKirilim', { placeholder: 'Tüm kırılımlar', closeOnSelect: false, maximumSelectionLength: 50,
                                   language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...',
                                               maximumSelected: () => 'En fazla 50 kırılım seçilebilir' } });

        /**
         * Seçenekler: kaynak → optgroup, değer 'kaynak_kod|alt_kirilim'.
         * Seçim sunucunun uyguladığı seçime eşitlenir (sonuçta olmayanlar düşer, rapor zaten onunla hesaplandı).
         * 'change.select2' yalnız görünümü günceller; raporYukle tetiklenmez.
         */
        function kirilimSecenekCiz(liste, secili) {
            const $s = $('#fKirilim').empty();
            let $grup = null, sonKod = null;
            (liste || []).forEach(o => {
                if (o.kaynak_kod !== sonKod) {
                    $grup = $('<optgroup>', { label: o.kaynak_ad }).appendTo($s);
                    sonKod = o.kaynak_kod;
                }
                $('<option>', { value: o.kaynak_kod + '|' + o.alt_kirilim, text: o.alt_kirilim }).appendTo($grup);
            });
            $s.val(secili || []).trigger('change.select2');
        }

        /** Kırılım seçiliyse yalnız seçili kırılımı olan kaynaklar (aylık tablo kolonları, kartlar) */
        function gorunenKaynaklar(d) {
            const kodlar = new Set((d.kirilimSecili || []).map(s => s.split('|')[0]));
            return d.kaynaklar.filter(k => k.destekli && (!kodlar.size || kodlar.has(k.kod)));
        }

        function varsayilanTarihler() {
            const bugun = new Date();
            $('#fBas').val(yerelTarih(bugun));
            $('#fBit').val(yerelTarih(bugun));
        }

        function filtreVerisi() {
            return {
                firma_id:  $('#fFirma').val() || '',
                kirilim:   $('#fKirilim').val() || [],
                bas_tarih: $('#fBas').val(),
                bit_tarih: $('#fBit').val()
            };
        }

        function filtreGecerli(f) {
            if (!f.bas_tarih || !f.bit_tarih) { showToast('Tarih aralığını seçin', 'warning'); return false; }
            if (f.bas_tarih > f.bit_tarih) { showToast('Başlangıç tarihi bitişten sonra olamaz', 'warning'); return false; }
            return true;
        }

        // ---------------------------------------------------------------
        let grafik = null;

        function grafikCiz(aylar) {
            const etiketler = aylar.map(a => ayAdi(a.ay));
            const veri = {
                labels: etiketler,
                datasets: [
                    { type: 'bar',  label: 'Gelir', data: aylar.map(a => a.gelir), backgroundColor: 'rgba(25,135,84,.75)', borderColor: '#198754', borderWidth: 1, order: 2 },
                    { type: 'bar',  label: 'Gider', data: aylar.map(a => a.gider), backgroundColor: 'rgba(220,53,69,.75)', borderColor: '#dc3545', borderWidth: 1, order: 2 },
                    { type: 'line', label: 'Net',   data: aylar.map(a => a.net),   borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: .3, pointRadius: 4, order: 1 }
                ]
            };
            if (grafik) {
                grafik.data = veri;
                grafik.update();
                return;
            }
            grafik = new Chart(document.getElementById('grafikAylik'), {
                data: veri,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + para(c.parsed.y) } },
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { ticks: { callback: (v) => Number(v).toLocaleString('tr-TR') + ' ₺' } }
                    }
                }
            });
        }

        // ---------------------------------------------------------------
        function tabloCiz(d) {
            const kaynaklar = gorunenKaynaklar(d);
            const top = d.kaynakTop || {};

            let bas = '<tr><th>Ay</th>';
            kaynaklar.forEach(k => {
                bas += '<th class="text-end kaynak-baslik" style="background:' + kacis(k.renk) + '" title="' + kacis(k.aciklama) + '">'
                     + '<i class="' + kacis(k.ikon) + ' me-1"></i>' + kacis(k.ad)
                     + '<br><small class="fw-normal opacity-75">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</small></th>';
            });
            bas += '<th class="text-end">Toplam Gelir</th><th class="text-end">Toplam Gider</th><th class="text-end">Net</th></tr>';
            $('#tblAylik thead').html(bas);

            const netHucre = (n) => '<td class="text-end tutar fw-semibold ' + (n < 0 ? 'text-danger' : 'text-success') + '">' + para(n) + '</td>';

            if (!d.aylar.length) {
                $('#tblAylik tbody').html('<tr><td colspan="' + (kaynaklar.length + 4) + '" class="text-center text-muted">Kayıt yok</td></tr>');
                $('#tblAylik tfoot').empty();
                return;
            }

            $('#tblAylik tbody').html(d.aylar.map(a => {
                let s = '<tr><td>' + kacis(ayAdi(a.ay)) + '</td>';
                kaynaklar.forEach(k => { s += '<td class="text-end tutar">' + para((a.kaynaklar || {})[k.kod] || 0) + '</td>'; });
                s += '<td class="text-end tutar">' + para(a.gelir) + '</td>'
                   + '<td class="text-end tutar">' + para(a.gider) + '</td>'
                   + netHucre(a.net) + '</tr>';
                return s;
            }).join(''));

            let alt = '<tr><td>Genel Toplam</td>';
            // data-kod: kirilimCiz detayı olan kaynakların hücresini tıklanabilir yapar
            kaynaklar.forEach(k => { alt += '<td class="text-end tutar" data-kod="' + kacis(k.kod) + '"><span class="toplam-tutar">'
                                          + para((top[k.kod] || {}).tutar || 0) + '</span></td>'; });
            alt += '<td class="text-end tutar">' + para(d.ozet.gelir) + '</td>'
                 + '<td class="text-end tutar">' + para(d.ozet.gider) + '</td>'
                 + netHucre(d.ozet.net) + '</tr>';
            $('#tblAylik tfoot').html(alt);
        }

        // ---------------------------------------------------------------
        // Kırılım satırları → detay modalı (index ile bu listeden okunur; filtre rapor anındaki haliyle saklanır)
        let detayKayitlari = [];
        let kaynakToplamlari = {};   // kod → kaynak toplamı detayı (türe göre parçalar)
        let ibFiltre = null;         // InfoBox detayı: ekrandaki raporun filtresi (firma_ad dahil)
        let ibKomisyonsuz = null;    // InfoBox detayı: komisyonsuz özet {siparis, kalem, tutar}

        /**
         * Kaynağın tüm kırılım satırları → detay türüne göre parçalar (anahtarlar birleşir, satır firması yok:
         * raporun firma filtresi ve kırılım seçimi zaten satırlara yansımış). adet / tutar
         * detay özetinin kartla karşılaştırması için satırlardan toplanır.
         */
        function kaynakParcalari(satirlar) {
            const parcalar = {};
            satirlar.forEach(s => {
                if (!DETAY_KOLONLARI[s.detay_tur] || !Array.isArray(s.anahtarlar) || !s.anahtarlar.length) return;
                const p = parcalar[s.detay_tur] ??= { tur: s.detay_tur, anahtarlar: [], satir_firma_id: '', adet: 0, tutar: 0 };
                s.anahtarlar.forEach(a => { if (!p.anahtarlar.includes(a)) p.anahtarlar.push(a); });
                p.adet  += Number(s.adet) || 0;
                p.tutar += Number(s.tutar) || 0;
            });
            return Object.values(parcalar);
        }

        /** Komisyon hariç tutar hücresi (yalnız satış kaynağında) */
        function komHaricHucre(x, ekSinif) {
            const net = Number(x.tutar || 0) - Number(x.komisyon || 0);
            return '<td class="text-end tutar' + (ekSinif || '') + '" title="Komisyon (KDV dahil): ' + para(x.komisyon || 0) + '">' + para(net) + '</td>';
        }

        function kirilimSatir(k, s, filtre, oranli, komli) {
            const hucreler = '<td><span>' + kacis(s.alt_kirilim) + '</span></td><td>' + kacis(s.firma_adi) + '</td>'
                + '<td class="text-end">' + Number(s.adet).toLocaleString('tr-TR') + '</td>'
                + '<td class="text-end tutar">' + para(s.tutar) + '</td>'
                + (komli ? komHaricHucre(s) : '')
                + (oranli ? '<td class="text-end">' + oranYazi(s.tutar, s.baz) + '</td>' : '');
            if (!DETAY_KOLONLARI[s.detay_tur] || !Array.isArray(s.anahtarlar) || !s.anahtarlar.length) {
                return '<tr>' + hucreler + '</tr>';
            }
            detayKayitlari.push({ tur: s.detay_tur, anahtarlar: s.anahtarlar, satir_firma_id: Number(s.firma_id) || 0,
                                  kaynak: k.ad, alt: s.alt_kirilim, firma: s.firma_adi, adet: s.adet, tutar: s.tutar, filtre: filtre });
            return '<tr class="kirilim-satir" data-idx="' + (detayKayitlari.length - 1) + '" title="Detayı göster">' + hucreler + '</tr>';
        }

        function kirilimCiz(d, filtre) {
            const kaynaklar = gorunenKaynaklar(d);
            const top = d.kaynakTop || {};
            detayKayitlari = [];
            kaynakToplamlari = {};
            ibFiltre = filtre;

            $('#kirilimKartlari').html(kaynaklar.map(k => {
                const satirlar = (d.kirilim || {})[k.kod] || [];
                const t = top[k.kod] || { tutar: 0, adet: 0 };
                const parcalar = kaynakParcalari(satirlar);
                if (parcalar.length) {
                    kaynakToplamlari[k.kod] = { kaynak: k.ad, tip: k.tip, alt: 'Toplam', firma: filtre.firma_ad, parcalar: parcalar, filtre: filtre };
                }
                const toplamSinif = parcalar.length ? ' kaynak-toplam" data-kod="' + kacis(k.kod) + '" title="Tüm kırılımların detayını göster' : '';
                // Oran kolonu yalnız paydası (baz) olan kaynakta (komisyon)
                const oranli = t.baz !== null && t.baz !== undefined;
                // Komisyon hariç kolonu yalnız komisyonu taşıyan kaynakta (satış)
                const komli = t.komisyon !== null && t.komisyon !== undefined;
                const govde = satirlar.length
                    ? satirlar.map(s => kirilimSatir(k, s, filtre, oranli, komli)).join('')
                    : '<tr><td colspan="' + (4 + (oranli ? 1 : 0) + (komli ? 1 : 0)) + '" class="text-center text-muted">Kayıt yok</td></tr>';

                return '<div class="col-12 col-xl-6 mb-3"><div class="card h-100" style="border-top:3px solid ' + kacis(k.renk) + '">'
                    + '<div class="card-header d-flex align-items-center gap-2">'
                    + '<span class="kaynak-ikon" style="background:' + kacis(k.renk) + '"><i class="' + kacis(k.ikon) + '"></i></span>'
                    + '<div class="flex-grow-1"><div class="fw-semibold">' + kacis(k.ad) + '</div>'
                    + '<small class="text-muted">' + kacis(k.aciklama) + '</small></div>'
                    + '<span class="badge ' + (k.tip === 'gelir' ? 'text-bg-success' : 'text-bg-danger') + '">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-success py-0 btn-kirilim-excel" data-kod="' + kacis(k.kod) + '" title="Kırılımı Excel\'e aktar">'
                    + '<i class="bi bi-file-earmark-excel"></i></button>'
                    + '</div><div class="card-body p-0"><div class="table-responsive">'
                    + '<table class="table table-sm table-striped mb-0 kirilim-tablo"><thead><tr>'
                    + '<th>Kırılım</th><th>Firma</th><th class="text-end">Adet</th><th class="text-end">Tutar</th>'
                    + (komli ? '<th class="text-end text-nowrap" title="Tutar − pazaryeri komisyonu (KDV dahil). Komisyonu henüz gelmemiş siparişlerde komisyon 0 sayılır.">Komisyon Hariç <i class="bi bi-question-circle"></i></th>' : '')
                    + (oranli ? '<th class="text-end text-nowrap" title="' + ORAN_IPUCU + '">Kom. / Tutar <i class="bi bi-question-circle"></i></th>' : '')
                    + '</tr></thead>'
                    + '<tbody>' + govde + '</tbody>'
                    + '<tfoot><tr class="fw-bold' + toplamSinif + '"><td colspan="2">Toplam</td><td class="text-end">' + Number(t.adet).toLocaleString('tr-TR') + '</td>'
                    + '<td class="text-end tutar"><span class="toplam-tutar">' + para(t.tutar) + '</span></td>'
                    + (komli ? komHaricHucre(t) : '')
                    + (oranli ? '<td class="text-end">' + oranYazi(t.tutar, t.baz) + '</td>' : '')
                    + '</tr></tfoot>'
                    + '</table></div></div></div></div>';
            }).join(''));

            // Aylık tablo Genel Toplam: yalnız detayı olan kaynak hücreleri tıklanır
            $('#tblAylik tfoot td[data-kod]').each(function () {
                const detayli = !!kaynakToplamlari[$(this).data('kod')];
                $(this).toggleClass('kaynak-toplam', detayli).attr('title', detayli ? 'Tüm kırılımların detayını göster' : null);
            });
        }

        // ---------------------------------------------------------------
        function ozetCiz(o) {
            $('#ibGelir').text(para(o.gelir));
            $('#ibGider').text(para(o.gider));
            $('#ibNet').text(para(o.net)).toggleClass('text-danger', o.net < 0);
            $('#ibMarj').text(o.marj === null ? '—' : '%' + Number(o.marj).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                        .toggleClass('text-danger', o.marj !== null && o.marj < 0);
        }

        function atlananCiz(liste) {
            $('#atlananKutu').html((liste || []).map(a =>
                '<span class="badge text-bg-warning me-1" title="Bu kaynak kodu için sayfada sorgu tanımlı değil; rapora dahil edilmedi">'
                + '<i class="bi bi-exclamation-triangle me-1"></i>' + kacis(a.ad) + ' (' + kacis(a.kod) + ') atlandı</span>').join(''));
        }

        let raporIstekNo = 0;   // art arda değişimde yalnız son isteğin yanıtı çizilir
        let sonFiltre = null;   // ekrandaki raporun filtresi (tablo bazlı Excel)

        /** InfoBox: komisyonu hesaplanmamış ciro; rapor çizildikten sonra ayrı çağrı (uygulanan kırılımla) */
        function komisyonsuzYukle(f, gelir, no) {
            $('#ibKomisyonsuz').text('...');
            $('#ibKomisyonsuzAlt').text('');
            $.post('', Object.assign({ action: 'komisyonsuz' }, f), function (res) {
                if (no !== raporIstekNo) return;
                if (!res.success) { $('#ibKomisyonsuz').text('-'); showToast(res.message || 'Özet alınamadı', 'error'); return; }
                const o = res.data;
                ibKomisyonsuz = o;
                const oran = gelir > 0 ? ' · %' + (o.tutar / gelir * 100).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + ' ciro' : '';
                $('#ibKomisyonsuz').text(para(o.tutar)).toggleClass('text-warning', o.tutar > 0);
                $('#ibKomisyonsuzAlt').text(sayi(o.siparis) + ' sipariş · ' + sayi(o.kalem) + ' kalem' + oran);
            }, 'json').fail(() => { if (no === raporIstekNo) $('#ibKomisyonsuz').text('-'); });
        }

        function raporYukle() {
            const f = filtreVerisi();
            if (!filtreGecerli(f)) return;

            const no = ++raporIstekNo;
            $('#btnFiltrele').prop('disabled', true);
            $.post('', Object.assign({ action: 'rapor' }, f), function (res) {
                if (no !== raporIstekNo) return;
                if (!res.success) { showToast(res.message || 'Rapor alınamadı', 'error'); return; }
                const d = res.data;
                kirilimSecenekCiz(d.kirilimlar, d.kirilimSecili);
                f.kirilim = d.kirilimSecili || [];
                sonFiltre = f;
                ozetCiz(d.ozet);
                // Gider kutusu: paydası olan gider kaynaklarının (komisyon) toplam oranı
                const giderOranli = gorunenKaynaklar(d).filter(k => k.tip === 'gider' && (d.kaynakTop[k.kod] || {}).baz != null);
                const gPay = giderOranli.reduce((a, k) => a + Number(d.kaynakTop[k.kod].tutar || 0), 0);
                const gBaz = giderOranli.reduce((a, k) => a + Number(d.kaynakTop[k.kod].baz || 0), 0);
                $('#ibGiderAlt').text(giderOranli.length ? 'Kom. / Tutar: ' + oranYazi(gPay, gBaz) : '');
                atlananCiz(d.atlanan);
                grafikCiz(d.aylar);
                tabloCiz(d);
                kirilimCiz(d, Object.assign({}, f, {
                    firma_ad: $('#fFirma option:selected').text() || 'Tüm firmalar'
                }));
                komisyonsuzYukle(f, d.ozet.gelir, no);
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => { if (no === raporIstekNo) $('#btnFiltrele').prop('disabled', false); });
        }

        // ---------------------------------------------------------------
        $('#btnFiltrele').on('click', raporYukle);
        $('#fFirma').on('change', raporYukle);

        // Çoklu seçimde her tıklama change üretir: kısa bekleme ile tek istek
        let kirilimZaman = null;
        $('#fKirilim').on('change', function () {
            clearTimeout(kirilimZaman);
            kirilimZaman = setTimeout(raporYukle, 500);
        });

        $('#btnTemizle').on('click', function () {
            const $ilk = $('#fFirma option:first');
            clearTimeout(kirilimZaman);
            $('#fFirma').val($ilk.val()).trigger('change.select2');
            $('#fKirilim').val(null).trigger('change.select2');
            varsayilanTarihler();
            raporYukle();
        });

        // Gizli form ile POST → tarayıcı dosyayı indirir. Dizi değerler 'ad[]', iç içe nesne yok.
        function excelIndir(veri) {
            const form = $('<form>', { method: 'POST', action: '' }).appendTo('body');
            Object.entries(veri).forEach(([k, v]) =>
                (Array.isArray(v) ? v : [v]).forEach(x =>
                    $('<input>', { type: 'hidden', name: Array.isArray(v) ? k + '[]' : k, value: x }).appendTo(form)));
            form.submit().remove();
            showToast('Excel dosyası hazırlanıyor...', 'info');
        }

        $('#btnExcel').on('click', function () {
            const f = filtreVerisi();
            if (!filtreGecerli(f)) return;
            excelIndir(Object.assign({ action: 'excel' }, f));
        });

        // Tablo bazlı Excel: ekrandaki tablonun yüklendiği filtreyle (filtre paneli sonradan değişmiş olabilir)
        $('#btnExcelAylik').on('click', function () {
            if (!sonFiltre) { showToast('Rapor henüz yüklenmedi', 'warning'); return; }
            excelIndir(Object.assign({ action: 'excel', bolum: 'aylik' }, sonFiltre));
        });

        $('#kirilimKartlari').on('click', '.btn-kirilim-excel', function () {
            if (!sonFiltre) return;
            excelIndir(Object.assign({ action: 'excel', bolum: 'kirilim', kaynak: $(this).data('kod') }, sonFiltre));
        });

        // ===============================================================
        // Kırılım detayı (server-side) + Ayarlar (kaynaklar)
        // ===============================================================
        const AYAR_YETKI = <?= $ayarYetki ? 'true' : 'false' ?>;

        // ---------------------------------------------------------------
        // Kırılım detayı: tür → kolonlar. Kolon sırası sunucudaki siralama whitelist index'iyle aynıdır
        // (detayTanim). orderable: false olan kolonların index'i sunucuda yoktur.
        const sayi = (n) => Number(n || 0).toLocaleString('tr-TR');
        const yuzde = (v) => v === null || v === undefined ? '—' : '%' + Number(v).toLocaleString('tr-TR', { maximumFractionDigits: 2 });
        const metin = (v) => kacis(v);

        /** Detay türü adları (kaynak toplamı sekmeleri): sunucudaki DETAY_TURLERI */
        const DETAY_AD = <?= json_encode(array_map(fn($t) => $t['ad'], DETAY_TURLERI), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

        const DETAY_KOLONLARI = {
            satis: {
                ikon: 'bi-cart-check', ara: 'Sipariş no / müşteri / ürün / barkod', birim: 'sipariş',
                kolonlar: [
                    { title: 'Sipariş Tarihi', data: 'tarih', render: metin },
                    { title: 'Sipariş No', data: 'siparis_no', render: metin },
                    { title: 'Pazaryeri', data: 'pazaryeri', render: metin },
                    { title: 'Kanal', data: 'kanal', render: metin },
                    { title: 'Firma', data: 'firma', render: metin },
                    { title: 'Müşteri', data: 'musteri', render: metin },
                    { title: 'Ürün', data: 'urunler', orderable: false, className: 'aciklama-hucre',
                      render: (v) => v ? v.split(' ¦ ').map((u) => '<div>' + kacis(u) + '</div>').join('') : '—' },
                    { title: 'Durum', data: 'durum', render: (v) => '<span class="badge text-bg-light border">' + kacis(v || '-') + '</span>' },
                    { title: 'Kalem', data: 'kalem', orderable: false, className: 'text-end', render: sayi },
                    { title: 'Komisyon (KDV Dahil)', data: 'komisyon', orderable: false, className: 'text-end tutar text-danger',
                      render: (v) => v === null ? '<span class="text-muted" title="Komisyon henüz yok: pazaryeri teslim sonrası bildirir">—</span>' : para(v) },
                    { title: 'Tutar (KDV Dahil)', data: 'tutar', className: 'text-end tutar', render: para },
                    { title: '<span title="' + ORAN_IPUCU + '">Kom. / Tutar</span>', data: 'komisyon_yuzde', orderable: false, className: 'text-end', render: yuzde }
                ]
            },
            komisyon_pazaryeri: {
                ikon: 'bi-percent', ara: 'Sipariş no / ürün adı / barkod', birim: 'sipariş',
                kolonlar: [
                    { title: 'Sipariş Tarihi', data: 'tarih', render: metin },
                    { title: 'Sipariş No', data: 'siparis_no', render: metin },
                    { title: 'Pazaryeri', data: 'pazaryeri', render: metin },
                    { title: 'Firma', data: 'firma', render: metin },
                    { title: 'Ürün Adı', data: 'urun', className: 'aciklama-hucre', render: metin },
                    { title: 'Barkod', data: 'barkod', render: metin },
                    { title: 'Miktar', data: 'miktar', className: 'text-end', render: sayi },
                    { title: 'Kalem Tutarı', data: 'kalem_tutar', className: 'text-end tutar', render: para },
                    { title: '<span title="Pazaryerinin bildirdiği oran (KDV hariç)">Komisyon Oranı</span>', data: 'komisyon_oran', className: 'text-end', render: yuzde },
                    { title: 'Komisyon (KDV Hariç)', data: 'komisyon_kdvsiz', orderable: false, className: 'text-end tutar', render: (v) => v === null ? '—' : para(v) },
                    { title: 'KDV', data: 'komisyon_kdv_oran', orderable: false, className: 'text-end', render: yuzde },
                    { title: 'Komisyon (KDV Dahil)', data: 'komisyon_tutar', className: 'text-end tutar', render: (v) => v === null ? '—' : para(v) },
                    { title: '<span title="' + ORAN_IPUCU + '">Kom. / Tutar</span>', data: 'etkin_oran', orderable: false, className: 'text-end fw-semibold', render: yuzde }
                ],
                // Komisyonu henüz gelmemiş kalem: soluk satır
                satirSinif: (tr, r) => {
                    if (r.komisyon_tutar === null) {
                        $(tr).addClass('satir-soluk').attr('title', 'Komisyon henüz yok: pazaryeri teslim sonrası bildirir');
                    }
                }
            },
            komisyonsuz: {
                ikon: 'bi-hourglass-split', ara: 'Sipariş no / ürün adı / barkod', birim: 'sipariş',
                kolonlar: [
                    { title: 'Sipariş Tarihi', data: 'tarih', render: metin },
                    { title: 'Sipariş No', data: 'siparis_no', render: metin },
                    { title: 'Pazaryeri', data: 'pazaryeri', render: metin },
                    { title: 'Firma', data: 'firma', render: metin },
                    { title: 'Ürün Adı', data: 'urun', className: 'aciklama-hucre', render: metin },
                    { title: 'Barkod', data: 'barkod', render: metin },
                    { title: 'Miktar', data: 'miktar', className: 'text-end', render: sayi },
                    { title: 'Durum', data: 'durum', render: (v) => '<span class="badge text-bg-light border">' + kacis(v || '-') + '</span>' },
                    { title: 'Kalem Tutarı', data: 'kalem_tutar', className: 'text-end tutar', render: para }
                ]
            }
        };

        let detayParam = null;   // {tur, anahtarlar, satir_firma_id, firma_id, bas_tarih, bit_tarih}
        let detayKayit = null;   // tıklanan kırılım satırı (başlık / özet metni için)
        let tblDetay   = null;
        let detayAraZaman = null;

        /** Özet metni: sunucudaki toplam (kırılım kartıyla aynı tanım) + arama varsa uyan kısım */
        function detayOzetCiz(json) {
            const tanim = DETAY_KOLONLARI[detayParam.tur];
            const o = json.ozet || { adet: 0, toplam: 0 };
            let s = '<span>Toplam: <b>' + sayi(o.adet) + '</b> ' + tanim.birim;
            if (o.kalem !== undefined) {
                s += ' · ' + sayi(o.kalem) + ' kalem';
                if (o.bos) s += ' <span class="text-muted">(' + sayi(o.bos) + ' kalemde komisyon henüz yok)</span>';
            }
            s += ' · <b class="tutar">' + para(o.toplam) + '</b>';
            // Komisyon detayında etkin oran: toplam komisyon / komisyonu gelmiş kalem tutarı
            if (o.baz !== undefined) {
                s += ' · <span title="' + ORAN_IPUCU + '">Kom. / Tutar: <b>' + oranYazi(o.toplam, o.baz) + '</b>'
                   + ' <span class="text-muted">(' + para(o.baz) + ' üzerinden)</span></span>';
            }
            s += '</span>';
            if (json.ozet_filtre) {
                const a = json.ozet_filtre;
                s += '<span class="ms-3 text-muted">Aramaya uyan: ' + sayi(a.kalem !== undefined ? a.kalem : a.adet) + ' kayıt · '
                   + '<span class="tutar">' + para(a.toplam) + '</span>'
                   + (a.baz !== undefined ? ' · ' + oranYazi(a.toplam, a.baz) : '') + '</span>';
            }
            // Kırılım kartındaki rakamla aynı olmalı; farklıysa (ör. veri arada değişti) kullanıcı uyarılır
            if (detayKayit && (Number(o.adet) !== Number(detayKayit.adet) || Math.abs(Number(o.toplam) - Number(detayKayit.tutar)) > 0.005)) {
                s += '<span class="ms-3 text-warning" title="Rapor yüklendikten sonra veri değişmiş olabilir; raporu yenileyin">'
                   + '<i class="bi bi-exclamation-triangle"></i> Kırılım kartıyla farklı</span>';
            }
            $('#detayOzet').html(s);
        }

        /** Tür değişebildiği için tablo her açılışta yeniden kurulur (destroy + yeni columns) */
        function detayKur() {
            if (tblDetay) {
                tblDetay.destroy();
                $('#tblDetay').empty();
                tblDetay = null;
            }
            const tanim = DETAY_KOLONLARI[detayParam.tur];
            tblDetay = $('#tblDetay').DataTable({
                serverSide: true,
                processing: true,
                scrollX: true,
                dom: 'lrtip',
                pageLength: 25,
                lengthMenu: [25, 50, 100],
                order: [[0, 'desc']],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: (d) => Object.assign(d, { action: 'detay', ara: $('#detayAra').val() }, detayParam || {}),
                    dataSrc: (json) => {
                        if (json.error) { showToast(json.error, 'error'); $('#detayOzet').text('-'); return []; }
                        detayOzetCiz(json);
                        return json.data || [];
                    },
                    error: () => showToast('Detay alınamadı', 'error')
                },
                columns: tanim.kolonlar,
                createdRow: tanim.satirSinif || null,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });
        }

        /**
         * Detay modalı. a: {kaynak, alt, firma, filtre, parcalar: [{tur, anahtarlar, satir_firma_id, adet, tutar}]}
         * Kırılım satırı tek parçadır; kaynak toplamında her detay türü bir sekme olur.
         */
        let detayAcilis = null;

        function detayParcaSec(i) {
            const p = detayAcilis.parcalar[i];
            const f = detayAcilis.filtre;   // raporun yüklendiği filtre: detay kırılım rakamıyla aynı kapsamda
            detayKayit = p;
            detayParam = {
                tur: p.tur, anahtarlar: p.anahtarlar, satir_firma_id: p.satir_firma_id,
                firma_id: f.firma_id, bas_tarih: f.bas_tarih, bit_tarih: f.bit_tarih,
                kirilim: f.kirilim || []   // komisyonsuz detayı kapsamı kırılım seçiminden alır
            };
            const tanim = DETAY_KOLONLARI[p.tur];
            $('#detayIkon').attr('class', 'bi ' + tanim.ikon + ' me-1');
            $('#detayAraEtiket').text(tanim.ara);
            $('#detayAra').val('').attr('placeholder', tanim.ara);
            $('#detayOzet').text('Yükleniyor...');
            $('#detaySekmeler .nav-link').removeClass('active').eq(i).addClass('active');
            if (tblDetay) { tblDetay.destroy(); $('#tblDetay').empty(); tblDetay = null; }
            // Modal açıkken sekme değişimi: tablo hemen kurulur (ilk açılışta shown.bs.modal kurar)
            if ($('#detayModal').hasClass('show')) detayKur();
        }

        function detayAc(a) {
            if (!a || !a.parcalar.length) return;
            const f = a.filtre;
            detayAcilis = a;
            $('#detayBaslik').text(a.kaynak + ' – ' + a.alt + ' – ' + a.firma + ' (' + f.bas_tarih + ' – ' + f.bit_tarih + ')');
            $('#detayKapsam').text('Filtre: ' + f.firma_ad);
            $('#detaySekmeler').toggleClass('d-none', a.parcalar.length < 2).html(a.parcalar.map((p, i) =>
                '<li class="nav-item"><button type="button" class="nav-link py-1" data-i="' + i + '">'
                + '<i class="bi ' + DETAY_KOLONLARI[p.tur].ikon + ' me-1"></i>' + kacis(DETAY_AD[p.tur] || p.tur)
                + ' <span class="badge text-bg-light border tutar">' + para(p.tutar) + '</span></button></li>').join(''));
            detayParcaSec(0);
            bootstrap.Modal.getOrCreateInstance('#detayModal').show();
        }

        $('#detaySekmeler').on('click', '.nav-link', function () {
            if (!$(this).hasClass('active')) detayParcaSec($(this).data('i'));
        });

        $('#kirilimKartlari').on('click', '.kirilim-satir', function () {
            const k = detayKayitlari[$(this).data('idx')];
            if (!k || !DETAY_KOLONLARI[k.tur]) return;
            detayAc({ kaynak: k.kaynak, alt: k.alt, firma: k.firma, filtre: k.filtre,
                      parcalar: [{ tur: k.tur, anahtarlar: k.anahtarlar, satir_firma_id: k.satir_firma_id, adet: k.adet, tutar: k.tutar }] });
        });

        // Kaynak toplamı: kart altı Toplam satırı ve aylık tablodaki Genel Toplam hücresi
        $('#kirilimKartlari, #tblAylik').on('click', '.kaynak-toplam', function () {
            detayAc(kaynakToplamlari[$(this).data('kod')]);
        });

        /**
         * InfoBox → detay modalı. gelir / gider: o tipteki kaynakların toplam parçaları (aynı tür birleşir),
         * net: gelir + gider sekmeleri, komisyonsuz: anahtarsız tek parça (kapsam rapor filtresinden).
         */
        $('.ib-detay').on('click', function () {
            if (!ibFiltre) { showToast('Rapor henüz yüklenmedi', 'warning'); return; }
            const ib = $(this).data('ib');
            const f = ibFiltre;

            if (ib === 'komisyonsuz') {
                if (!ibKomisyonsuz) { showToast('Özet henüz yüklenmedi', 'warning'); return; }
                if (!ibKomisyonsuz.kalem) { showToast('Komisyonu hesaplanmamış kalem yok', 'info'); return; }
                detayAc({ kaynak: 'Komisyonu Hesaplanmamış Ciro', alt: 'Tümü', firma: f.firma_ad, filtre: f,
                          parcalar: [{ tur: 'komisyonsuz', anahtarlar: [], satir_firma_id: '',
                                       adet: ibKomisyonsuz.siparis, tutar: ibKomisyonsuz.tutar }] });
                return;
            }

            const tipler = ib === 'net' ? ['gelir', 'gider'] : [ib];
            const parcalar = {};
            Object.values(kaynakToplamlari).filter(t => tipler.includes(t.tip)).forEach(t => t.parcalar.forEach(p => {
                const h = parcalar[p.tur] ??= { tur: p.tur, anahtarlar: [], satir_firma_id: '', adet: 0, tutar: 0 };
                p.anahtarlar.forEach(a => { if (!h.anahtarlar.includes(a)) h.anahtarlar.push(a); });
                h.adet  += p.adet;
                h.tutar += p.tutar;
            }));
            const liste = Object.values(parcalar);
            if (!liste.length) { showToast('Detay gösterilecek kayıt yok', 'info'); return; }
            const ad = { gelir: 'Toplam Gelir', gider: 'Toplam Gider', net: 'Gelir ve Gider' }[ib];
            detayAc({ kaynak: ad, alt: 'Tümü', firma: f.firma_ad, filtre: f, parcalar: liste });
        });

        // Tablo modal görünür olunca kurulur: scrollX kolon genişlikleri doğru hesaplanır
        $('#detayModal').on('shown.bs.modal', function (e) {
            if (e.target !== this || !detayParam) return;
            if (!tblDetay) { detayKur(); } else { tblDetay.columns.adjust(); }
        });

        // Detay Excel: modaldaki arama ve sıralamayla, sayfalamasız.
        // Birden fazla sekme varsa hepsi tek dosyada; arama + sıralama yalnız açık sekmeye uygulanır.
        $('#btnDetayExcel').on('click', function () {
            if (!detayParam || !tblDetay) return;
            const sira = tblDetay.order()[0] || [0, 'desc'];
            const veri = Object.assign({}, detayParam, {
                action: 'detay_excel',
                ara: $('#detayAra').val(),
                'order[0][column]': sira[0],
                'order[0][dir]': sira[1],
                baslik: $('#detayBaslik').text() + ' · ' + $('#detayKapsam').text()
            });
            if (detayAcilis && detayAcilis.parcalar.length > 1) {
                veri.parcalar = JSON.stringify(detayAcilis.parcalar.map(p => ({ tur: p.tur, anahtarlar: p.anahtarlar, satir_firma_id: p.satir_firma_id })));
            }
            excelIndir(veri);
        });

        $('#detayAra').on('input', function () {
            clearTimeout(detayAraZaman);
            detayAraZaman = setTimeout(() => tblDetay && tblDetay.ajax.reload(), 400);
        });

        // ---------------------------------------------------------------
        // Ayarlar
        if (!AYAR_YETKI) {
            varsayilanTarihler();
            raporYukle();
            return;
        }

        let ayar = null;   // {kaynaklar}

        function ayarYukle() {
            return $.post('', { action: 'ayar_liste' }, null, 'json').then(function (res) {
                if (!res.success) { showToast(res.message || 'Ayarlar alınamadı', 'error'); return $.Deferred().reject(); }
                ayar = res.data;
                kaynaklarCiz();
            }, function () { showToast('Sunucu hatası', 'error'); });
        }

        const renkHex = (r) => {
            r = String(r || '').trim();
            if (/^#[0-9a-f]{6}$/i.test(r)) return r;
            if (/^#[0-9a-f]{3}$/i.test(r)) return '#' + r.slice(1).split('').map(c => c + c).join('');
            return '#6c757d';
        };
        const IKON_DESENI = /^[a-zA-Z0-9 _-]{1,60}$/;

        function kaynaklarCiz() {
            $('#tblKaynaklar tbody').html((ayar.kaynaklar || []).map(k => {
                const id = Number(k.id);
                const ikon = IKON_DESENI.test(k.ikon || '') ? k.ikon : 'bi bi-circle';
                return '<tr data-id="' + id + '">'
                    + '<td><code>' + kacis(k.kod) + '</code></td>'
                    + '<td><span class="badge ' + (k.tip === 'gelir' ? 'text-bg-success' : 'text-bg-danger') + '">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</span></td>'
                    + '<td><input type="text" class="form-control form-control-sm k-ad" maxlength="100" value="' + kacis(k.ad) + '"></td>'
                    + '<td><input type="color" class="form-control form-control-sm k-renk" value="' + renkHex(k.renk) + '" title="' + kacis(k.renk) + '"></td>'
                    + '<td><div class="input-group input-group-sm" style="min-width:170px">'
                    + '<span class="input-group-text"><i class="k-ikon-onizle ' + kacis(ikon) + '"></i></span>'
                    + '<input type="text" class="form-control k-ikon" maxlength="60" value="' + kacis(k.ikon) + '" placeholder="bi bi-truck"></div></td>'
                    + '<td><input type="number" class="form-control form-control-sm k-sira" min="0" max="9999" value="' + Number(k.sira) + '"></td>'
                    + '<td><input type="text" class="form-control form-control-sm k-aciklama" maxlength="300" value="' + kacis(k.aciklama) + '"></td>'
                    + '<td><div class="form-check form-switch d-flex justify-content-center">'
                    + '<input class="form-check-input kaynak-durum" type="checkbox" role="switch" id="kyd_' + id + '"' + (Number(k.durum) ? ' checked' : '') + '>'
                    + '<label class="form-check-label" for="kyd_' + id + '"></label></div></td>'
                    + '<td class="text-end"><button type="button" class="btn btn-sm btn-primary py-0 btn-kaynak-kaydet" title="Kaydet"><i class="bi bi-check-lg"></i></button></td>'
                    + '</tr>';
            }).join('') || '<tr><td colspan="9" class="text-center text-muted">Kaynak yok</td></tr>');
        }

        $('#btnAyarlar').on('click', function () {
            ayarYukle();
            bootstrap.Modal.getOrCreateInstance('#ayarModal').show();
        });

        /** Değişiklik sonrası: açık listeler + rapor (+ açıksa kırılım detayı) yenilenir; kart rakamı eskidiği için karşılaştırma kapanır */
        function degisiklikSonrasi() {
            if ($('#ayarModal').hasClass('show')) ayarYukle();
            detayKayit = null;
            if (tblDetay && $('#detayModal').hasClass('show')) tblDetay.ajax.reload(null, false);
            raporYukle();
        }

        // ---- Kaynaklar
        $('#tblKaynaklar').on('input', '.k-ikon', function () {
            const v = $.trim($(this).val());
            $(this).closest('td').find('.k-ikon-onizle').attr('class', 'k-ikon-onizle ' + (IKON_DESENI.test(v) ? v : 'bi bi-question-circle text-danger'));
        });

        $('#tblKaynaklar').on('click', '.btn-kaynak-kaydet', function () {
            const $tr = $(this).closest('tr');
            const veri = {
                action:   'kaynak_kaydet',
                id:       $tr.data('id'),
                ad:       $.trim($tr.find('.k-ad').val()),
                renk:     $tr.find('.k-renk').val(),
                ikon:     $.trim($tr.find('.k-ikon').val()),
                sira:     $tr.find('.k-sira').val(),
                aciklama: $.trim($tr.find('.k-aciklama').val())
            };
            if (!veri.ad) { showToast('Ad zorunludur', 'warning'); return; }
            if (!IKON_DESENI.test(veri.ikon)) { showToast('İkon yalnız harf, rakam, boşluk ve tire içerebilir', 'warning'); return; }
            const $btn = $(this).prop('disabled', true);
            $.post('', veri, function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (res.success) degisiklikSonrasi();
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => $btn.prop('disabled', false));
        });

        $('#tblKaynaklar').on('change', '.kaynak-durum', function () {
            const $c = $(this);
            const durum = $c.is(':checked') ? 1 : 0;
            $.post('', { action: 'kaynak_durum', id: $c.closest('tr').data('id'), durum: durum }, function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (res.success) { degisiklikSonrasi(); } else { $c.prop('checked', !durum); }
            }, 'json').fail(() => { $c.prop('checked', !durum); showToast('Sunucu hatası', 'error'); });
        });

        varsayilanTarihler();
        raporYukle();
    });
    </script>
</body>
</html>
