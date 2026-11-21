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
(varsayılan: /var/backups/emor).
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

echo "==> Deploy başladı (branch: $BRANCH)"

# --------- 1. PostgreSQL yedeği (deploy ÖNCESİ durum) ---------
# Yedeği uygulama alır (BY-013): modül açıksa imzalı "yayın öncesi" yedek,
# aynı kilitle (açık geri yükleme varken başlamaz); kapalıysa eski davranış —
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
cleanup() {
  if [[ "$WITH_DOWN" -eq 1 ]]; then
    php backend/artisan up || true
  fi
}
trap cleanup EXIT

if [[ "$WITH_DOWN" -eq 1 ]]; then
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
