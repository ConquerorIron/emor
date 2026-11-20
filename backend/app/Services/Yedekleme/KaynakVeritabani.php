<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;

/**
 * Yedeği alınan veritabanının anlık görüntüsü. Gerçeği PostgreSQL'dedir
 * (PostgresKaynakVeritabani); testler SQLite ile çalıştığı için sahtesi bağlanır.
 */
interface KaynakVeritabani
{
    /**
     * Anlık görüntüyü açar, bilgileri onun içinden okur, işi çalıştırır ve
     * görüntüyü kapatır. İş sürdükçe görüntü açık kalır (pg_dump --snapshot).
     *
     * @template T
     *
     * @param  Closure(AnlikGoruntu): T  $is
     * @return T
     */
    public function anlikGoruntuyle(Closure $is): mixed;
}
