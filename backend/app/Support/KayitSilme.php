<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Başka kayıtlarda kullanılan tanım silinmez: veritabanının RESTRICT kuralı
 * (ör. kesinti satırındaki taşeron) "beklenmeyen hata" yerine 409 +
 * KAYIT_KULLANILIYOR olarak döner.
 */
final class KayitSilme
{
    public static function sil(Model $kayit): void
    {
        try {
            // Savepoint: başarısız silme dıştaki işlemi (ör. testlerin işlemi) bozmasın
            DB::transaction(fn () => $kayit->delete());
        } catch (QueryException $hata) {
            if (! self::yabanciAnahtarIhlali($hata)) {
                throw $hata;
            }

            throw new HttpResponseException(response()->json([
                'kod' => 'KAYIT_KULLANILIYOR',
                'mesaj' => __('hata.kayit_kullaniliyor'),
            ], 409));
        }
    }

    /** PostgreSQL 23503; SQLite (testler) 23000 + "FOREIGN KEY constraint failed" */
    private static function yabanciAnahtarIhlali(QueryException $hata): bool
    {
        return (string) $hata->getCode() === '23503'
            || str_contains($hata->getMessage(), 'FOREIGN KEY constraint failed');
    }
}
