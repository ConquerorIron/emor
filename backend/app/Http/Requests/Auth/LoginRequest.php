<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'kullanici_adi' => ['required', 'string', 'max:128'],
            'sifre' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Kimlik doğrulama: önce lokal kullanıcı (kurulum/acil durum admin'i),
     * yoksa ERP MSSQL doğrulaması. ERP başarısında kullanıcı yerel tabloya
     * yansıtılır (upsert) ve oturum açılır.
     */
    public function authenticate(ErpKimlikDogrulayici $erp): User
    {
        $kullaniciAdi = $this->string('kullanici_adi')->trim()->value();
        $sifre = $this->string('sifre')->value();

        $lokal = User::query()
            ->where('kullanici_adi', $kullaniciAdi)
            ->where('kaynak', User::KAYNAK_LOKAL)
            ->first();

        if ($lokal !== null) {
            if ($lokal->password === null || ! Hash::check($sifre, $lokal->password)) {
                $this->basarisiz();
            }

            return $this->girisYap($lokal);
        }

        if (! $erp->yapilandirildi()) {
            $this->basarisiz();
        }

        $erpKullanici = $erp->dogrula($kullaniciAdi, $sifre);

        if ($erpKullanici === null) {
            $this->basarisiz();
        }

        $user = User::query()->updateOrCreate(
            ['kullanici_adi' => $erpKullanici['kullanici_adi'], 'kaynak' => User::KAYNAK_ERP],
            [
                'ad' => $erpKullanici['ad'],
                'erp_kullanici_id' => $erpKullanici['erp_kullanici_id'],
                // ERP'deki yetki her girişte tazelenir
                'sistem_yoneticisi' => $erpKullanici['sistem_yoneticisi'],
            ],
        );

        // Yeni kayıtta DB default'ları (aktif_mi=true) modele yüklensin;
        // mevcut pasif kullanıcı ise pasif kalır (girisYap engeller)
        if ($user->wasRecentlyCreated) {
            $user->refresh();
        }

        return $this->girisYap($user);
    }

    private function girisYap(User $user): User
    {
        if (! $user->aktif_mi) {
            throw ValidationException::withMessages([
                'kullanici_adi' => __('auth.pasif'),
            ]);
        }

        Auth::guard('web')->login($user);

        return $user;
    }

    private function basarisiz(): never
    {
        throw ValidationException::withMessages([
            'kullanici_adi' => __('auth.failed'),
        ]);
    }
}
