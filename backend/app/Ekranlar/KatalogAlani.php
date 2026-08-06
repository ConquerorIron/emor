<?php

declare(strict_types=1);

namespace App\Ekranlar;

/**
 * Ekran tasarım motorunun alan tanımı — KOD tarafında durur, kullanıcı
 * değiştiremez. Alanın NE olduğunu anlatır (giriş tipi, veri kaynağı, proc
 * eşlemesi); NASIL göründüğü (yer, genişlik, zorunluluk) tasarımda tutulur.
 *
 * Bir "alan" tek bir form anahtarı değildir: personel seçimi hem personel_id
 * hem personel_adi yazar. veriAnahtarlari bu yüzden liste.
 */
final class KatalogAlani
{
    /**
     * @param  string  $anahtar  Tasarımda ve katalogda alanı tanımlayan ad
     * @param  string  $etiketAnahtari  i18n anahtarı (frontend çevirir)
     * @param  string  $girisTipi  Frontend kayıt defterindeki bileşen anahtarı
     * @param  list<string>  $veriAnahtarlari  Alanın form verisine yazdığı anahtarlar
     * @param  string  $procParametresi  Belge amaçlı: hangi proc parametresine gider
     * @param  int  $varsayilanGenislik  1–12 (ilk eklendiğinde)
     * @param  bool  $kaldirilamaz  Proc'un onsuz çalışmadığı alanlar tasarımdan çıkarılamaz
     * @param  bool  $saltOkunurSabit  Değerini program üretir; düzenlenebilir yapılamaz (ör. No)
     * @param  bool  $zorunluSecilebilir  Tasarımcı "zorunlu" işaretleyebilir mi
     * @param  bool  $metinAlani  Serbest metin mi (textarea seçeneği yalnız bunlarda)
     */
    public function __construct(
        public readonly string $anahtar,
        public readonly string $etiketAnahtari,
        public readonly string $girisTipi,
        public readonly array $veriAnahtarlari,
        public readonly string $procParametresi = '',
        public readonly int $varsayilanGenislik = 6,
        public readonly bool $kaldirilamaz = false,
        public readonly bool $saltOkunurSabit = false,
        public readonly bool $zorunluSecilebilir = true,
        public readonly bool $metinAlani = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diziye(): array
    {
        return [
            'anahtar' => $this->anahtar,
            'etiket_anahtari' => $this->etiketAnahtari,
            'giris_tipi' => $this->girisTipi,
            'veri_anahtarlari' => $this->veriAnahtarlari,
            'proc_parametresi' => $this->procParametresi,
            'varsayilan_genislik' => $this->varsayilanGenislik,
            'kaldirilamaz' => $this->kaldirilamaz,
            'salt_okunur_sabit' => $this->saltOkunurSabit,
            'zorunlu_secilebilir' => $this->zorunluSecilebilir,
            'metin_alani' => $this->metinAlani,
        ];
    }
}
