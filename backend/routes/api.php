<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlarmKuraliController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EFaturaController;
use App\Http\Controllers\Api\V1\EFaturaSenkronController;
use App\Http\Controllers\Api\V1\EkranTasarimController;
use App\Http\Controllers\Api\V1\EntegratorBaglantiController;
use App\Http\Controllers\Api\V1\ErpFirmaController;
use App\Http\Controllers\Api\V1\ErpProjeController;
use App\Http\Controllers\Api\V1\FirmaController;
use App\Http\Controllers\Api\V1\IsgucuKesintisiController;
use App\Http\Controllers\Api\V1\IsgucuYuklemeController;
use App\Http\Controllers\Api\V1\KesintiSecenekController;
use App\Http\Controllers\Api\V1\KesintiTuruController;
use App\Http\Controllers\Api\V1\KullaniciController;
use App\Http\Controllers\Api\V1\MailAyarController;
use App\Http\Controllers\Api\V1\ParaBirimiController;
use App\Http\Controllers\Api\V1\ProjeController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\SatinalmaTalebiController;
use App\Http\Controllers\Api\V1\SecenekController;
use App\Http\Controllers\Api\V1\SqlBaglantiController;
use App\Models\AlarmKurali;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    Route::middleware(['auth:sanctum', 'aktif'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        // ERP view'larından ortak seçim listeleri (program genelinde kullanılır)
        Route::get('/secenekler/personeller', [SecenekController::class, 'personeller'])
            ->name('secenekler.personeller');
        Route::get('/secenekler/tablo-maddesi/{tur}', [SecenekController::class, 'tabloMaddesi'])
            ->whereNumber('tur')
            ->name('secenekler.tablo-maddesi');
        Route::get('/secenekler/ilgili/{cins}', [SecenekController::class, 'ilgili'])
            ->whereNumber('cins')
            ->name('secenekler.ilgili');
        Route::get('/secenekler/depolar', [SecenekController::class, 'depolar'])
            ->name('secenekler.depolar');
        Route::get('/secenekler/firmamiz-adresleri', [SecenekController::class, 'firmamizAdresleri'])
            ->name('secenekler.firmamiz-adresleri');
        // Talep satırı listeleri — seçili projeye göre süzülür
        Route::get('/secenekler/aktiviteler/{projemizId}', [SecenekController::class, 'aktiviteler'])
            ->whereNumber('projemizId')
            ->name('secenekler.aktiviteler');
        Route::get('/secenekler/masraf-merkezleri/{projemizId}', [SecenekController::class, 'masrafMerkezleri'])
            ->whereNumber('projemizId')
            ->name('secenekler.masraf-merkezleri');
        // Ürün listesi büyük: arama sunucuda (?ara=), ilk 50 kayıt döner
        Route::get('/secenekler/urunler', [SecenekController::class, 'urunler'])
            ->name('secenekler.urunler');
        Route::get('/secenekler/ekipmanlar', [SecenekController::class, 'ekipmanlar'])
            ->name('secenekler.ekipmanlar');
        Route::get('/secenekler/butce-kalemleri/{projemizId}', [SecenekController::class, 'butceKalemleri'])
            ->whereNumber('projemizId')
            ->name('secenekler.butce-kalemleri');
        Route::get('/secenekler/duran-varliklar', [SecenekController::class, 'duranVarliklar'])
            ->name('secenekler.duran-varliklar');
        Route::get('/secenekler/ambalajlar', [SecenekController::class, 'ambalajlar'])
            ->name('secenekler.ambalajlar');
        Route::get('/secenekler/paralar', [SecenekController::class, 'paralar'])
            ->name('secenekler.paralar');
        Route::get('/secenekler/onay-rolleri', [SecenekController::class, 'onayRolleri'])
            ->name('secenekler.onay-rolleri');
        // Kur, belge tarihine göre okunur (ISO tarih)
        Route::get('/secenekler/kur/{paraId}/{tarih}', [SecenekController::class, 'kur'])
            ->whereNumber('paraId')
            ->where('tarih', '\d{4}-\d{2}-\d{2}')
            ->name('secenekler.kur');
        // Satırdaki personel: parti yaması ağacından (başlıktaki İK kartı değil)
        Route::get('/secenekler/parti-yamasi-personelleri', [SecenekController::class, 'partiYamasiPersonelleri'])
            ->name('secenekler.parti-yamasi-personelleri');

        // Satınalma Talebi — kayıt doğrudan ERP'ye (SOHOM_SIPARIS_KAYDET)
        Route::post('/satinalma/talepler', [SatinalmaTalebiController::class, 'kaydet'])
            ->middleware('can:satinalma_talebi.guncelle')
            ->name('satinalma.talepler.kaydet');
        Route::get('/satinalma/ozellikler', [SatinalmaTalebiController::class, 'ozellikler'])
            ->middleware('can:satinalma_talebi.goruntule')
            ->name('satinalma.ozellikler');

        // Ekran tasarım motoru — yayındaki tasarım herkese (form çizimi),
        // taslak/sürümler ve düzenleme Ekran Tasarımı izinleriyle
        Route::get('/ekranlar/{ekran}/tasarim', [EkranTasarimController::class, 'goster'])
            ->name('ekranlar.tasarim');
        Route::middleware('can:ekran_tasarimi.goruntule')->group(function (): void {
            Route::get('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslak'])
                ->name('ekranlar.taslak');
            Route::get('/ekranlar/{ekran}/surumler', [EkranTasarimController::class, 'surumler'])
                ->name('ekranlar.surumler');
        });
        Route::middleware('can:ekran_tasarimi.guncelle')->group(function (): void {
            Route::put('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslagiKaydet'])
                ->name('ekranlar.taslak.kaydet');
            Route::post('/ekranlar/{ekran}/yayinla', [EkranTasarimController::class, 'yayinla'])
                ->name('ekranlar.yayinla');
            Route::post('/ekranlar/{ekran}/surumler/{surum}/geri-al', [EkranTasarimController::class, 'geriAl'])
                ->whereNumber('surum')
                ->name('ekranlar.surumler.geri-al');
        });

        // e-Fatura ekranları (EFAT-11) — kapsam aktif entegratör hesabı; izinler EFAT-18.
        // Her uç önce görüntüleme iznini ister: PDF/Excel/senkron izni tek başına
        // listeyi görmeyen birine verinin tamamını açmaz.
        Route::middleware('can:efatura.goruntule')->group(function (): void {
            Route::get('/efatura/durum', [EFaturaSenkronController::class, 'durum'])
                ->name('efatura.durum');
            Route::get('/efatura/{yon}/faturalar', [EFaturaController::class, 'index'])
                ->whereIn('yon', ['gelen', 'giden'])
                ->name('efatura.faturalar');
            Route::get('/efatura/{yon}/faturalar/excel', [EFaturaController::class, 'excel'])
                ->whereIn('yon', ['gelen', 'giden'])
                ->middleware(['can:efatura.disari_aktar', 'throttle:efatura-excel'])
                ->name('efatura.faturalar.excel');
            Route::get('/efatura/faturalar/{fatura}/pdf', [EFaturaController::class, 'pdf'])
                ->whereNumber('fatura')
                ->middleware(['can:efatura.pdf', 'throttle:efatura-pdf'])
                ->name('efatura.faturalar.pdf');
            Route::get('/efatura/faturalar/{fatura}/xml', [EFaturaController::class, 'xml'])
                ->whereNumber('fatura')
                ->middleware(['can:efatura.pdf', 'throttle:efatura-pdf'])
                ->name('efatura.faturalar.xml');
            Route::put('/efatura/faturalar/{fatura}/gizli', [EFaturaController::class, 'gizle'])
                ->whereNumber('fatura')
                ->middleware('can:efatura.gizle')
                ->name('efatura.faturalar.gizle');
            Route::post('/efatura/senkron', [EFaturaSenkronController::class, 'baslat'])
                ->middleware('can:efatura.senkron')
                ->name('efatura.senkron');
            // "ERP Senkronla": eMOR (ERP'ye işlendi mi) hemen tazelenir
            Route::post('/efatura/erp-senkron', [EFaturaSenkronController::class, 'erpEslestir'])
                ->middleware(['can:efatura.senkron', 'throttle:efatura-erp'])
                ->name('efatura.erp-senkron');
        });

        // Kesinti Yönetimi → Kesinti Türleri. Modülün verisi ERP'de değil,
        // uygulamanın PostgreSQL'indedir
        Route::get('/kesinti/turler', [KesintiTuruController::class, 'index'])
            ->middleware('can:kesinti_turleri.goruntule')
            ->name('kesinti.turler');
        Route::middleware('can:kesinti_turleri.guncelle')->group(function (): void {
            Route::post('/kesinti/turler', [KesintiTuruController::class, 'store'])
                ->name('kesinti.turler.store');
            Route::put('/kesinti/turler/{kesintiTuru}', [KesintiTuruController::class, 'update'])
                ->whereNumber('kesintiTuru')
                ->name('kesinti.turler.update');
            Route::delete('/kesinti/turler/{kesintiTuru}', [KesintiTuruController::class, 'destroy'])
                ->whereNumber('kesintiTuru')
                ->name('kesinti.turler.destroy');
        });

        // Kesinti Yönetimi → Projeler: elle tanım + canlı ERP'den aktarım
        // (önce önizleme, sonra seçilenlerin kaydı)
        Route::get('/kesinti/projeler', [ProjeController::class, 'index'])
            ->middleware('can:projeler.goruntule')
            ->name('kesinti.projeler');
        Route::middleware('can:projeler.guncelle')->group(function (): void {
            Route::post('/kesinti/projeler', [ProjeController::class, 'store'])
                ->name('kesinti.projeler.store');
            Route::put('/kesinti/projeler/{proje}', [ProjeController::class, 'update'])
                ->whereNumber('proje')
                ->name('kesinti.projeler.update');
            Route::delete('/kesinti/projeler/{proje}', [ProjeController::class, 'destroy'])
                ->whereNumber('proje')
                ->name('kesinti.projeler.destroy');
            Route::get('/kesinti/projeler/erp', [ErpProjeController::class, 'index'])
                ->middleware('throttle:erp-aktarim')
                ->name('kesinti.projeler.erp');
            Route::post('/kesinti/projeler/erp', [ErpProjeController::class, 'store'])
                ->middleware('throttle:erp-aktarim')
                ->name('kesinti.projeler.erp.store');
        });

        // Kesinti Yönetimi → Para Birimleri (Excel'deki PARA BİRİMİ koduyla eşleşir)
        Route::get('/kesinti/para-birimleri', [ParaBirimiController::class, 'index'])
            ->middleware('can:para_birimleri.goruntule')
            ->name('kesinti.para-birimleri');
        Route::middleware('can:para_birimleri.guncelle')->group(function (): void {
            Route::post('/kesinti/para-birimleri', [ParaBirimiController::class, 'store'])
                ->name('kesinti.para-birimleri.store');
            Route::put('/kesinti/para-birimleri/{paraBirimi}', [ParaBirimiController::class, 'update'])
                ->whereNumber('paraBirimi')
                ->name('kesinti.para-birimleri.update');
            Route::delete('/kesinti/para-birimleri/{paraBirimi}', [ParaBirimiController::class, 'destroy'])
                ->whereNumber('paraBirimi')
                ->name('kesinti.para-birimleri.destroy');
        });

        // Kesinti icmal formlarının seçim listeleri (tür, taşeron, proje, para birimi)
        Route::get('/kesinti/secenekler', [KesintiSecenekController::class, 'index'])
            ->middleware('can:kesinti-secenekleri')
            ->name('kesinti.secenekler');

        // Kesinti Yönetimi → İşgücü Kesintileri: liste + elle giriş + Excel/yapıştırma
        // içe aktarımı (her yükleme bir parti; parti satırlarıyla geri alınır)
        Route::middleware('can:isgucu_kesintileri.goruntule')->group(function (): void {
            Route::get('/kesinti/isgucu', [IsgucuKesintisiController::class, 'index'])
                ->name('kesinti.isgucu');
            Route::get('/kesinti/isgucu/yuklemeler', [IsgucuYuklemeController::class, 'index'])
                ->name('kesinti.isgucu.yuklemeler');
        });
        Route::middleware('can:isgucu_kesintileri.guncelle')->group(function (): void {
            Route::post('/kesinti/isgucu', [IsgucuKesintisiController::class, 'store'])
                ->name('kesinti.isgucu.store');
            Route::put('/kesinti/isgucu/{kesinti}', [IsgucuKesintisiController::class, 'update'])
                ->whereNumber('kesinti')
                ->name('kesinti.isgucu.update');
            Route::delete('/kesinti/isgucu/{kesinti}', [IsgucuKesintisiController::class, 'destroy'])
                ->whereNumber('kesinti')
                ->name('kesinti.isgucu.destroy');
            Route::post('/kesinti/isgucu/yuklemeler/onizle', [IsgucuYuklemeController::class, 'onizle'])
                ->middleware('throttle:kesinti-ice-aktarim')
                ->name('kesinti.isgucu.yuklemeler.onizle');
            Route::post('/kesinti/isgucu/yuklemeler', [IsgucuYuklemeController::class, 'store'])
                ->middleware('throttle:kesinti-ice-aktarim')
                ->name('kesinti.isgucu.yuklemeler.store');
            Route::delete('/kesinti/isgucu/yuklemeler/{yukleme}', [IsgucuYuklemeController::class, 'destroy'])
                ->whereNumber('yukleme')
                ->name('kesinti.isgucu.yuklemeler.destroy');
        });

        // Kesinti Yönetimi → Firmalar (taşeronlar): elle tanım + canlı ERP'den
        // aktarım (eşleşme GUID ya da VKN ile)
        Route::get('/kesinti/firmalar', [FirmaController::class, 'index'])
            ->middleware('can:firmalar.goruntule')
            ->name('kesinti.firmalar');
        Route::middleware('can:firmalar.guncelle')->group(function (): void {
            Route::post('/kesinti/firmalar', [FirmaController::class, 'store'])
                ->name('kesinti.firmalar.store');
            Route::put('/kesinti/firmalar/{firma}', [FirmaController::class, 'update'])
                ->whereNumber('firma')
                ->name('kesinti.firmalar.update');
            Route::delete('/kesinti/firmalar/{firma}', [FirmaController::class, 'destroy'])
                ->whereNumber('firma')
                ->name('kesinti.firmalar.destroy');
            Route::get('/kesinti/firmalar/erp', [ErpFirmaController::class, 'index'])
                ->middleware('throttle:erp-aktarim')
                ->name('kesinti.firmalar.erp');
            Route::post('/kesinti/firmalar/erp', [ErpFirmaController::class, 'store'])
                ->middleware('throttle:erp-aktarim')
                ->name('kesinti.firmalar.erp.store');
        });

        // Header'daki Test/Canlı rozeti — her kullanıcıya yalnız ortam adı döner
        Route::get('/aktif-ortam', [SqlBaglantiController::class, 'aktifOrtam'])
            ->name('aktif-ortam');

        // Ayarlar ekranları: her ekran kendi görüntüle/güncelle izniyle
        // (App\Yetki\Izin::ekranlar). Sınama ve test maili de güncelleme sayılır.

        // Ayarlar → SQL Bağlantıları (Test/Canlı MSSQL tanımları + aktif ortam)
        Route::get('/ayarlar/sql-baglantilari', [SqlBaglantiController::class, 'index'])
            ->middleware('can:sql_baglantilari.goruntule')
            ->name('ayarlar.sql-baglantilari');
        Route::middleware('can:sql_baglantilari.guncelle')->group(function (): void {
            Route::post('/ayarlar/sql-baglantilari/aktif', [SqlBaglantiController::class, 'aktifYap'])
                ->name('ayarlar.sql-baglantilari.aktif');
            Route::put('/ayarlar/sql-baglantilari/{ortam}', [SqlBaglantiController::class, 'guncelle'])
                ->whereIn('ortam', ['test', 'canli'])
                ->name('ayarlar.sql-baglantilari.guncelle');
            Route::post('/ayarlar/sql-baglantilari/{ortam}/sina', [SqlBaglantiController::class, 'sina'])
                ->whereIn('ortam', ['test', 'canli'])
                ->middleware('throttle:sql-sina')
                ->name('ayarlar.sql-baglantilari.sina');
        });

        // Ayarlar → Entegratör Bağlantıları (Test/Canlı İzibiz tanımları);
        // aktif ortam SQL'den bağımsız seçilir (EFAT-15, S5)
        Route::get('/ayarlar/entegrator-baglantilari', [EntegratorBaglantiController::class, 'index'])
            ->middleware('can:entegrator_baglantilari.goruntule')
            ->name('ayarlar.entegrator-baglantilari');
        Route::middleware('can:entegrator_baglantilari.guncelle')->group(function (): void {
            Route::post('/ayarlar/entegrator-baglantilari/aktif', [EntegratorBaglantiController::class, 'aktifYap'])
                ->name('ayarlar.entegrator-baglantilari.aktif');
            Route::put('/ayarlar/entegrator-baglantilari/{ortam}', [EntegratorBaglantiController::class, 'guncelle'])
                ->whereIn('ortam', ['test', 'canli'])
                ->name('ayarlar.entegrator-baglantilari.guncelle');
            Route::post('/ayarlar/entegrator-baglantilari/{ortam}/sina', [EntegratorBaglantiController::class, 'sina'])
                ->whereIn('ortam', ['test', 'canli'])
                ->middleware('throttle:entegrator-sina')
                ->name('ayarlar.entegrator-baglantilari.sina');
        });

        // Ayarlar → Roller (EFAT-18). Rol listesi Kullanıcılar ekranında da
        // (rol ataması) gerekir: `rol-listesi` Gate'i ikisinden birini ister
        Route::get('/ayarlar/izinler', [RolController::class, 'izinler'])
            ->middleware('can:roller.goruntule')
            ->name('ayarlar.izinler');
        Route::get('/ayarlar/roller', [RolController::class, 'index'])
            ->middleware('can:rol-listesi')
            ->name('ayarlar.roller');
        Route::middleware('can:roller.guncelle')->group(function (): void {
            Route::post('/ayarlar/roller', [RolController::class, 'store'])
                ->name('ayarlar.roller.store');
            Route::put('/ayarlar/roller/{rol}', [RolController::class, 'update'])
                ->whereNumber('rol')
                ->name('ayarlar.roller.update');
            Route::delete('/ayarlar/roller/{rol}', [RolController::class, 'destroy'])
                ->whereNumber('rol')
                ->name('ayarlar.roller.destroy');
        });

        // Ayarlar → Kullanıcılar (EFAT-18)
        Route::get('/ayarlar/kullanicilar', [KullaniciController::class, 'index'])
            ->middleware('can:kullanicilar.goruntule')
            ->name('ayarlar.kullanicilar');
        Route::middleware('can:kullanicilar.guncelle')->group(function (): void {
            Route::post('/ayarlar/kullanicilar', [KullaniciController::class, 'store'])
                ->name('ayarlar.kullanicilar.store');
            Route::put('/ayarlar/kullanicilar/{kullanici}', [KullaniciController::class, 'update'])
                ->whereNumber('kullanici')
                ->name('ayarlar.kullanicilar.update');
        });

        // Ayarlar → Alarm Kuralları (EFAT-13)
        Route::get('/ayarlar/alarm-kurallari', [AlarmKuraliController::class, 'index'])
            ->middleware('can:alarm_kurallari.goruntule')
            ->name('ayarlar.alarm-kurallari');
        Route::put('/ayarlar/alarm-kurallari/{tur}', [AlarmKuraliController::class, 'update'])
            ->whereIn('tur', AlarmKurali::TURLER)
            ->middleware('can:alarm_kurallari.guncelle')
            ->name('ayarlar.alarm-kurallari.update');

        // Ayarlar → Mail (SMTP): uygulamanın giden mail tanımı
        Route::get('/ayarlar/mail', [MailAyarController::class, 'goster'])
            ->middleware('can:mail_ayarlari.goruntule')
            ->name('ayarlar.mail');
        Route::middleware('can:mail_ayarlari.guncelle')->group(function (): void {
            Route::put('/ayarlar/mail', [MailAyarController::class, 'guncelle'])
                ->name('ayarlar.mail.guncelle');
            Route::post('/ayarlar/mail/test', [MailAyarController::class, 'testGonder'])
                ->middleware('throttle:mail-test')
                ->name('ayarlar.mail.test');
        });
    });
});
