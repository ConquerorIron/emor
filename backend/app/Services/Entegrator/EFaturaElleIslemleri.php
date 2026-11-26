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
 * - değişiklik yoksa (zaten RED, zaten gizli) hiçbir şey yazmaz, ilk bilgi korunur.
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
        $sonuc = DB::transaction(function () use ($fatura, $red, $neden, $kullanici): EFatura {
            $kayit = $this->kilitle($fatura);

            if ($red) {
                if ($kayit->kaynaktaReddedildi()) {
                    throw EFaturaIslemHatasi::kaynaktaReddedilmis();
                }
                if ($kayit->harici_red_zamani !== null) {
                    return $kayit;
                }
                if ($kayit->sap_islendi === true) {
                    throw EFaturaIslemHatasi::islendiIsaretli();
                }
                if (in_array($kayit->emor_durumu?->value, EFatura::ISLENMIS_EMOR_DURUMLARI, true)) {
                    throw EFaturaIslemHatasi::emorIslenmis();
                }

                $kayit->update([
                    'harici_red_zamani' => now(),
                    'harici_red_eden_id' => $kullanici->id,
                    'harici_red_nedeni' => $neden,
                ]);
                $this->gecmiseYaz($kayit, EFaturaIslemTuru::RedeCekildi, $kullanici, neden: $neden);

                return $kayit;
            }

            if ($kayit->harici_red_zamani === null) {
                return $kayit;
            }

            $oncekiNeden = $kayit->harici_red_nedeni;
            $kayit->update(['harici_red_zamani' => null, 'harici_red_eden_id' => null, 'harici_red_nedeni' => null]);
            $this->gecmiseYaz($kayit, EFaturaIslemTuru::RedGeriAlindi, $kullanici, onceki: $oncekiNeden, neden: $neden);

            return $kayit;
        });

        $this->denetimeYaz($red ? 'e-Fatura RED\'e çekildi' : 'e-Fatura RED\'i geri alındı', $sonuc, $kullanici);

        return $sonuc;
    }

    /**
     * "İşlendi" (SAP) işareti: true, kaldırılınca NULL. RED faturasında
     * işaretlenemez; işaret kaldırmak RED'de de serbesttir (eski veriyi düzeltir).
     */
    public function islendiDegistir(EFatura $fatura, bool $islendi, User $kullanici): EFatura
    {
        $sonuc = DB::transaction(function () use ($fatura, $islendi, $kullanici): EFatura {
            $kayit = $this->kilitle($fatura);

            if ($islendi && $kayit->reddedildi()) {
                throw EFaturaIslemHatasi::reddedildi();
            }
            if (($kayit->sap_islendi === true) === $islendi) {
                return $kayit;
            }

            $kayit->update(['sap_islendi' => $islendi ?: null]);
            $this->gecmiseYaz($kayit, $islendi ? EFaturaIslemTuru::IslendiIsaretlendi : EFaturaIslemTuru::IslendiKaldirildi, $kullanici);

            return $kayit;
        });

        $this->denetimeYaz($islendi ? 'e-Fatura işlendi işaretlendi' : 'e-Fatura işlendi işareti kaldırıldı', $sonuc, $kullanici);

        return $sonuc;
    }

    /** Gizleme (yanlış posta kutusu, 2026-09-24): zaten gizliyse ilk gizleme bilgisi korunur. */
    public function gizlemeDegistir(EFatura $fatura, bool $gizli, User $kullanici): EFatura
    {
        $sonuc = DB::transaction(function () use ($fatura, $gizli, $kullanici): EFatura {
            $kayit = $this->kilitle($fatura);

            if (($kayit->gizlenme_zamani !== null) === $gizli) {
                return $kayit;
            }

            $kayit->update($gizli
                ? ['gizlenme_zamani' => now(), 'gizleyen_id' => $kullanici->id]
                : ['gizlenme_zamani' => null, 'gizleyen_id' => null]);
            $this->gecmiseYaz($kayit, $gizli ? EFaturaIslemTuru::Gizlendi : EFaturaIslemTuru::GizlemeKaldirildi, $kullanici);

            return $kayit;
        });

        // Liste, özet, Excel ve alarmı etkiler: kim ne zaman yaptı izi kalır
        $this->denetimeYaz($gizli ? 'e-Fatura gizlendi' : 'e-Fatura listeye geri alındı', $sonuc, $kullanici);

        return $sonuc;
    }

    /**
     * İşlem geçmişi kaydı. Çağıranın veritabanı işlemi içinde çağrılır; fatura
     * ya da parti silinse de okunur kalsın diye kimlikler kopya yazılır.
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
            'belge_no' => $fatura?->belge_no,
            'belge_kimligi' => $fatura === null ? null : ($fatura->belge_anahtari ?? $fatura->ettn),
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

    private function kilitle(EFatura $fatura): EFatura
    {
        return EFatura::query()->lockForUpdate()->findOrFail($fatura->id);
    }

    private function denetimeYaz(string $mesaj, EFatura $kayit, User $kullanici): void
    {
        Log::channel('denetim')->info($mesaj, [
            'kullanici_id' => $kullanici->id,
            'fatura_id' => $kayit->id,
            'belge_no' => $kayit->belge_no,
        ]);
    }
}
