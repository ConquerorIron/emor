<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * İşlem geçmişine (EK-03) yazılan elle işlemler. Değerler veritabanında
 * saklanır ve ekranda çevrilir (efatura.islem.*); değiştirilmez.
 */
enum EFaturaIslemTuru: string
{
    case RedeCekildi = 'red_cekildi';
    case RedGeriAlindi = 'red_geri_alindi';
    case IslendiIsaretlendi = 'islendi_isaretlendi';
    case IslendiKaldirildi = 'islendi_kaldirildi';
    case Gizlendi = 'gizlendi';
    case GizlemeKaldirildi = 'gizleme_kaldirildi';
    case IceAktarildi = 'ice_aktarildi';
    case IceAktarimGeriAlindi = 'ice_aktarim_geri_alindi';
}
