<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kullanıcı yönetimi (EFAT-18): ERP kullanıcılarına rol atanır; ERP'de
 * olmayan kişiler için lokal kullanıcı açılır.
 *
 * Kurallar:
 * - Lokal kullanıcı adı ERP'de varsa açılmaz (giriş önce lokale baktığı için
 *   o ERP kullanıcısı bir daha giremezdi); ERP'ye ulaşılamazsa da açılmaz.
 * - ERP kullanıcısının adı/e-postası/şifresi ERP'den gelir, burada değişmez.
 * - Kimse kendini pasife alamaz; `.env` yedek admini pasife alınamaz.
 * - `sistem_yoneticisi` bu ekrandan verilmez (ERP'den ya da yedek adminden gelir).
 * - Silme yok: pasife alma (geçmiş kayıtlarla bağ korunur).
 */
final class KullaniciYonetimServisi
{
    public function __construct(
        private readonly ErpKimlikDogrulayici $erp,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function listele(): Collection
    {
        return User::query()->with('roller:id')->orderBy('ad')->get();
    }

    /**
     * @param  array{kullanici_adi: string, ad: string, email?: string|null, sifre: string, aktif_mi?: bool, rol_idleri?: list<int>}  $veri
     */
    public function lokalOlustur(array $veri): User
    {
        if ($this->erp->kullaniciVarMi($veri['kullanici_adi'])) {
            throw ValidationException::withMessages([
                'kullanici_adi' => __('hata.kullanici_adi_erpde_var'),
            ]);
        }

        return DB::transaction(function () use ($veri): User {
            $user = User::query()->create([
                'kullanici_adi' => $veri['kullanici_adi'],
                'ad' => $veri['ad'],
                'email' => $veri['email'] ?? null,
                'kaynak' => User::KAYNAK_LOKAL,
                'password' => $veri['sifre'],
                'aktif_mi' => $veri['aktif_mi'] ?? true,
                'sistem_yoneticisi' => false,
            ]);

            $user->roller()->sync($veri['rol_idleri'] ?? []);

            return $user;
        });
    }

    /**
     * @param  array{ad?: string, email?: string|null, sifre?: string|null, aktif_mi?: bool, rol_idleri?: list<int>}  $veri
     */
    public function guncelle(User $hedef, User $yapan, array $veri): User
    {
        $kimlikAlanlari = array_filter(
            array_intersect_key($veri, array_flip(['ad', 'email', 'sifre'])),
            fn (mixed $deger): bool => $deger !== null && $deger !== '',
        );

        if ($hedef->kaynak === User::KAYNAK_ERP && $kimlikAlanlari !== []) {
            throw ValidationException::withMessages([
                'ad' => __('hata.erp_kullanici_bilgisi_ekrandan_degismez'),
            ]);
        }

        // `boolean` kuralı 0/"0"/"false" da kabul eder ve ham değeri döndürür:
        // kilitler katı `=== false` ile değil normalize değerle denetlenir
        $aktif = array_key_exists('aktif_mi', $veri) ? filter_var($veri['aktif_mi'], FILTER_VALIDATE_BOOLEAN) : null;

        if ($aktif === false) {
            if ($hedef->is($yapan)) {
                throw ValidationException::withMessages(['aktif_mi' => __('hata.kendini_pasif_yapamaz')]);
            }

            if ($hedef->yedekAdminMi()) {
                throw ValidationException::withMessages(['aktif_mi' => __('hata.yedek_admin_pasif_yapilamaz')]);
            }
        }

        return DB::transaction(function () use ($hedef, $veri, $aktif): User {
            if ($hedef->kaynak === User::KAYNAK_LOKAL) {
                if (isset($veri['ad'])) {
                    $hedef->ad = $veri['ad'];
                }

                if (array_key_exists('email', $veri)) {
                    $hedef->email = $veri['email'] === '' ? null : $veri['email'];
                }

                if (($veri['sifre'] ?? '') !== '') {
                    $hedef->password = $veri['sifre'];
                }
            }

            if ($aktif !== null) {
                $hedef->aktif_mi = $aktif;
            }

            $hedef->save();

            if (array_key_exists('rol_idleri', $veri)) {
                $hedef->roller()->sync($veri['rol_idleri']);
            }

            return $hedef;
        });
    }
}
