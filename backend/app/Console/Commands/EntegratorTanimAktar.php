<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\Ayar\EntegratorBaglantiGuncelleRequest;
use App\Models\EntegratorBaglanti;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * Entegratör tanımını tek seferlik içe aktarır (EFAT-06).
 *
 * Şifre komut satırına YAZILMAZ: tanım bir JSON dosyasından ya da pipe ile
 * standart girdiden (`--dosya=-`) okunur; çıktıya ve loga şifre düşmez.
 * Tekrar çalıştırmak kayıt çoğaltmaz; farklı bir tanım varsa `--uzerine-yaz`
 * verilmeden dokunulmaz. SQL ortamına dokunmaz; `--aktif-yap` verilmezse
 * aktif ortamı değiştirmez.
 *
 * JSON: {"kullanici_adi", "sifre", "vkn", "posta_kutusu"?, "gonderici_birim"?}
 */
#[Signature('entegrator:tanim-aktar
    {ortam : test | canli}
    {--dosya= : Tanım JSON dosyası (pipe ile standart girdi için -)}
    {--uzerine-yaz : Farklı mevcut tanımı güncelle}
    {--aktif-yap : Aktarımdan sonra bu ortamı aktif entegratör ortamı yap}')]
#[Description('Entegratör (İzibiz) bağlantı tanımını tek seferlik içe aktarır')]
final class EntegratorTanimAktar extends Command
{
    public function handle(EntegratorBaglantiServisi $servis): int
    {
        $ortam = (string) $this->argument('ortam');

        if (! in_array($ortam, EntegratorBaglanti::ORTAMLAR, true)) {
            $this->error('Ortam "test" veya "canli" olmalı.');

            return self::INVALID;
        }

        $veri = $this->tanimiOku();

        if ($veri === null) {
            return self::INVALID;
        }

        $dogrulayici = Validator::make(
            $veri,
            [...EntegratorBaglantiGuncelleRequest::kurallar(), 'sifre' => ['required', 'string', 'max:255']],
            EntegratorBaglantiGuncelleRequest::mesajlar(),
        );

        if ($dogrulayici->fails()) {
            // Mesajlar alan adlarını söyler, değerleri (şifreyi) içermez
            foreach ($dogrulayici->errors()->all() as $mesaj) {
                $this->error($mesaj);
            }

            return self::INVALID;
        }

        /** @var array{kullanici_adi: string, sifre: string, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null, api_url?: string|null} $gecerli */
        $gecerli = $dogrulayici->validated();

        $mevcut = $servis->tanim($ortam);

        if ($mevcut !== null && $this->ayni($mevcut, $gecerli)) {
            $this->info("{$ortam} tanımı zaten güncel; değişiklik yapılmadı.");
        } elseif ($mevcut !== null && ! $this->option('uzerine-yaz')) {
            $this->warn("{$ortam} için farklı bir tanım var; dokunulmadı. Güncellemek için --uzerine-yaz verin.");

            return self::FAILURE;
        } else {
            $servis->guncelle($ortam, $gecerli);
            $this->info($mevcut === null ? "{$ortam} tanımı oluşturuldu." : "{$ortam} tanımı güncellendi.");
        }

        if ($this->option('aktif-yap')) {
            $servis->aktifYap($ortam);
            $this->info("Aktif entegratör ortamı: {$ortam}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tanimiOku(): ?array
    {
        $dosya = $this->option('dosya');

        if (! is_string($dosya) || $dosya === '') {
            $this->error('--dosya zorunlu (pipe için --dosya=-).');

            return null;
        }

        // '-' standart girdidir; WSL'de /dev/stdin file_get_contents ile okunamıyor
        $icerik = @file_get_contents($dosya === '-' ? 'php://stdin' : $dosya);

        if ($icerik === false) {
            $this->error('Tanım dosyası okunamadı.');

            return null;
        }

        try {
            $veri = json_decode($icerik, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // İçerik (şifre) hata mesajına konmaz
            $this->error('Tanım dosyası geçerli JSON değil.');

            return null;
        }

        if (! is_array($veri)) {
            $this->error('Tanım dosyası bir JSON nesnesi olmalı.');

            return null;
        }

        return $veri;
    }

    /**
     * @param  array{kullanici_adi: string, sifre: string, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null, api_url?: string|null}  $veri
     */
    private function ayni(EntegratorBaglanti $mevcut, array $veri): bool
    {
        return $mevcut->kullanici_adi === $veri['kullanici_adi']
            && hash_equals($mevcut->sifre, $veri['sifre'])
            && $mevcut->vkn === $veri['vkn']
            && $mevcut->posta_kutusu === ($veri['posta_kutusu'] ?? null)
            && $mevcut->gonderici_birim === ($veri['gonderici_birim'] ?? null)
            // Adres verilmediyse karşılaştırılmaz (değişmez); verildiyse aynı olmalı
            && (! array_key_exists('api_url', $veri)
                || EntegratorBaglantiServisi::saklanacakAdres($veri['api_url']) === $mevcut->api_url);
    }
}
