#!/usr/bin/env bash
#
# ERP Web (emor) — GEÇİCİ günlük PostgreSQL yedeği (BY-001).
#
# Asıl yedekleme sistemi (docs/yedekleme) gelene kadar, yayın yapılmayan
# günlerde de yedek alınsın diye yayın kullanıcısının zamanlayıcısından günde
# bir çalışır. BY-013 canlıya alınınca bu betik ve zamanlayıcı satırı kaldırılır.
#
# Yaptığı: backend/.env'deki veritabanını pg_dump (custom biçim) ile yedekler.
#   - Önce geçici ada yazar, doğrular, sonra asıl adına taşır: yarım dosya
#     hiçbir zaman "yedek" gibi görünmez.
#   - Parolayı komut satırına ve ortam değişkenine koymaz; yalnız bu kullanıcının
#     okuyabildiği geçici bir parola dosyası kullanır (PGPASSFILE).
#   - Yalnız kendi ürettiği dosyaları temizler (<veritabanı>_gunluk_*.dump);
#     deploy.sh'ın <veritabanı>_predeploy_* yedeklerine dokunmaz.
#
# Kullanım (proje kökünden bağımsız çalışır):
#   scripts/gecici-gunluk-yedek.sh
#
# Ortam değişkenleri (isteğe bağlı):
#   EMOR_BACKUP_DIR         yedek klasörü           (varsayılan /var/backups/emor)
#   EMOR_GUNLUK_SAKLA_GUN   bu günden eskiler silinir (varsayılan 30)
#   EMOR_GUNLUK_EN_AZ       her durumda tutulan en yeni yedek sayısı (varsayılan 7)
#   EMOR_ENV_DOSYASI        .env yolu               (varsayılan <proje>/backend/.env)
#
# Çıkış kodu: 0 başarılı; 1 hata (yedek alınamadı); 75 başka bir kopya çalışıyor.
#
set -euo pipefail

# Yedek bütün veriyi içerir: dosyalar yalnız sahibi tarafından okunabilir
umask 077

PROJE_KOKU="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DOSYASI="${EMOR_ENV_DOSYASI:-$PROJE_KOKU/backend/.env}"
YEDEK_KLASORU="${EMOR_BACKUP_DIR:-/var/backups/emor}"
SAKLA_GUN="${EMOR_GUNLUK_SAKLA_GUN:-30}"
EN_AZ="${EMOR_GUNLUK_EN_AZ:-7}"

GUNLUK="$YEDEK_KLASORU/gecici-gunluk-yedek.log"
GECICI=""
PAROLA_DOSYASI=""

gunluge_yaz() {
  local satir
  satir="$(date '+%F %T') $*"
  echo "$satir"
  # Günlük yazılamıyorsa (klasör yok vb.) asıl hata mesajı yine ekranda kalır
  echo "$satir" >> "$GUNLUK" 2>/dev/null || true
}

hata() {
  gunluge_yaz "HATA: $*"
  exit 1
}

temizle() {
  # Hata yolunda geçici yedek ve parola dosyası bırakılmaz
  [[ -n "$GECICI" && -f "$GECICI" ]] && rm -f "$GECICI"
  [[ -n "$PAROLA_DOSYASI" && -f "$PAROLA_DOSYASI" ]] && rm -f "$PAROLA_DOSYASI"
  return 0
}
trap temizle EXIT

env_degeri() {
  # deploy.sh ile aynı okuma kuralı: ilk eşleşen satır, tırnaklar atılır
  grep -E "^$1=" "$ENV_DOSYASI" | head -n1 | cut -d'=' -f2- | tr -d '"' | tr -d "'" || true
}

pozitif_tam_sayi_mi() {
  [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 ))
}

# --------- 1. Ön denetimler ---------
[[ -f "$ENV_DOSYASI" ]] || hata "$ENV_DOSYASI bulunamadı."
[[ -d "$YEDEK_KLASORU" && -w "$YEDEK_KLASORU" ]] || hata "Yedek klasörü yok ya da yazılamıyor: $YEDEK_KLASORU"
pozitif_tam_sayi_mi "$SAKLA_GUN" || hata "EMOR_GUNLUK_SAKLA_GUN pozitif tam sayı olmalı: $SAKLA_GUN"
pozitif_tam_sayi_mi "$EN_AZ" || hata "EMOR_GUNLUK_EN_AZ pozitif tam sayı olmalı: $EN_AZ"
command -v pg_dump >/dev/null 2>&1 || hata "pg_dump bulunamadı."
command -v pg_restore >/dev/null 2>&1 || hata "pg_restore bulunamadı."

VT_ADI="$(env_degeri DB_DATABASE)"
VT_KULLANICI="$(env_degeri DB_USERNAME)"
VT_PAROLA="$(env_degeri DB_PASSWORD)"
VT_SUNUCU="$(env_degeri DB_HOST)"; VT_SUNUCU="${VT_SUNUCU:-127.0.0.1}"
VT_PORT="$(env_degeri DB_PORT)"; VT_PORT="${VT_PORT:-5432}"

[[ -n "$VT_ADI" && -n "$VT_KULLANICI" ]] || hata "$ENV_DOSYASI içinde DB_DATABASE / DB_USERNAME boş."
# Dosya adına girecek: yalnız güvenli karakterler
[[ "$VT_ADI" =~ ^[A-Za-z0-9_]+$ ]] || hata "Veritabanı adı dosya adı için uygun değil: $VT_ADI"

# --------- 2. Tek kopya ---------
# Önceki çalışma hâlâ sürüyorsa ikincisi başlamaz (zamanlayıcı üst üste binmesin)
exec 9>"$YEDEK_KLASORU/.gecici-gunluk-yedek.kilit"
if ! flock -n 9; then
  gunluge_yaz "ATLANDI: başka bir yedek çalışıyor."
  exit 75
fi

# --------- 3. Yedek ---------
ZAMAN="$(date +%F_%H%M%S)"
HEDEF="$YEDEK_KLASORU/${VT_ADI}_gunluk_${ZAMAN}.dump"
# Aynı saniyede ikinci çalışma var olan yedeğin üzerine yazmasın
SIRA=2
while [[ -e "$HEDEF" ]]; do
  HEDEF="$YEDEK_KLASORU/${VT_ADI}_gunluk_${ZAMAN}_${SIRA}.dump"
  SIRA=$((SIRA + 1))
done
GECICI="$(mktemp "$YEDEK_KLASORU/.${VT_ADI}_gunluk_${ZAMAN}.XXXXXX.tmp")"

# Parola dosyası: sunucu:port:veritabanı:kullanıcı:parola (":" ve "\" kaçırılır)
pgpass_kacir() {
  local deger="${1//\\/\\\\}"
  printf '%s' "${deger//:/\\:}"
}
PAROLA_DOSYASI="$(mktemp "$YEDEK_KLASORU/.pgpass.XXXXXX")"
printf '%s:%s:%s:%s:%s\n' \
  "$(pgpass_kacir "$VT_SUNUCU")" "$(pgpass_kacir "$VT_PORT")" "$(pgpass_kacir "$VT_ADI")" \
  "$(pgpass_kacir "$VT_KULLANICI")" "$(pgpass_kacir "$VT_PAROLA")" > "$PAROLA_DOSYASI"

if ! PGPASSFILE="$PAROLA_DOSYASI" pg_dump \
    --host="$VT_SUNUCU" --port="$VT_PORT" --username="$VT_KULLANICI" \
    --dbname="$VT_ADI" --no-password --format=custom --file="$GECICI"; then
  hata "pg_dump başarısız; yedek alınamadı."
fi

# --------- 4. Doğrulama ---------
[[ -s "$GECICI" ]] || hata "Yedek dosyası boş."
# İçindekiler okunabiliyor mu ve bütün veri blokları açılıyor mu (veritabanı gerekmez)
pg_restore --list "$GECICI" >/dev/null 2>&1 || hata "Yedek okunamıyor (pg_restore --list)."
pg_restore --file=/dev/null "$GECICI" >/dev/null 2>&1 || hata "Yedeğin verisi okunamıyor (pg_restore)."

mv "$GECICI" "$HEDEF"
GECICI=""
BOYUT="$(stat -c %s "$HEDEF")"
gunluge_yaz "TAMAM: $(basename "$HEDEF") ($BOYUT bayt)"

# --------- 5. Temizlik (yalnız bu betiğin dosyaları) ---------
# En yeni EN_AZ dosya her durumda kalır; kalanlardan SAKLA_GUN günden eskiler silinir.
# Ad zaman damgası taşıdığı için ada göre ters sıra = yeniden eskiye.
SILINEN=0
SAYAC=0
while IFS= read -r DOSYA; do
  SAYAC=$((SAYAC + 1))
  (( SAYAC <= EN_AZ )) && continue
  if [[ -n "$(find "$DOSYA" -maxdepth 0 -type f -mtime +"$SAKLA_GUN" -print 2>/dev/null)" ]]; then
    rm -f "$DOSYA"
    SILINEN=$((SILINEN + 1))
  fi
done < <(find "$YEDEK_KLASORU" -maxdepth 1 -type f -name "${VT_ADI}_gunluk_*.dump" | sort -r)

# Çökmüş eski çalışmalardan kalan geçici dosyalar (bir günden eski)
find "$YEDEK_KLASORU" -maxdepth 1 -type f \
  \( -name ".${VT_ADI}_gunluk_*.tmp" -o -name ".pgpass.*" \) -mmin +1440 -delete 2>/dev/null || true

(( SILINEN > 0 )) && gunluge_yaz "TEMIZLIK: $SILINEN eski günlük yedek silindi."
exit 0
