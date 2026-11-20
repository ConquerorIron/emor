<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Olay günlüğündeki olay türleri (README §5.3: manifest değişmez, sonradan
 * değişenler buraya). Ayrıntı alanları Backup-Jira BY-006'daki tabloda.
 */
enum OlayTuru: string
{
    case Sabitlendi = 'sabitlendi';
    case SabitCozuldu = 'sabit_cozuldu';
    case Aciklama = 'aciklama';
    case Dogrulandi = 'dogrulandi';
    case Kopya = 'kopya';
    case KopyaSilindi = 'kopya_silindi';
    case YerelSilindi = 'yerel_silindi';
    case Silindi = 'silindi';
}
