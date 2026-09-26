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

        // Yalnız Kullanıcılar ekranında tanımlanmış (giriş izni verilmiş) ERP
        // kullanıcıları girer (kullanıcı kararı 2026-09-24). İstisna: ERP sistem
        // yöneticisi — ilk girişte tanımlanır, yoksa uygulama yöneticisiz kalabilirdi.
        $user = User::query()
            ->where('kaynak', User::KAYNAK_ERP)
            ->where('erp_kullanici_id', $erpKullanici['erp_kullanici_id'])
            ->first();

        // Kullanıcı adı ERP'de başka bir kişiye verilebilir; izinler yalnız
        // kalıcı ERP kimliğine aittir. Ad çakışması eski hesabı devralamaz.
        $adBaskasinda = User::query()
            ->where('kullanici_adi', $erpKullanici['kullanici_adi'])
            ->when($user !== null, fn ($q) => $q->whereKeyNot($user->id))
            ->exists();

        if ($adBaskasinda || ($user === null && ! $erpKullanici['sistem_yoneticisi'])) {
            throw ValidationException::withMessages([
                'kullanici_adi' => __('auth.izin_yok'),
            ]);
        }

        $user ??= new User(['kaynak' => User::KAYNAK_ERP, 'aktif_mi' => true]);
        $user->fill([
            'kullanici_adi' => $erpKullanici['kullanici_adi'],
            'ad' => $erpKullanici['ad'],
            'erp_kullanici_id' => $erpKullanici['erp_kullanici_id'],
            // ERP'deki yetki her girişte tazelenir
            'sistem_yoneticisi' => $erpKullanici['sistem_yoneticisi'],
        ])->save();

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
