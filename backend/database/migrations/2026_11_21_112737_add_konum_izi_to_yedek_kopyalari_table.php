<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kopyanın yapıldığı yerin değişmez kimliği (BY-028-R5): hedefin konum
 * alanlarının normalleştirilmiş hâlinin SHA-256'sı (HedefKonumu::iz; sır
 * içermez). İmzalı kopya olayına da yazılır; dizin kurulurken olaydaki iz
 * hedefin bugünkü iziyle uyuşmayan kopya hedefe `tamam` bağlanmaz,
 * `konum_belirsiz` olur.
 *
 * Var olan `tamam` satırlarının izi yoktur: hangi yerde yapıldıkları
 * doğrulanamaz, `konum_belirsiz` olurlar (olayları da izsiz olduğu için
 * dizin kurulunca da öyle kalırlar). Modül henüz canlıda değil.
 */
return new class extends Migration
{
    private const KOD = 'KOPYA_KONUMU_DOGRULANAMADI';

    public function up(): void
    {
        Schema::table('yedek_kopyalari', function (Blueprint $table) {
            $table->char('konum_izi', 64)->nullable();
        });

        DB::table('yedek_kopyalari')->where('durum', 'tamam')->whereNull('konum_izi')
            ->update(['durum' => 'konum_belirsiz', 'hata_kodu' => self::KOD, 'updated_at' => now()]);
    }

    /**
     * Geri alma, izsiz satırları yeniden `tamam` sayar (eski kodda
     * `konum_belirsiz` değeri yoktur); yeri doğrulanmamış kopya yeniden
     * kullanılabilir olur — canlıda geri almak yerine ileri düzeltme tercih edilmeli.
     */
    public function down(): void
    {
        DB::table('yedek_kopyalari')->where('durum', 'konum_belirsiz')
            ->update(['durum' => 'tamam', 'hata_kodu' => null, 'updated_at' => now()]);

        Schema::table('yedek_kopyalari', function (Blueprint $table) {
            $table->dropColumn('konum_izi');
        });
    }
};
