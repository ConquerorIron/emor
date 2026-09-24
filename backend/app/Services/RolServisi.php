<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Rol;
use App\Yetki\Izin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rol yönetimi (EFAT-18). İzinler katalogdan (App\Yetki\Izin) seçilir;
 * doğrulama request katmanında yapılır.
 */
final class RolServisi
{
    /**
     * @return array{0: Collection<int, Rol>, 1: array<int, list<string>>} [roller, rol_id => izinler]
     */
    public function listele(): array
    {
        $roller = Rol::query()->withCount('kullanicilar')->orderBy('ad')->get();

        $izinler = [];
        foreach (DB::table('rol_izinleri')->whereIn('rol_id', $roller->modelKeys())->orderBy('izin')->get() as $satir) {
            $izinler[(int) $satir->rol_id][] = (string) $satir->izin;
        }

        return [$roller, $izinler];
    }

    /**
     * @param  array{ad: string, aciklama?: string|null, izinler: list<string>}  $veri
     */
    public function kaydet(?Rol $rol, array $veri): Rol
    {
        return DB::transaction(function () use ($rol, $veri): Rol {
            $rol ??= new Rol;
            $rol->fill(['ad' => $veri['ad'], 'aciklama' => $veri['aciklama'] ?? null]);
            $rol->save();

            // Güncelleme/ek izin, ekranın görüntüleme iznini de getirir
            DB::table('rol_izinleri')->where('rol_id', $rol->id)->delete();
            DB::table('rol_izinleri')->insert(array_map(
                fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin],
                Izin::tamamla($veri['izinler']),
            ));

            return $rol;
        });
    }

    /** Rol silinince kullanıcı ve izin eşlemeleri de silinir (cascade). */
    public function sil(Rol $rol): void
    {
        $rol->delete();
    }
}
