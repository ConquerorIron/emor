<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Dosya yerine TAŞINDI (okuyanlara görünür), ama ardından klasör diske
 * eşitlenemedi: yazım yayımlandı, kesintiye dayanıp dayanmayacağı
 * doğrulanamadı (AtomikDosya; Dikkat_Edilecekler D3). Taşımadan önceki
 * hatalar düz RuntimeException'dır: o zaman dosya hiç yayımlanmamıştır.
 *
 * RuntimeException'dan türer: yalnız RuntimeException / Throwable yakalayan
 * çağıranlar için davranış değişmez; ayrımı isteyen (istek kabulü — BY-014-R2)
 * bu sınıfı ayrıca yakalar.
 */
final class KalicilikDogrulanamadi extends RuntimeException {}
