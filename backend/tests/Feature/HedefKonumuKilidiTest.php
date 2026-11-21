<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\YedekKopyalayici;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-016-R3: hedefin konum denetimi + kaydı ile kopyanın "kopyalanıyor"
 * kaydı aynı satır kilidini paylaşır. SQLite'ta satır kilidi yoktur; yalnız
 * PostgreSQL. İkinci bağlantı öbür işlemi (açık, kaydedilmemiş) taklit eder;
 * bekleme `lock_timeout` ile görünür olur.
 */
final class HedefKonumuKilidiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private ConnectionInterface $ikinci;

    private YedekHedefi $hedef;

    private Yedek $yedek;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite satır kilidi (FOR UPDATE) desteklemez; yalnız PostgreSQL.');
        }

        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        // Önce testin bağlantısında bir sorgu: tembel yenileme (gerekirse migrate:fresh) ikinci bağlantının kayıtlarından önce olsun
        DB::statement("SET lock_timeout = '300ms'");
        config(['database.connections.ikinci' => config('database.connections.'.config('database.default'))]);
        $this->ikinci = DB::connection('ikinci');
        // Testin başarısız yolunda da asılı kalınmasın
        $this->ikinci->statement("SET lock_timeout = '5s'");

        // Öbür işlemin görebilmesi için ikinci bağlantıdan kaydedilir (testin işlemi dışında)
        $this->hedef = YedekHedefi::factory()->make(['ad' => 'Kilit '.bin2hex(random_bytes(4)), 'baglanti' => ['klasor' => '/eski']]);
        $this->hedef->setConnection('ikinci')->save();
        $this->yedek = Yedek::factory()->make();
        $this->yedek->setConnection('ikinci')->save();

        // Testin işlemi geri alındıktan SONRA (bu geri çağırma ondan sonra kaydedildi): yoksa testin
        // kaydedilmemiş satırları silmeyi bekletir
        $this->beforeApplicationDestroyed(function (): void {
            if ($this->ikinci->transactionLevel() > 0) {
                $this->ikinci->rollBack();
            }
            $this->ikinci->table('yedek_kopyalari')->where('yedek_hedefi_id', $this->hedef->id)->delete();
            $this->ikinci->table('yedekler')->where('id', $this->yedek->id)->delete();
            $this->ikinci->table('yedek_hedefleri')->where('id', $this->hedef->id)->delete();
            DB::purge('ikinci');
            $this->yedeklemeOrtaminiKaldir();
        });
    }

    public function test_kopya_kaydi_acilirken_konum_guncellemesi_bekler_kopyasiz_sanip_konumu_degistirmez(): void
    {
        // Kopyalayıcının işlemi: kopya kaydı açılmış, henüz kaydedilmemiş
        $this->ikinci->beginTransaction();
        $this->ikinci->table('yedek_kopyalari')->insert(['yedek_id' => $this->yedek->id, 'yedek_hedefi_id' => $this->hedef->id, 'durum' => KopyaDurumu::Kopyalaniyor->value, 'deneme' => 0, 'sifreli' => false, 'created_at' => now(), 'updated_at' => now()]);

        // Kilit beklemesi lock_timeout ile hataya döner; kilitsiz güncelleme kopyayı görmeden 200 dönerdi
        Exceptions::fake();
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/yedek-hedefleri/'.$this->hedef->id, ['ad' => $this->hedef->ad, 'tur' => 'yerel', 'alanlar' => ['klasor' => '/yeni']])
            ->assertServerError();
        // 55P03 lock_not_available: satır kilidini bekledi
        Exceptions::assertReported(fn (QueryException $hata): bool => $hata->getCode() === '55P03');

        $this->ikinci->commit();
        $this->assertSame('/eski', YedekHedefi::query()->findOrFail($this->hedef->id)->baglanti['klasor']);
    }

    public function test_hedef_guncellenirken_kopya_baslamaz_bekler(): void
    {
        // Güncellemenin işlemi: konum yazılmış, henüz kaydedilmemiş
        $this->ikinci->beginTransaction();
        $this->ikinci->table('yedek_hedefleri')->where('id', $this->hedef->id)->update(['baglanti' => json_encode(['klasor' => '/yeni'])]);

        try {
            app(YedekKopyalayici::class)->kopyala($this->yedek, YedekHedefi::query()->findOrFail($this->hedef->id));
            $this->fail('Kopya, hedef güncellemesini beklemeden başladı.');
        } catch (QueryException $hata) {
            // 55P03 lock_not_available: satır kilidini bekledi
            $this->assertSame('55P03', $hata->getCode());
        }

        $this->assertFalse(YedekKopyasi::query()->where('yedek_hedefi_id', $this->hedef->id)->exists());
    }
}
