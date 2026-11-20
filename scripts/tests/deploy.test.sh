#!/usr/bin/env bash
#
# deploy.sh'ın yayın öncesi yedek adımı testleri (BY-013). Sunucu, veritabanı
# ya da ağ gerektirmez: php ve git, PATH'e konan sahte komutlarla değiştirilir.
# Sahte php her çağrıyı kaydeder; `yedek:yayin-oncesi` çıkış kodu
# SAHTE_YEDEK_KODU ile seçilir. Sahte git `fetch`'te durur: yedek adımından
# sonrası bu testlerin konusu değil, yalnız "yedekten sonra ne çalıştı" sınanır.
#
# Çalıştırma: scripts/tests/deploy.test.sh
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

SAHTE="$CALISMA/bin"
mkdir -p "$SAHTE"
cat > "$SAHTE/php" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAHTE_KAYIT/php.cagri"
if [[ "${2:-}" == "yedek:yayin-oncesi" ]]; then
  env | grep -o '^PG[A-Z_]*' | sort | tr '\n' ' ' > "$SAHTE_KAYIT/yedek.pg_ortami"
  exit "${SAHTE_YEDEK_KODU:-0}"
fi
exit 0
EOF
cat > "$SAHTE/git" <<'EOF'
#!/usr/bin/env bash
printf 'git %s\n' "$*" >> "$SAHTE_KAYIT/php.cagri"
[[ "${1:-}" == "fetch" ]] && exit 128
exit 0
EOF
chmod +x "$SAHTE/php" "$SAHTE/git"

# Proje kökü kopyası: deploy.sh kendi klasöründe .git ve backend/.env arar
kur() {
  KOK="$CALISMA/kok-$1"
  KAYIT="$CALISMA/kayit-$1"
  mkdir -p "$KOK/.git" "$KOK/backend" "$KAYIT"
  cp "$BETIK" "$KOK/deploy.sh"
  # Elle ayrıştırılırsa tırnağı ve yorumu bozulan bir parola (BY-001-R1)
  printf 'DB_DATABASE=erp\nDB_USERNAME=erp\nDB_PASSWORD="a#b c" # yorum\n' > "$KOK/backend/.env"
}

calistir() {
  (cd "$KOK" && env -u PGPASSWORD PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" SAHTE_YEDEK_KODU="$1" \
    EMOR_BACKUP_DIR=/var/backups/emor-test bash ./deploy.sh "${@:2}" >"$KAYIT/cikti" 2>&1)
  echo $?
}

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
esit "ilk çağrı yedek, sonra bakım modu" "$(head -n2 "$KAYIT/php.cagri" | tr '\n' '|')" \
  "backend/artisan yedek:yayin-oncesi --eski-klasor=/var/backups/emor-test|backend/artisan down|"
esit "yedeğe PG ortam değişkeni (PGPASSWORD) geçirilmez" "$(cat "$KAYIT/yedek.pg_ortami")" ""

kur yedeksiz
calistir 1 --no-backup >/dev/null
icermiyor "--no-backup yedeği atlar" "$KAYIT/php.cagri" "yedek:yayin-oncesi"
iceriyor "--no-backup ile yayın devam eder" "$KAYIT/php.cagri" "git fetch"

icermiyor "deploy.sh .env okumaz (env_val yok)" "$BETIK" "env_val"
icermiyor "deploy.sh kendi pg_dump'ını çalıştırmaz" "$BETIK" "pg_dump -h"

echo
echo "Geçen: $GECEN, kalan: $KALAN"
[[ "$KALAN" -eq 0 ]]
