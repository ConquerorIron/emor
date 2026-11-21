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
#   - `migrate` çağrıldığında yayın kilidinin tutulup tutulmadığını kaydeder.
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
  MODUL=kapali; DENETIM=0; KILIT_KODU=0; GIT_TAMAM=0
}

calistir() {
  (cd "$KOK" && env -u PGPASSWORD PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" SAHTE_YEDEK_KODU="$1" \
    SAHTE_MODUL="$MODUL" SAHTE_KILIT="$KILIT" SAHTE_DENETIM_KODU="$DENETIM" SAHTE_KILIT_KODU="$KILIT_KODU" \
    SAHTE_GIT_TAMAM="$GIT_TAMAM" EMOR_YAYIN_KILIT_BEKLEME=1 \
    EMOR_BACKUP_DIR=/var/backups/emor-test bash ./deploy.sh "${@:2}" >"$KAYIT/cikti" 2>&1)
  echo $?
}

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

kur onceden-bakimda
touch "$KOK/backend/storage/framework/down"
calistir 0 >/dev/null
icermiyor "önceden bakım modundaysa down çağrılmaz" "$KAYIT/php.cagri" "artisan down"
icermiyor "önceden bakım modundaysa (başkasının) bakım modu kaldırılmaz" "$KAYIT/php.cagri" "artisan up"
iceriyor "önceden bakım modu not düşülür" "$KAYIT/cikti" "zaten bakım modunda"

echo
echo "Geçen: $GECEN, kalan: $KALAN"
[[ "$KALAN" -eq 0 ]]
