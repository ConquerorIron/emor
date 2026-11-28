#!/usr/bin/env bash
#
# deploy.sh testleri: yayın öncesi yedek (BY-013) ve yayın koruması (BY-013-R1,
# BY-029-R1: yayın kilidi, açık geri yükleme denetimi, bakım modu sahipliği).
# Sunucu, veritabanı ya da ağ gerektirmez: php, git, composer ve npm, PATH'e
# konan sahte komutlarla değiştirilir. Sahte php her çağrıyı kaydeder:
#   - `yedek:yayin-oncesi` çıkış kodu SAHTE_YEDEK_KODU ile seçilir;
#   - `yedek:yayin-korumasi kilit-yolu` modül açıkken (SAHTE_MODUL=acik)
#     SAHTE_KILIT'i yazar, kapalıyken hiçbir şey; çıkışı SAHTE_KILIT_KODU;
#   - `yedek:yayin-korumasi denetle` çıkışı SAHTE_DENETIM_KODU (1 = açık
#     geri yükleme var);
#   - `migrate` çağrıldığında yayın kilidinin tutulup tutulmadığını kaydeder;
#   - ilk çağrıda bootstrap/cache'in içeriğini ve iznini, her çağrıda umask'ı
#     ve VIEW_COMPILED_PATH'i kaydeder (YF-03).
# Ayrıca: yayın damgası (YF-24), yayın sonunda bakım modu uyarısı (YF-65).
# publish.sh her yayından önce bu testi çalıştırır (YF-69).
# Sahte git `fetch`'te durur (SAHTE_GIT_TAMAM=1 ise yayın sonuna kadar gider).
#
# Çalıştırma: scripts/tests/deploy.test.sh   (flock gerekir: util-linux)
# Çıkış kodu: 0 hepsi geçti, 1 en az biri başarısız.
#
set -uo pipefail

BETIK="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/deploy.sh"
CALISMA="$(mktemp -d)"
trap 'rm -rf "$CALISMA"' EXIT

GECEN=0
KALAN=0

gecti() { GECEN=$((GECEN + 1)); echo "  ✓ $1"; }
kaldi() { KALAN=$((KALAN + 1)); echo "  ✗ $1"; }
esit() { if [[ "$2" == "$3" ]]; then gecti "$1"; else kaldi "$1 (beklenen: $3, gelen: $2)"; fi; }
iceriyor() { if grep -qF -- "$3" "$2" 2>/dev/null; then gecti "$1"; else kaldi "$1 ($2 içinde yok: $3)"; fi; }
icermiyor() { if grep -qF -- "$3" "$2" 2>/dev/null; then kaldi "$1 ($2 içinde var: $3)"; else gecti "$1"; fi; }

command -v flock >/dev/null || { echo "flock bulunamadı (util-linux)"; exit 1; }

SAHTE="$CALISMA/bin"
mkdir -p "$SAHTE"
cat > "$SAHTE/php" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAHTE_KAYIT/php.cagri"
# YF-03: ilk çağrının gördüğü uygulama önbellek klasörü; her çağrının umask'ı ve
# derlenmiş görünüm klasörü (komut|umask|VIEW_COMPILED_PATH)
if [[ ! -e "$SAHTE_KAYIT/ilk.onbellek" ]]; then
  ls -A backend/bootstrap/cache > "$SAHTE_KAYIT/ilk.onbellek" 2>&1
  stat -c %a backend/bootstrap/cache > "$SAHTE_KAYIT/ilk.onbellek.izin" 2>&1
fi
printf '%s|%s|%s\n' "${2:-}" "$(umask)" "${VIEW_COMPILED_PATH:-}" >> "$SAHTE_KAYIT/php.ortam"
case "${2:-} ${3:-}" in
  "yedek:yayin-oncesi "*)
    env | grep -o '^PG[A-Z_]*' | sort | tr '\n' ' ' > "$SAHTE_KAYIT/yedek.pg_ortami"
    exit "${SAHTE_YEDEK_KODU:-0}" ;;
  "yedek:yayin-korumasi kilit-yolu")
    if [[ "${SAHTE_MODUL:-kapali}" == acik ]]; then printf '%s\n' "$SAHTE_KILIT"; fi
    exit "${SAHTE_KILIT_KODU:-0}" ;;
  "yedek:yayin-korumasi denetle")
    exit "${SAHTE_DENETIM_KODU:-0}" ;;
  "migrate "*)
    if flock -n "$SAHTE_KILIT" true; then echo serbest; else echo tutuluyor; fi > "$SAHTE_KAYIT/migrate.kilit" ;;
esac
exit 0
EOF
cat > "$SAHTE/git" <<'EOF'
#!/usr/bin/env bash
printf 'git %s\n' "$*" >> "$SAHTE_KAYIT/php.cagri"
[[ "${1:-}" == "fetch" && "${SAHTE_GIT_TAMAM:-0}" != 1 ]] && exit 128
exit 0
EOF
printf '#!/usr/bin/env bash\nexit 0\n' > "$SAHTE/composer"
printf '#!/usr/bin/env bash\nexit 0\n' > "$SAHTE/npm"
chmod +x "$SAHTE/php" "$SAHTE/git" "$SAHTE/composer" "$SAHTE/npm"
# Yayını başka bir kullanıcı çalıştırıyormuş gibi (YF-03: bootstrap/cache'in
# sahibi yayın kullanıcısı değil). Yalnız EK_PATH ile verilen testte.
BASKA_KULLANICI="$CALISMA/baska-kullanici"
mkdir -p "$BASKA_KULLANICI"
printf '#!/usr/bin/env bash\nif [[ "$*" == "-un" ]]; then echo baskasi; else exec /usr/bin/id "$@"; fi\n' > "$BASKA_KULLANICI/id"
chmod +x "$BASKA_KULLANICI/id"

# Proje kökü kopyası: deploy.sh kendi klasöründe .git ve backend/.env arar
kur() {
  KOK="$CALISMA/kok-$1"
  KAYIT="$CALISMA/kayit-$1"
  KILIT="$CALISMA/kilit-$1"
  mkdir -p "$KOK/.git" "$KOK/backend/storage/framework" "$KOK/frontend" "$KAYIT"
  cp "$BETIK" "$KOK/deploy.sh"
  : > "$KILIT"
  # Elle ayrıştırılırsa tırnağı ve yorumu bozulan bir parola (BY-001-R1)
  printf 'DB_DATABASE=erp\nDB_USERNAME=erp\nDB_PASSWORD="a#b c" # yorum\n' > "$KOK/backend/.env"
  # Sunucudaki gibi web'in yazabildiği uygulama önbelleği (YF-03: 2775 admin:www-data):
  # web'in yazdığı config.php ve klasör dışına giden bir bağlantı
  ONBELLEK="$KOK/backend/bootstrap/cache"
  mkdir -p "$ONBELLEK"; chmod 2775 "$ONBELLEK"
  printf '*\n!.gitignore\n' > "$ONBELLEK/.gitignore"
  printf '<?php return ["web" => "yazdi"];\n' > "$ONBELLEK/config.php"; chmod 664 "$ONBELLEK/config.php"
  ln -s "$CALISMA/disarida.php" "$ONBELLEK/routes-v7.php"
  MODUL=kapali; DENETIM=0; KILIT_KODU=0; GIT_TAMAM=0; EK_PATH=""
}

# Yayın, kabuğun önceki umask'ı 0002 iken çalışır (sunucudaki admin oturumu gibi)
calistir() {
  (cd "$KOK" && umask 0002 && env -u PGPASSWORD PATH="${EK_PATH:+$EK_PATH:}$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" SAHTE_YEDEK_KODU="$1" \
    SAHTE_MODUL="$MODUL" SAHTE_KILIT="$KILIT" SAHTE_DENETIM_KODU="$DENETIM" SAHTE_KILIT_KODU="$KILIT_KODU" \
    SAHTE_GIT_TAMAM="$GIT_TAMAM" EMOR_YAYIN_KILIT_BEKLEME=1 \
    EMOR_BACKUP_DIR=/var/backups/emor-test bash ./deploy.sh "${@:2}" >"$KAYIT/cikti" 2>&1)
  echo $?
}

# php.ortam satırının alanı: $1 komut (ör. "migrate"), $2 alan (2 umask, 3 görünüm klasörü)
ortam_alani() { grep -m1 "^$1|" "$KAYIT/php.ortam" | cut -d'|' -f"$2"; }

# Yayın koruması dışındaki çağrılar (sıra denetimi için)
yayin_cagrilari() { grep -v 'yedek:yayin-korumasi' "$KAYIT/php.cagri" | head -n "$1" | tr '\n' '|'; }

echo "deploy.sh — yayın öncesi yedek"

kur basarisiz
kod="$(calistir 1)"
esit "yedek başarısızsa yayın 1 ile durur" "$kod" "1"
iceriyor "yedek uygulamanın komutuyla istenir" "$KAYIT/php.cagri" "backend/artisan yedek:yayin-oncesi --eski-klasor=/var/backups/emor-test"
icermiyor "yedek başarısızken migration çalışmaz" "$KAYIT/php.cagri" "migrate"
icermiyor "yedek başarısızken bakım moduna alınmaz" "$KAYIT/php.cagri" "artisan down"
icermiyor "yedek başarısızken kod çekilmez" "$KAYIT/php.cagri" "git fetch"
iceriyor "anlaşılır hata mesajı" "$KAYIT/cikti" "Yayın öncesi yedek alınamadı"

kur kilitli
kod="$(calistir 75)"
esit "kilit meşgulken (75) de yayın durur" "$kod" "1"
icermiyor "kilit meşgulken migration çalışmaz" "$KAYIT/php.cagri" "migrate"

kur basarili
calistir 0 >/dev/null
esit "ilk çağrı yedek, sonra bakım modu" "$(yayin_cagrilari 2)" \
  "backend/artisan yedek:yayin-oncesi --eski-klasor=/var/backups/emor-test|backend/artisan down|"
esit "yedeğe PG ortam değişkeni (PGPASSWORD) geçirilmez" "$(cat "$KAYIT/yedek.pg_ortami")" ""

kur yedeksiz
calistir 1 --no-backup >/dev/null
icermiyor "--no-backup yedeği atlar" "$KAYIT/php.cagri" "yedek:yayin-oncesi"
iceriyor "--no-backup ile yayın devam eder" "$KAYIT/php.cagri" "git fetch"

icermiyor "deploy.sh .env okumaz (env_val yok)" "$BETIK" "env_val"
icermiyor "deploy.sh kendi pg_dump'ını çalıştırmaz" "$BETIK" "pg_dump -h"

echo
echo "deploy.sh — yayın koruması (BY-013-R1, BY-029-R1)"

for yol in yedekli yedeksiz; do
  kur "acik-geri-yukleme-$yol"
  MODUL=acik; DENETIM=1
  if [[ "$yol" == yedeksiz ]]; then kod="$(calistir 0 --no-backup)"; else kod="$(calistir 0)"; fi
  esit "açık geri yükleme ($yol): yayın 1 ile durur" "$kod" "1"
  iceriyor "açık geri yükleme ($yol): denetim yapıldı" "$KAYIT/php.cagri" "yedek:yayin-korumasi denetle"
  icermiyor "açık geri yükleme ($yol): yedek istenmez" "$KAYIT/php.cagri" "yedek:yayin-oncesi"
  icermiyor "açık geri yükleme ($yol): kod çekilmez" "$KAYIT/php.cagri" "git "
  icermiyor "açık geri yükleme ($yol): migration yok" "$KAYIT/php.cagri" "migrate"
  icermiyor "açık geri yükleme ($yol): bakım moduna alınmaz" "$KAYIT/php.cagri" "artisan down"
  icermiyor "açık geri yükleme ($yol): geri yüklemenin bakım modu kaldırılmaz" "$KAYIT/php.cagri" "artisan up"
done

kur kilit-mesgul
MODUL=acik
exec 8<"$KILIT"
flock -n 8 || kaldi "ön koşul: test yayın kilidini alamadı"
kod="$(calistir 0 --no-backup)"
exec 8<&-
esit "yayın kilidi başkasındayken yayın 1 ile durur" "$kod" "1"
iceriyor "kilit meşgul: anlaşılır mesaj" "$KAYIT/cikti" "yayın kilidi"
icermiyor "kilit meşgul: denetim ve yayın adımları çalışmaz" "$KAYIT/php.cagri" "denetle"
icermiyor "kilit meşgul: kod çekilmez" "$KAYIT/php.cagri" "git "
icermiyor "kilit meşgul: bakım modu kaldırılmaz" "$KAYIT/php.cagri" "artisan up"

kur kilit-yolu-hatasi
MODUL=acik; KILIT_KODU=1
kod="$(calistir 0 --no-backup)"
esit "koruma komutu çalışmazsa yayın 1 ile durur (bilinmeyen durum izin değildir)" "$kod" "1"
icermiyor "koruma komutu çalışmazsa kod çekilmez" "$KAYIT/php.cagri" "git "

kur sira
MODUL=acik
calistir 0 >/dev/null
esit "sıra: kilit yolu, denetim, yedek, bakım modu" "$(head -n4 "$KAYIT/php.cagri" | tr '\n' '|')" \
  "backend/artisan yedek:yayin-korumasi kilit-yolu|backend/artisan yedek:yayin-korumasi denetle|backend/artisan yedek:yayin-oncesi --eski-klasor=/var/backups/emor-test|backend/artisan down|"

kur tam-yayin
MODUL=acik; GIT_TAMAM=1
kod="$(calistir 0)"
esit "tam yayın 0 ile biter" "$kod" "0"
esit "migration sırasında yayın kilidi tutuluyor" "$(cat "$KAYIT/migrate.kilit" 2>/dev/null)" "tutuluyor"
esit "yayın kendi açtığı bakım modunu sonda kapatır" "$(grep -c '^backend/artisan up$' "$KAYIT/php.cagri")" "1"
if flock -n "$KILIT" true; then gecti "yayın bitince kilit bırakıldı"; else kaldi "yayın bitince kilit bırakıldı"; fi
esit "bakım modu kapalıyken son satır yalnız 'Deploy tamamlandı'" "$(tail -n1 "$KAYIT/cikti" | grep -c 'Deploy tamamlandı\. ')" "1"
# YF-24: başarılı yayının sonunda çalıştırıcının yeniden başlaması için damga
if grep -Eqx '[^ ]* [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z' "$KOK/.yayin-damgasi" 2>/dev/null; then
  gecti "başarılı yayın proje kökünde damga yazar (sürüm + zaman)"
else
  kaldi "başarılı yayın proje kökünde damga yazar (sürüm + zaman) ($(cat "$KOK/.yayin-damgasi" 2>&1))"
fi
esit "damganın geçici kopyası kalmaz" "$(find "$KOK" -maxdepth 1 -name '.yayin-damgasi?*' | wc -l)" "0"

kur onceden-bakimda
touch "$KOK/backend/storage/framework/down"
calistir 0 >/dev/null
icermiyor "önceden bakım modundaysa down çağrılmaz" "$KAYIT/php.cagri" "artisan down"
icermiyor "önceden bakım modundaysa (başkasının) bakım modu kaldırılmaz" "$KAYIT/php.cagri" "artisan up"
iceriyor "önceden bakım modu not düşülür" "$KAYIT/cikti" "zaten bakım modunda"

# YF-65: başkasının bıraktığı bakım modu yayın sonunda da görünür
kur onceden-bakimda-tam-yayin
touch "$KOK/backend/storage/framework/down"
GIT_TAMAM=1
kod="$(calistir 0)"
esit "başkasının bakım modunda tam yayın 0 ile biter" "$kod" "0"
iceriyor "yayın sonunda bakım modu uyarısı" "$KAYIT/cikti" "UYARI: Uygulama HÂLÂ bakım modunda"
esit "son satır bakım modunun açık olduğunu söyler" "$(tail -n1 "$KAYIT/cikti")" "==> Deploy tamamlandı (bakım modu AÇIK)."

echo
echo "deploy.sh — web'in yazabildiği PHP admin olarak yüklenmez (YF-03)"

kur onbellek-basarili
GIT_TAMAM=1
calistir 0 >/dev/null
icermiyor "web'in yazdığı bootstrap/cache/config.php ilk php çağrısından önce silindi" "$KAYIT/ilk.onbellek" "config.php"
icermiyor "bootstrap/cache'teki .php bağlantısı da silindi" "$KAYIT/ilk.onbellek" "routes-v7.php"
iceriyor "bootstrap/cache'in .gitignore'u korundu" "$KAYIT/ilk.onbellek" ".gitignore"
esit "ilk php çağrısında bootstrap/cache'e grup / başkaları yazamaz" "$(cat "$KAYIT/ilk.onbellek.izin")" "2755"
esit "ilk php çağrısı (önbellek dosyalarını üretir) umask 022 ile" "$(head -n1 "$KAYIT/php.ortam" | cut -d'|' -f2)" "0022"
esit "optimize (bootstrap/cache'i üretir) umask 022 ile" "$(ortam_alani optimize 2)" "0022"
esit "yayın öncesi yedek web ile ortak günlükler için önceki umask ile" "$(ortam_alani yedek:yayin-oncesi 2)" "0002"
GORUNUM="$(ortam_alani migrate 3)"
if [[ -n "$GORUNUM" && "$GORUNUM" != *storage* ]]; then gecti "cache adımından önceki artisan çağrıları özel görünüm klasörünü kullanır"; else kaldi "cache adımından önceki artisan çağrıları özel görünüm klasörünü kullanır ('$GORUNUM')"; fi
esit "yayın öncesi yedek de aynı özel görünüm klasörüyle" "$(ortam_alani yedek:yayin-oncesi 3)" "$GORUNUM"
esit "optimize:clear normal görünüm yolunu kullanır" "$(ortam_alani optimize:clear 3)" ""
esit "optimize normal görünüm yolunu önbelleğe yazar" "$(ortam_alani optimize 3)" ""
if [[ -n "$GORUNUM" && ! -e "$GORUNUM" ]]; then gecti "özel görünüm klasörü yayın sonunda silindi"; else kaldi "özel görünüm klasörü yayın sonunda silindi"; fi

esit "tam yayında optimize bir kez çalışır (çıkış tuzağı yinelemez)" "$(grep -c '^backend/artisan optimize$' "$KAYIT/php.cagri")" "1"

kur onbellek-yedek-basarisiz
calistir 1 >/dev/null
GORUNUM="$(ortam_alani yedek:yayin-oncesi 3)"
if [[ -n "$GORUNUM" && ! -e "$GORUNUM" ]]; then gecti "yayın yarıda durunca da özel görünüm klasörü silinir"; else kaldi "yayın yarıda durunca da özel görünüm klasörü silinir ('$GORUNUM')"; fi
icermiyor "yarıda duran yayın damga yazmaz" <(ls -A "$KOK") ".yayin-damgasi"
# G1-4: kod çekilmeden duran yayın sildiği önbelleği geri kurar (site önbelleksiz kalmaz)
esit "kod çekilmeden duran yayının son php çağrısı optimize" "$(tail -n1 "$KAYIT/php.cagri")" "backend/artisan optimize"
esit "geri kurulan önbellek umask 022 ile" "$(ortam_alani optimize 2)" "0022"
esit "geri kurulan önbellek normal görünüm yolunu yazar (silinen özel klasörü değil)" "$(ortam_alani optimize 3)" ""

# Bakım modunu yayın açtıysa önbellek bakım modu kapanmadan geri kurulur
kur onbellek-git-basarisiz
calistir 0 >/dev/null
esit "kod çekme düşünce: önce optimize, sonra bakım modu kapanır" "$(grep -v '^git ' "$KAYIT/php.cagri" | tail -n2 | tr '\n' '|')" "backend/artisan optimize|backend/artisan up|"

kur onbellek-baskasinin
EK_PATH="$BASKA_KULLANICI"
kod="$(calistir 0)"
esit "bootstrap/cache yayın kullanıcısının değilse yayın 1 ile durur" "$kod" "1"
esit "sahibi farklıyken hiçbir php çağrısı yapılmaz" "$(cat "$KAYIT/php.cagri" 2>/dev/null)" ""
iceriyor "sahibi farklı: anlaşılır mesaj" "$KAYIT/cikti" "bootstrap/cache"
esit "sahibi farklıyken web'in dosyasına dokunulmaz" "$(cat "$ONBELLEK/config.php" 2>/dev/null)" '<?php return ["web" => "yazdi"];'

if command -v setfacl >/dev/null && setfacl -d -m g::rwx "$CALISMA" 2>/dev/null; then
  setfacl -k "$CALISMA"
  kur onbellek-varsayilan-acl
  # Varsayılan ACL umask'ı geçersiz kılar: yeni dosyalar yine grup yazılabilir açılırdı (G5)
  setfacl -d -m g::rwx,m::rwx "$ONBELLEK"
  calistir 0 >/dev/null
  esit "bootstrap/cache'in ek ACL girdileri kaldırıldı" "$(getfacl -cps "$ONBELLEK" 2>/dev/null)" ""
else
  echo "  - (atlandı: setfacl yok ya da dosya sistemi ACL desteklemiyor)"
fi

echo
echo "Geçen: $GECEN, kalan: $KALAN"
[[ "$KALAN" -eq 0 ]]
