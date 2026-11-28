<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EFaturaIslemi;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ekrandan yapılan elle e-Fatura işlemleri: RED'e çekme / geri alma (EK-04),
 * "İşlendi" işareti, gizleme. Her işlem:
 *
 * - satırı kilitler ve kuralı KİLİT ALTINDA denetler (Dikkat_Edilecekler G18:
 *   iki eşzamanlı istek aynı faturayı hem RED hem İşlendi yapamaz);
 * - durum değişikliğini ve işlem geçmişi kaydını (EK-03) AYNI veritabanı
 *   işleminde yazar — biri olmadan diğeri kalmaz;
 * - değişiklik yoksa (zaten RED, zaten gizli) hiçbir şey yazmaz — ne geçmişe ne
 *   denetim günlüğüne; ilk bilgi korunur.
 *
 * Dış sisteme (İzibiz, Protel, ERP) istek gitmez: bütün işaretler eMOR'dadır.
 */
final class EFaturaElleIslemleri
{
    /**
     * Her kaynağın gelen faturası RED'e çekilebilir; işlenmiş fatura çekilemez
     * (kullanıcı isteği 2026-11-26): eMOR'da İşlendi / İşlendi (elle) ya da
     * "SAP (Manuel İşle)" işaretli. Geri alma her durumda açıktır.
     */
    public function redDegistir(EFatura $fatura, bool $red, ?string $neden, User $kullanici): EFatura
    {
        /** @var array{0: EFatura, 1: bool} $sonuc */
        $sonuc = DB::transaction(function () use ($fatura, $red, $neden, $kullanici): array {
            $kayit = $this->kilitle($fatura);

            if ($red) {
                if ($kayit->kaynaktaReddedildi()) {
                    throw EFaturaIslemHatasi::kaynaktaReddedilmis();
                }
                if ($kayit->harici_red_zamani !== null) {
                    return [$kayit, false];
                }
                if ($kayit->sap_islendi === true) {
                    throw EFaturaIslemHatasi::islendiIsaretli();
                }
                if ($kayit->emor_durumu === null) {
                    throw EFaturaIslemHatasi::emorBilinmiyor();
                }
                if (in_array($kayit->emor_durumu->value, EFatura::ISLENMIS_EMOR_DURUMLARI, true)) {
                    throw EFaturaIslemHatasi::emorIslenmis();
                }

                $kayit->update([
                    'harici_red_zamani' => now(),
                    'harici_red_eden_id' => $kullanici->id,
                    'harici_red_nedeni' => $neden,
                ]);
                $this->gecmiseYaz($kayit, EFaturaIslemTuru::RedeCekildi, $kullanici, neden: $neden);

                return [$kayit, true];
            }

            if ($kayit->harici_red_zamani === null) {
                return [$kayit, false];
            }

            $oncekiNeden = $kayit->harici_red_nedeni;
            $kayit->update(['harici_red_zamani' => null, 'harici_red_eden_id' => null, 'harici_red_nedeni' => null]);
            $this->gecmiseYaz($kayit, EFaturaIslemTuru::RedGeriAlindi, $kullanici, onceki: $oncekiNeden, neden: $neden);

            return [$kayit, true];
        });

        return $this->denetimeYaz($red ? 'e-Fatura RED\'e çekildi' : 'e-Fatura RED\'i geri alındı', $sonuc, $kullanici);
    }

    /**
     * "İşlendi" (SAP) işareti: true, kaldırılınca NULL. RED faturasında
     * işaretlenemez; işaret kaldırmak RED'de de serbesttir (eski veriyi düzeltir).
     */
    public function islendiDegistir(EFatura $fatura, bool $islendi, User $kullanici): EFatura
    {
        /** @var array{0: EFatura, 1: bool} $sonuc */
        $sonuc = DB::transaction(function () use ($fatura, $islendi, $kullanici): array {
            $kayit = $this->kilitle($fatura);

            if ($islendi && $kayit->reddedildi()) {
                throw EFaturaIslemHatasi::reddedildi();
            }
            if (($kayit->sap_islendi === true) === $islendi) {
                return [$kayit, false];
            }

            $kayit->update(['sap_islendi' => $islendi ?: null]);
            $this->gecmiseYaz($kayit, $islendi ? EFaturaIslemTuru::IslendiIsaretlendi : EFaturaIslemTuru::IslendiKaldirildi, $kullanici);

            return [$kayit, true];
        });

        return $this->denetimeYaz($islendi ? 'e-Fatura işlendi işaretlendi' : 'e-Fatura işlendi işareti kaldırıldı', $sonuc, $kullanici);
    }

    /** Gizleme (yanlış posta kutusu, 2026-09-24): zaten gizliyse ilk gizleme bilgisi korunur. */
    public function gizlemeDegistir(EFatura $fatura, bool $gizli, User $kullanici): EFatura
    {
        /** @var array{0: EFatura, 1: bool} $sonuc */
        $sonuc = DB::transaction(function () use ($fatura, $gizli, $kullanici): array {
            $kayit = $this->kilitle($fatura);

            if (($kayit->gizlenme_zamani !== null) === $gizli) {
                return [$kayit, false];
            }

            $kayit->update($gizli
                ? ['gizlenme_zamani' => now(), 'gizleyen_id' => $kullanici->id]
                : ['gizlenme_zamani' => null, 'gizleyen_id' => null]);
            $this->gecmiseYaz($kayit, $gizli ? EFaturaIslemTuru::Gizlendi : EFaturaIslemTuru::GizlemeKaldirildi, $kullanici);

            return [$kayit, true];
        });

        // Liste, özet, Excel ve alarmı etkiler: kim ne zaman yaptı izi kalır
        return $this->denetimeYaz($gizli ? 'e-Fatura gizlendi' : 'e-Fatura listeye geri alındı', $sonuc, $kullanici);
    }

    /**
     * İşlem geçmişi kaydı. Çağıranın veritabanı işlemi içinde çağrılır; fatura
     * ya da parti silinse de okunur kalsın diye kimlikler, sağlayıcı ve tanımın
     * ortamı (test / canlı — inceleme A-9) kopya yazılır.
     *
     * @param  array<string, mixed>  $ayrinti
     */
    public function gecmiseYaz(
        ?EFatura $fatura,
        EFaturaIslemTuru $islem,
        ?User $kullanici,
        ?string $onceki = null,
        ?string $yeni = null,
        ?string $neden = null,
        array $ayrinti = [],
        ?EntegratorBaglanti $tanim = null,
        ?int $yuklemeId = null,
    ): EFaturaIslemi {
        $tanim ??= $fatura?->entegratorBaglanti;

        return EFaturaIslemi::query()->create([
            'fatura_id' => $fatura?->id,
            'yukleme_id' => $yuklemeId,
            'saglayici' => (string) $tanim?->saglayici,
            'ortam' => $tanim?->ortam,
            'belge_no' => $fatura?->belge_no,
            'belge_kimligi' => $this->belgeKimligi($fatura, $tanim),
            'islem' => $islem,
            'onceki' => $onceki === null ? null : mb_substr($onceki, 0, 255),
            'yeni' => $yeni === null ? null : mb_substr($yeni, 0, 255),
            'neden' => $neden,
            'ayrinti' => $ayrinti === [] ? null : $ayrinti,
            'kullanici_id' => $kullanici?->id,
            'kullanici_adi' => $kullanici === null ? '' : (string) $kullanici->ad,
            'created_at' => now(),
        ]);
    }

    /**
     * Geçmişe yazılan belge kimliği: ETTN ya da statik kaynağın belge anahtarı.
     * e-Arşiv / e-SMM anahtarı (`tür|VKN-TCKN|no`) göndericinin kimlik nosunu
     * taşır; salt eklemeli geçmişe yalnız `tür|no` kopyalanır (inceleme B-12,
     * kullanıcı kararı 2026-11-27; eski kayıtlara dokunulmaz).
     */
    private function belgeKimligi(?EFatura $fatura, ?EntegratorBaglanti $tanim): ?string
    {
        if ($fatura === null) {
            return null;
        }

        if ($tanim?->saglayici === EntegratorBaglanti::SAGLAYICI_EARSIV) {
            $parcalar = explode('|', (string) $fatura->belge_anahtari);

            return $fatura->belge_turu.'|'.(count($parcalar) === 3 ? $parcalar[2] : $fatura->belge_no);
        }

        return $fatura->belge_anahtari ?? $fatura->ettn;
    }

    private function kilitle(EFatura $fatura): EFatura
    {
        return EFatura::query()->lockForUpdate()->findOrFail($fatura->id);
    }

    /**
     * Denetim günlüğü yalnız değişiklikte: işlemsiz tekrar istek (zaten RED)
     * "yapıldı" diye yazılmaz (geçmişle aynı olayı farklı anlatmasın — inceleme B-11).
     *
     * @param  array{0: EFatura, 1: bool}  $sonuc  [kayıt, değişti mi]
     */
    private function denetimeYaz(string $mesaj, array $sonuc, User $kullanici): EFatura
    {
        [$kayit, $degisti] = $sonuc;

        if ($degisti) {
            Log::channel('denetim')->info($mesaj, [
                'kullanici_id' => $kullanici->id,
                'fatura_id' => $kayit->id,
                'belge_no' => $kayit->belge_no,
            ]);
        }

        return $kayit;
    }
}
