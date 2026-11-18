<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedeği ne başlattı (README §4.1). Saklama kuralı kaynağa göre seçilir:
 * plan yedekleri planın hedef kuralıyla, diğerleri yedek_ayarlari'ndaki
 * sürelerle temizlenir (README §4.3).
 */
enum YedekKaynagi: string
{
    case Elle = 'elle';
    case Plan = 'plan';
    case YayinOncesi = 'yayin_oncesi';
    case GeriYuklemeOncesi = 'geri_yukleme_oncesi';
}
