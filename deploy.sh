#!/usr/bin/env bash
#
# ERP Web (emor) — deploy scripti (production sunucuda çalışır)
# GitHub'dan son kodu çeker, bağımlılıkları kurar, frontend'i build eder,
# migration'ları çalıştırır ve uygulamayı canlıya alır.
#
# Kullanım: ./deploy.sh [--branch main] [--with-seed] [--no-backup] [--no-down]
#
set -euo pipefail

BRANCH="main"
WITH_SEED=0
WITH_BACKUP=1
WITH_DOWN=1
BACKUP_DIR="${EMOR_BACKUP_DIR:-/var/backups/emor}"
# Yayın kilidi başkasındaysa en çok bu kadar saniye beklenir (BY-013-R1)
KILIT_BEKLEME="${EMOR_YAYIN_KILIT_BEKLEME:-60}"

usage() {
  cat <<'EOF'
Kullanım: ./deploy.sh [seçenekler]

Seçenekler:
  --branch <ad>   Deploy edilecek branch (varsayılan: main)
  --with-seed     Migration sonrası php artisan db:seed çalıştır
  --no-backup     PostgreSQL yedeğini atla
  --no-down       Bakım modunu (artisan down/up) atla
  -h, --help      Bu yardımı göster

Yedek dizini EMOR_BACKUP_DIR ortam değişkeni ile değiştirilebilir
(varsayılan: /var/backups/emor). Yedekleme modülü açıkken yayın kilidi
en çok EMOR_YAYIN_KILIT_BEKLEME saniye beklenir (varsayılan: 60).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --branch)    BRANCH="${2:-}"; shift 2 ;;
    --with-seed) WITH_SEED=1; shift ;;
    --no-backup) WITH_BACKUP=0; shift ;;
    --no-down)   WITH_DOWN=0; shift ;;
    -h|--help)   usage; exit 0 ;;
    *) echo "Bilinmeyen seçenek: $1"; usage; exit 1 ;;
  esac
done

if [[ ! -f deploy.sh || ! -d .git ]]; then
  echo "HATA: Bu script sunucuda, git clone edilmiş proje kökünde çalıştırılmalı."
  exit 1
fi

if [[ ! -f backend/.env ]]; then
  echo "HATA: backend/.env bulunamadı. İlk kurulum tamamlanmamış."
  exit 1
fi

if [[ ! "$KILIT_BEKLEME" =~ ^[1-9][0-9]{0,4}$ ]]; then
  echo "HATA: EMOR_YAYIN_KILIT_BEKLEME pozitif bir tam sayı (saniye) olmalı: '$KILIT_BEKLEME'"
  exit 1
fi

echo "==> Deploy başladı (branch: $BRANCH)"

# --------- 0. Yayın koruması (BY-013-R1, BY-029-R1) ---------
# Yedekleme modülü açıksa yayın, betik bitene kadar YAYIN KİLİDİNİ tutar
# (fd 9; süreç bitince — öldürülse de — çekirdek bırakır). Üstüne geri yükleme
# bu kilit tutulurken başlamaz; kilit alındıktan sonra açık bir geri yükleme
# (onay bekleniyor / sürüyor) varsa yayın, iki yolda da (yedekli ve
# --no-backup) bakım modu, kod çekme, migration ve `artisan up` olmadan durur.
# Kilit, yedek işlem kilidinden ayrıdır: yayın öncesi yedek servisten istenirken
# ikisi birbirini beklemez. Modül durumu ve kilidin yolu uygulamanın komutundan
# gelir (bu betik .env okumaz — G3); komut çalışmazsa açık geri yükleme
# bilinemez, yayın durur (bilinmeyen durum izin değildir — D6).
if ! KILIT_YOLU="$(php backend/artisan yedek:yayin-korumasi kilit-yolu)"; then
  echo "HATA: Yayın koruması (yedek:yayin-korumasi) çalışmadı; açık geri yükleme denetlenemedi, yayın yapılmadı."
  exit 1
fi
if [[ -n "$KILIT_YOLU" ]]; then
  if [[ ! -r "$KILIT_YOLU" ]] || ! exec 9<"$KILIT_YOLU"; then
    echo "HATA: Yayın kilidi açılamadı: $KILIT_YOLU (kurulum: scripts/sunucu-yedekleme-kurulumu.sh); yayın yapılmadı."
    exit 1
  fi
  KILIT_KODU=0
  flock -w "$KILIT_BEKLEME" -E 75 9 || KILIT_KODU=$?
  if [[ "$KILIT_KODU" -eq 75 ]]; then
    echo "HATA: Başka bir yayın ya da üstüne geri yükleme sürüyor (yayın kilidi $KILIT_BEKLEME sn'de boşalmadı); yayın yapılmadı. Biraz sonra tekrar deneyin."
    exit 1
  elif [[ "$KILIT_KODU" -ne 0 ]]; then
    echo "HATA: Yayın kilidi alınamadı (flock çıkış $KILIT_KODU; başka işlem tutmuyor); yayın yapılmadı."
    exit 1
  fi
  if ! php backend/artisan yedek:yayin-korumasi denetle; then
    echo "HATA: Açık geri yükleme var ya da durum okunamadı; yayın yapılmadı (bakım modu, kod ve veritabanına dokunulmadı)."
    exit 1
  fi
fi

# --------- 1. PostgreSQL yedeği (deploy ÖNCESİ durum) ---------
# Yedeği uygulama alır (BY-013): modül açıksa imzalı "yayın öncesi" yedek
# (açık geri yükleme varken başlamaz); kapalıysa eski davranış —
# $BACKUP_DIR'e <vt>_predeploy_<zaman>.dump. Bağlantı bilgisi Laravel'in
# yapılandırmasından, parola PGPASSFILE ile gelir; bu betik .env okumaz.
# Yedek alınamazsa yayın burada durur: bakım modu, kod çekme, migration yok.
if [[ "$WITH_BACKUP" -eq 1 ]]; then
  echo "==> PostgreSQL yedeği (yayın öncesi)"
  if ! php backend/artisan yedek:yayin-oncesi --eski-klasor="$BACKUP_DIR"; then
    echo "HATA: Yayın öncesi yedek alınamadı; yayın yapılmadı. (Yedeksiz yayın için: --no-backup)"
    exit 1
  fi
fi

# --------- 2. Bakım modu ---------
# Yayın yalnız KENDİ açtığı bakım modunu kapatır: uygulama zaten bakım
# modundaysa (elle ya da bir geri yükleme açmış) down / up çağrılmaz.
BAKIM_BIZIM=0
if [[ "$WITH_DOWN" -eq 1 ]]; then
  if [[ -e backend/storage/framework/down ]]; then
    echo "==> NOT: Uygulama zaten bakım modunda (bu yayın açmadı); yayın bakım modunu açıp kapatmayacak."
  else
    BAKIM_BIZIM=1
  fi
fi

cleanup() {
  if [[ "$BAKIM_BIZIM" -eq 1 ]]; then
    php backend/artisan up || true
  fi
}
trap cleanup EXIT

if [[ "$BAKIM_BIZIM" -eq 1 ]]; then
  php backend/artisan down || true
fi

# --------- 3. Kod güncelleme ---------
echo "==> Kod çekiliyor (origin/$BRANCH)"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git config core.fileMode false

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "HATA: Sunucuda izlenen dosyalarda lokal değişiklik var. Önce commit/stash/reset gerekli:"
  git status --short
  exit 1
fi

git pull --ff-only origin "$BRANCH"

# --------- 4. Bağımlılıklar + frontend build ---------
echo "==> Composer bağımlılıkları"
(cd backend && composer install --no-dev --optimize-autoloader --no-interaction)

echo "==> Frontend build (npm ci + vite build)"
(cd frontend && npm ci && npm run build)

# --------- 5. Veritabanı + cache ---------
echo "==> Migration'lar"
php backend/artisan migrate --force

if [[ "$WITH_SEED" -eq 1 ]]; then
  echo "==> Seeder"
  php backend/artisan db:seed --force
fi

php backend/artisan storage:link || true

echo "==> Cache yenileme"
php backend/artisan optimize:clear
php backend/artisan optimize

echo "==> Queue worker restart"
php backend/artisan queue:restart || true

echo "==> Deploy tamamlandı. (nginx frontend/dist'i doğrudan sunar; yeni build anında yayındadır)"
