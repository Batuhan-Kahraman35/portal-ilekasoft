<?php
/**
 * Bugün Doğum Günü Olanlar kartı
 * dashboard*.php dosyalarından include edilir; $db ve $user değişkenleri hazır olmalı.
 * 29 Şubat doğumlular, artık yıl olmayan yıllarda 28 Şubat'ta listelenir.
 */

$dogumGunuOlanlar = $db->fetchAll("
    SELECT
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad AS ad_soyad,
        d.departman_adi,
        DATEDIFF(YEAR, k.kullanici_dogum_tarihi, GETDATE()) AS yas
    FROM kullanicilar k
    LEFT JOIN Departmanlar d ON d.departman_id = k.kullanici_calisma_departman_id
    WHERE k.kullanici_durum = 1
      AND k.kullanici_dogum_tarihi IS NOT NULL
      AND (k.kullanici_ise_cikis_tarihi IS NULL OR k.kullanici_ise_cikis_tarihi >= CONVERT(date, GETDATE()))
      AND (
            (MONTH(k.kullanici_dogum_tarihi) = MONTH(GETDATE()) AND DAY(k.kullanici_dogum_tarihi) = DAY(GETDATE()))
         OR (MONTH(k.kullanici_dogum_tarihi) = 2 AND DAY(k.kullanici_dogum_tarihi) = 29
             AND MONTH(GETDATE()) = 2 AND DAY(GETDATE()) = 28
             AND ISDATE(CAST(YEAR(GETDATE()) AS VARCHAR(4)) + '0229') = 0)
      )
    ORDER BY k.kullanici_ad, k.kullanici_soyad
") ?: [];
?>
<div class="row mb-3">
    <div class="col-12">
        <div class="card card-outline card-danger">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-cake2-fill text-danger me-2"></i>Bugün Doğum Günü Olanlar
                    <span class="badge text-bg-danger ms-1"><?= count($dogumGunuOlanlar) ?></span>
                </h3>
                <div class="card-tools">
                    <span class="text-muted small"><?= date('d.m.Y') ?></span>
                </div>
            </div>
            <div class="card-body<?= empty($dogumGunuOlanlar) ? '' : ' p-0' ?>">
                <?php if (empty($dogumGunuOlanlar)): ?>
                    <p class="text-muted text-center mb-0">
                        <i class="bi bi-calendar-heart me-1"></i>Bugün doğum günü olan personel yok.
                    </p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($dogumGunuOlanlar as $kisi):
                            $benMi = ((int)$kisi['kullanici_id'] === (int)($user['id'] ?? 0));
                        ?>
                        <li class="list-group-item d-flex align-items-center<?= $benMi ? ' list-group-item-danger' : '' ?>">
                            <span class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center me-3"
                                  style="width: 40px; height: 40px; font-size: 1.25rem;">
                                <i class="bi bi-gift-fill"></i>
                            </span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($kisi['ad_soyad']) ?>
                                    <?php if ($benMi): ?><span class="badge text-bg-danger ms-1">İyi ki doğdunuz!</span><?php endif; ?>
                                </div>
                                <?php if (!empty($kisi['departman_adi'])): ?>
                                <small class="text-muted"><?= htmlspecialchars($kisi['departman_adi']) ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="badge rounded-pill text-bg-light border"><?= (int)$kisi['yas'] ?> yaş</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
