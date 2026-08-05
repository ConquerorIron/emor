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

env_val() {
  grep -E "^$1=" backend/.env | head -n1 | cut -d'=' -f2- | tr -d '"' | tr -d "'"
}

echo "==> Deploy başladı (branch: $BRANCH)"

# --------- 1. PostgreSQL yedeği (deploy ÖNCESİ durum) ---------
if [[ "$WITH_BACKUP" -eq 1 ]]; then
  DB_DATABASE="$(env_val DB_DATABASE)"
  DB_USERNAME="$(env_val DB_USERNAME)"
  DB_PASSWORD="$(env_val DB_PASSWORD)"
  DB_HOST="$(env_val DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="$(env_val DB_PORT)"; DB_PORT="${DB_PORT:-5432}"

  if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "HATA: backend/.env içinde DB_DATABASE / DB_USERNAME boş."
    exit 1
  fi

  if ! command -v pg_dump >/dev/null 2>&1; then
    echo "HATA: pg_dump bulunamadı — ya kurun ya --no-backup kullanın."
    exit 1
  fi

  if [[ ! -d "$BACKUP_DIR" ]]; then
    mkdir -p "$BACKUP_DIR" 2>/dev/null || { sudo mkdir -p "$BACKUP_DIR" && sudo chown "$(id -un)" "$BACKUP_DIR"; }
  fi

  TS="$(date +%F_%H%M%S)"
  BACKUP_FILE="$BACKUP_DIR/${DB_DATABASE}_predeploy_${TS}.dump"
  echo "==> PostgreSQL yedeği: $BACKUP_FILE"
  PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" \
    -d "$DB_DATABASE" --format=custom --file="$BACKUP_FILE"
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
