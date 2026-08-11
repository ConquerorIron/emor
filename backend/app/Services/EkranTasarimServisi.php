<?php

declare(strict_types=1);

namespace App\Services;

use App\Ekranlar\EkranKataloglari;
use App\Ekranlar\EkranKatalogu;
use App\Ekranlar\KatalogAlani;
use App\Ekranlar\SatinalmaTalebiSatirKatalogu;
use App\Models\EkranTasarimi;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ekran tasarım motoru: düzen doğrulama + taslak/yayın/sürüm akışı.
 *
 * Düzen KULLANICI verisidir; katalog KOD tarafındadır. Bu servis ikisinin
 * tutarlı kalmasını garanti eder — bilinmeyen alan, geçersiz genişlik veya
 * kaldırılamaz alanın silinmesi kaydedilemez.
 */
final class EkranTasarimServisi
{
    public function __construct(
        private readonly ErpEvrakOzellikleri $ozellikler,
    ) {}

    /**
     * Satır kataloğu = program kolonları + ERP'nin özel alanları.
     *
     * ERP'ye ulaşılamazsa yalnız program kolonlarıyla devam edilir: tasarım
     * ekranı MSSQL kapalıyken de açılabilmeli.
     *
     * @return list<array<string, mixed>>
     */
    public function satirKatalogu(): array
    {
        try {
            $ozellikler = $this->ozellikler->satinalmaTalebi(1);
        } catch (\Throwable) {
            $ozellikler = [];
        }

        return SatinalmaTalebiSatirKatalogu::alanlar($ozellikler);
    }

    /** Yayında sürüm yoksa katalogun varsayılan düzeni kullanılır. */
    public function yayindakiDuzen(string $ekranAnahtari): array
    {
        $katalog = EkranKataloglari::bul($ekranAnahtari);

        $tasarim = EkranTasarimi::query()
            ->where('ekran_anahtari', $ekranAnahtari)
            ->where('durum', EkranTasarimi::DURUM_YAYINDA)
            ->first();

        // Saklanan düzen katalogla UZLAŞTIRILARAK döner: katalog sonradan
        // büyüdüğünde (yeni alan, satır ızgarası) eski kayıtlar eksik kalmaz
        return $tasarim === null
            ? $katalog->varsayilanDuzen()
            : $this->duzeniDogrula($katalog, $tasarim->duzen);
    }

    /** Taslak yoksa yayındakinden (o da yoksa varsayılandan) kopyalanarak açılır. */
    public function taslakGetirVeyaAc(string $ekranAnahtari, int $kullaniciId): EkranTasarimi
    {
        $mevcut = EkranTasarimi::query()
            ->where('ekran_anahtari', $ekranAnahtari)
            ->where('durum', EkranTasarimi::DURUM_TASLAK)
            ->first();

        if ($mevcut !== null) {
            // Eski taslak da katalogla uzlaştırılır (kaydedilmez, gösterilir)
            $mevcut->duzen = $this->duzeniDogrula(
                EkranKataloglari::bul($ekranAnahtari),
                $mevcut->duzen,
            );

            return $mevcut;
        }

        return EkranTasarimi::query()->create([
            'ekran_anahtari' => $ekranAnahtari,
            'surum' => $this->sonrakiSurum($ekranAnahtari),
            'durum' => EkranTasarimi::DURUM_TASLAK,
            'duzen' => $this->yayindakiDuzen($ekranAnahtari),
            'olusturan_id' => $kullaniciId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $duzen
     */
    public function taslagiKaydet(string $ekranAnahtari, array $duzen, int $kullaniciId): EkranTasarimi
    {
        $katalog = EkranKataloglari::bul($ekranAnahtari);
        $temiz = $this->duzeniDogrula($katalog, $duzen);

        $taslak = $this->taslakGetirVeyaAc($ekranAnahtari, $kullaniciId);
        $taslak->duzen = $temiz;
        $taslak->save();

        return $taslak;
    }

    /** Taslağı canlıya alır; önceki yayın arşive düşer (geri alınabilir). */
    public function yayinla(string $ekranAnahtari, int $kullaniciId): EkranTasarimi
    {
        return DB::transaction(function () use ($ekranAnahtari, $kullaniciId): EkranTasarimi {
            $taslak = EkranTasarimi::query()
                ->where('ekran_anahtari', $ekranAnahtari)
                ->where('durum', EkranTasarimi::DURUM_TASLAK)
                ->lockForUpdate()
                ->first();

            if ($taslak === null) {
                throw ValidationException::withMessages([
                    'ekran' => __('hata.tasarim_taslak_yok'),
                ]);
            }

            // Yayınlanmadan önce son bir kez doğrula (katalog kod değişmiş olabilir)
            $taslak->duzen = $this->duzeniDogrula(EkranKataloglari::bul($ekranAnahtari), $taslak->duzen);

            EkranTasarimi::query()
                ->where('ekran_anahtari', $ekranAnahtari)
                ->where('durum', EkranTasarimi::DURUM_YAYINDA)
                ->update(['durum' => EkranTasarimi::DURUM_ARSIV]);

            $taslak->durum = EkranTasarimi::DURUM_YAYINDA;
            $taslak->yayinlayan_id = $kullaniciId;
            $taslak->yayin_zamani = now();
            $taslak->save();

            return $taslak;
        });
    }

    /** Arşivdeki bir sürümü yeni taslak olarak geri getirir. */
    public function surumuGeriAl(string $ekranAnahtari, int $surum, int $kullaniciId): EkranTasarimi
    {
        return DB::transaction(function () use ($ekranAnahtari, $surum, $kullaniciId): EkranTasarimi {
            $kaynak = EkranTasarimi::query()
                ->where('ekran_anahtari', $ekranAnahtari)
                ->where('surum', $surum)
                ->first();

            if ($kaynak === null) {
                throw ValidationException::withMessages([
                    'surum' => __('hata.tasarim_surum_yok'),
                ]);
            }

            // Açık taslak varsa üzerine yazılır (tek taslak kuralı)
            EkranTasarimi::query()
                ->where('ekran_anahtari', $ekranAnahtari)
                ->where('durum', EkranTasarimi::DURUM_TASLAK)
                ->delete();

            return EkranTasarimi::query()->create([
                'ekran_anahtari' => $ekranAnahtari,
                'surum' => $this->sonrakiSurum($ekranAnahtari),
                'durum' => EkranTasarimi::DURUM_TASLAK,
                'duzen' => $kaynak->duzen,
                'notlar' => __('tasarim.geri_alindi', ['surum' => $surum]),
                'olusturan_id' => $kullaniciId,
            ]);
        });
    }

    /**
     * Düzeni katalogla karşılaştırıp temizlenmiş halini döner. Bilinmeyen alan,
     * geçersiz genişlik, tekrar eden alan ve kaldırılamaz alanın eksikliği hata.
     *
     * @param  array<string, mixed>  $duzen
     * @return array{bolumler: list<array<string, mixed>>, onay_rol_id: int|null}
     */
    /**
     * Satır ızgarasının düzeni: sıra, görünürlük ve PİKSEL genişlik.
     *
     * Başlıktaki 12'lik ızgara burada işe yaramaz — satırlar yatay kaydırılır,
     * kolon içeriği kadar yer kaplamalıdır. Katalogda olmayan kolon atılır,
     * kaldırılamaz kolon (Ürün kodu) gizlenemez, ERP'nin doldurduğu kolonlar
     * salt okunur kalır.
     *
     * @return list<array<string, mixed>>
     */
    private function satirDuzeniniDogrula(mixed $satirlar, string $birim = 'px'): array
    {
        // Yüzde 1-100, piksel 64-640 aralığında anlamlı
        [$enAz, $enCok] = $birim === 'yuzde' ? [1, 100] : [64, 640];
        $katalogAlanlari = [];
        foreach ($this->satirKatalogu() as $alan) {
            $katalogAlanlari[$alan['anahtar']] = $alan;
        }

        if (! is_array($satirlar) || $satirlar === []) {
            return array_map(
                static fn (array $alan): array => [
                    'alan' => $alan['anahtar'],
                    'genislik' => $alan['varsayilan_genislik'],
                    'gizli' => false,
                    'varsayilan' => '',
                ],
                $katalogAlanlari === [] ? $this->satirKatalogu() : array_values($katalogAlanlari),
            );
        }

        $temiz = [];
        $gorulen = [];
        foreach ($satirlar as $kolon) {
            $anahtar = is_array($kolon) ? ($kolon['alan'] ?? null) : null;
            if (! is_string($anahtar) || ! isset($katalogAlanlari[$anahtar]) || isset($gorulen[$anahtar])) {
                continue;
            }
            $gorulen[$anahtar] = true;
            $katalogAlani = $katalogAlanlari[$anahtar];
            $genislik = $kolon['genislik'] ?? null;

            $temiz[] = [
                'alan' => $anahtar,
                // Aşırı dar/geniş değerler ızgarayı kullanılamaz hale getirir
                'genislik' => is_numeric($genislik)
                    ? max($enAz, min($enCok, (int) $genislik))
                    : $katalogAlani['varsayilan_genislik'],
                'gizli' => ($katalogAlani['kaldirilamaz'] ?? false)
                    ? false
                    : (bool) ($kolon['gizli'] ?? false),
                // Gizlenen kolon da ERP'ye değer göndermeli (ör. birim fiyatı
                // ekranda yok ama satıra 0 yazılmalı)
                'varsayilan' => is_scalar($kolon['varsayilan'] ?? null)
                    ? trim((string) $kolon['varsayilan'])
                    : '',
                // Başlık alanlarındaki iki ayarın satır karşılığı. Gizli kolon
                // doldurulamayacağı için zorunlu sayılmaz; ERP'nin hesapladığı
                // kolonlar zaten daima salt okunur.
                'zorunlu' => ($kolon['gizli'] ?? false) === true
                    ? false
                    : (bool) ($kolon['zorunlu'] ?? false),
                'salt_okunur' => ($katalogAlani['salt_okunur_sabit'] ?? false)
                    ? true
                    : (bool) ($kolon['salt_okunur'] ?? false),
            ];
        }

        // Katalogda olup düzende bulunmayan kolonlar sona eklenir: yeni kolon
        // geldiğinde eski tasarım onu sessizce yutmasın
        foreach ($katalogAlanlari as $anahtar => $alan) {
            if (! isset($gorulen[$anahtar])) {
                $temiz[] = [
                    'alan' => $anahtar,
                    'genislik' => $alan['varsayilan_genislik'],
                    'gizli' => false,
                    'varsayilan' => '',
                    'zorunlu' => false,
                    'salt_okunur' => (bool) ($alan['salt_okunur_sabit'] ?? false),
                ];
            }
        }

        return $temiz;
    }

    public function duzeniDogrula(EkranKatalogu $katalog, array $duzen): array
    {
        /** @var array<string, KatalogAlani> $katalogAlanlari */
        $katalogAlanlari = [];
        foreach ($katalog->alanlar() as $alan) {
            $katalogAlanlari[$alan->anahtar] = $alan;
        }
        $gecerliBolumler = array_column($katalog->bolumler(), 'anahtar');

        $bolumler = $duzen['bolumler'] ?? null;
        if (! is_array($bolumler) || $bolumler === []) {
            throw ValidationException::withMessages([
                'duzen' => __('hata.tasarim_bolum_yok'),
            ]);
        }

        $gorulenAlanlar = [];
        $temizBolumler = [];

        foreach ($bolumler as $bolum) {
            if (! is_array($bolum) || ! in_array($bolum['anahtar'] ?? null, $gecerliBolumler, true)) {
                throw ValidationException::withMessages([
                    'duzen' => __('hata.tasarim_bolum_gecersiz'),
                ]);
            }

            $temizAlanlar = [];
            foreach ($bolum['alanlar'] ?? [] as $satir) {
                $alanAnahtari = is_array($satir) ? ($satir['alan'] ?? null) : null;
                $alan = is_string($alanAnahtari) ? ($katalogAlanlari[$alanAnahtari] ?? null) : null;

                if ($alan === null) {
                    throw ValidationException::withMessages([
                        'duzen' => __('hata.tasarim_alan_gecersiz', ['alan' => (string) $alanAnahtari]),
                    ]);
                }

                if (isset($gorulenAlanlar[$alan->anahtar])) {
                    throw ValidationException::withMessages([
                        'duzen' => __('hata.tasarim_alan_tekrar', ['alan' => $alan->anahtar]),
                    ]);
                }
                $gorulenAlanlar[$alan->anahtar] = true;

                $genislik = (int) ($satir['genislik'] ?? $alan->varsayilanGenislik);
                if ($genislik < 1 || $genislik > 12) {
                    throw ValidationException::withMessages([
                        'duzen' => __('hata.tasarim_genislik_gecersiz', ['alan' => $alan->anahtar]),
                    ]);
                }

                $temiz = [
                    'alan' => $alan->anahtar,
                    'genislik' => $genislik,
                ];

                // Kilitli alanlar tasarımdan gevşetilemez
                $saltOkunur = $alan->saltOkunurSabit || (bool) ($satir['salt_okunur'] ?? false);
                if ($saltOkunur) {
                    $temiz['salt_okunur'] = true;
                }

                // Gizli alan: formda ÇİZİLMEZ ama değeri (varsayılanı) kayda gider.
                // ERP'de "bu alanı göstermiyoruz ama hep Projemiz seçili" davranışı.
                $gizli = (bool) ($satir['gizli'] ?? false);
                if ($gizli) {
                    $temiz['gizli'] = true;
                }

                // Gizli alanda zorunluluk anlamsız — kullanıcı dolduramaz
                if (! $gizli && $alan->zorunluSecilebilir && (bool) ($satir['zorunlu'] ?? false)) {
                    $temiz['zorunlu'] = true;
                }

                if ($alan->metinAlani && ($satir['gorunum'] ?? '') === 'textarea') {
                    $temiz['gorunum'] = 'textarea';
                    $temiz['satir'] = max(1, min(10, (int) ($satir['satir'] ?? 2)));
                }

                if (isset($satir['varsayilan']) && is_string($satir['varsayilan']) && $satir['varsayilan'] !== '') {
                    $temiz['varsayilan'] = $satir['varsayilan'];
                }

                $temizAlanlar[] = $temiz;
            }

            $bolumGenisligi = (int) ($bolum['genislik'] ?? 12);
            if ($bolumGenisligi < 1 || $bolumGenisligi > 12) {
                throw ValidationException::withMessages([
                    'duzen' => __('hata.tasarim_genislik_gecersiz', ['alan' => (string) $bolum['anahtar']]),
                ]);
            }

            $temizBolum = [
                'anahtar' => $bolum['anahtar'],
                'genislik' => $bolumGenisligi,
                'alanlar' => $temizAlanlar,
            ];

            // Bölüm başlığı: anahtar YOKSA katalogun i18n varsayılanı kullanılır
            // (çeviri korunur). Anahtar VARSA — boş dahil — o kullanılır; boş
            // başlık "bu bölümde başlık gösterme" demektir.
            // Not: Laravel boş dizgeyi null'a çevirir (ConvertEmptyStringsToNull),
            // bu yüzden null da "boş başlık" sayılır.
            if (array_key_exists('baslik', $bolum)) {
                $ham = $bolum['baslik'];
                $temizBolum['baslik'] = is_string($ham) ? mb_substr(trim($ham), 0, 120) : '';
            }

            $temizBolumler[] = $temizBolum;
        }

        // Proc'un onsuz çalışmadığı alanlar tasarımdan çıkarılamaz — aksi halde
        // kaydedilemeyen bir form tasarlanmış olurdu
        foreach ($katalogAlanlari as $alan) {
            if ($alan->kaldirilamaz && ! isset($gorulenAlanlar[$alan->anahtar])) {
                throw ValidationException::withMessages([
                    'duzen' => __('hata.tasarim_alan_zorunlu', ['alan' => $alan->anahtar]),
                ]);
            }
        }

        // Onay rolü ekranın bir ayarıdır, alan değil: talep onaya sunulurken
        // hangi ROL_ID kullanılacağını belirler (ERP'de VOHOM_ARAMA_ONAY_ROLU)
        $onayRolId = $duzen['onay_rol_id'] ?? null;
        $birim = ($duzen['satir_genislik_birimi'] ?? '') === 'yuzde' ? 'yuzde' : 'px';

        return [
            'bolumler' => $temizBolumler,
            'onay_rol_id' => is_numeric($onayRolId) ? (int) $onayRolId : null,
            // px: ızgara içeriğe göre genişler, yatay kaydırılır.
            // yuzde: ızgara ekranı doldurur, kolonlar payları kadar yer alır.
            'satir_genislik_birimi' => $birim,
            'satirlar' => $this->satirDuzeniniDogrula($duzen['satirlar'] ?? null, $birim),
        ];
    }

    private function sonrakiSurum(string $ekranAnahtari): int
    {
        return (int) EkranTasarimi::query()
            ->where('ekran_anahtari', $ekranAnahtari)
            ->max('surum') + 1;
    }
}
