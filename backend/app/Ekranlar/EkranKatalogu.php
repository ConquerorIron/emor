<?php

declare(strict_types=1);

namespace App\Ekranlar;

/**
 * Bir ERP ekranının alan kataloğu. Yeni ekran eklemek = bu arayüzü uygulayan
 * bir sınıf yazıp EkranKataloglari'na kaydetmek.
 */
interface EkranKatalogu
{
    /** Ekranı tanımlayan anahtar (ör. 'satinalma.talep') */
    public function anahtar(): string;

    /** Ekran adının i18n anahtarı */
    public function baslikAnahtari(): string;

    /**
     * Tasarlanabilir alanların tamamı.
     *
     * @return list<KatalogAlani>
     */
    public function alanlar(): array;

    /**
     * Bölüm tanımları — başlıklar i18n anahtarıdır, tasarımcı değiştiremez
     * (çoklu dil desteği korunur).
     *
     * @return list<array{anahtar: string, baslik_anahtari: string}>
     */
    public function bolumler(): array;

    /**
     * Hiç tasarım yokken kullanılacak varsayılan düzen (ilk yayınlanan sürüm
     * bundan üretilir; ekran ilk günkü haliyle açılır).
     *
     * @return array{bolumler: list<array<string, mixed>>}
     */
    public function varsayilanDuzen(): array;
}
