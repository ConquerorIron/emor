#!/usr/bin/env bash
#
# ERP Web (emor) — publish scripti (lokal makinede / WSL'de çalışır)
# Kalite kontrollerini çalıştırır, commit'ler ve GitHub'a push'lar.
#
# Kullanım: ./publish.sh --message "feat(ayarlar): kısa açıklama"
#
set -euo pipefail

# Ortam koruması: araç zinciri (node_modules, PHP, composer) WSL Ubuntu'ya
# kuruludur. Windows terminalinden çalıştırılırsa yanıltıcı hatalar çıkar.
if [[ "$(uname -s)" != "Linux" ]]; then
  echo "HATA: publish.sh WSL (Ubuntu-24.04) içinde çalıştırılmalıdır."
  echo "Şöyle çalıştırın:"
  echo "  wsl -d Ubuntu-24.04 bash -lc 'cd ~/projects/erp && ./publish.sh --message \"...\"'"
  exit 1
fi

BRANCH="main"
MESSAGE=""
RUN_TESTS=1
RUN_BUILD=1

usage() {
  cat <<'EOF'
Kullanım: ./publish.sh --message "commit mesajı" [seçenekler]

Seçenekler:
  --message <metin>   Commit mesajı (zorunlu; Conventional Commits: feat|fix|refactor|docs|test|chore)
  --branch <ad>       Git branch (varsayılan: main)
  --no-test           Test ve statik analiz adımlarını atla
  --no-build          Frontend doğrulama build'ini atla
  -h, --help          Bu yardımı göster
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --message)  MESSAGE="${2:-}"; shift 2 ;;
    --branch)   BRANCH="${2:-}"; shift 2 ;;
    --no-test)  RUN_TESTS=0; shift ;;
    --no-build) RUN_BUILD=0; shift ;;
    -h|--help)  usage; exit 0 ;;
    *) echo "Bilinmeyen seçenek: $1"; usage; exit 1 ;;
  esac
done

if [[ -z "$MESSAGE" ]]; then
  echo "HATA: --message zorunlu."
  usage
  exit 1
fi

if [[ ! -f publish.sh || ! -d backend ]]; then
  echo "HATA: Bu script proje kökünde çalıştırılmalı."
  exit 1
fi

if [[ ! -d .git ]]; then
  echo "HATA: Git repo bulunamadı. Önce:"
  echo "  git init -b main && git remote add origin git@github.com:ConquerorIron/emor.git"
  exit 1
fi

echo "==> Branch kontrolü"
CURRENT_BRANCH="$(git symbolic-ref --short -q HEAD || echo "$BRANCH")"
if [[ "$CURRENT_BRANCH" != "$BRANCH" ]]; then
  echo "Aktif branch '$CURRENT_BRANCH'. '$BRANCH' branch'ine geçiliyor..."
  git checkout "$BRANCH"
fi

echo "==> Remote kontrolü"
if ! git fetch origin "$BRANCH" 2>/dev/null; then
  echo "Uyarı: origin/$BRANCH henüz yok (ilk push öncesi normal)."
fi

# --------- Backend kontrolleri ---------
if [[ -f backend/artisan ]]; then
  if [[ "$RUN_TESTS" -eq 1 ]]; then
    if [[ -x backend/vendor/bin/pint ]]; then
      echo "==> Backend: Pint (format kontrolü)"
      (cd backend && ./vendor/bin/pint --test)
    fi
    echo "==> Backend: testler"
    (cd backend && php artisan test)
  fi
fi

# --------- Frontend kontrolleri ---------
if [[ -f frontend/package.json ]]; then
  if [[ "$RUN_TESTS" -eq 1 ]]; then
    echo "==> Frontend: lint + typecheck + test"
    (cd frontend && npm run lint --if-present)
    (cd frontend && npm run typecheck --if-present)
    (cd frontend && npm run test --if-present -- --run)
  fi
  if [[ "$RUN_BUILD" -eq 1 ]]; then
    echo "==> Frontend: doğrulama build'i (çıktı commit edilmez; dist/ gitignore'dadır)"
    (cd frontend && npm run build)
  fi
fi

# --------- Commit + Push ---------
if [[ -z "$(git status --porcelain)" ]]; then
  echo "Değişiklik yok. Commit atlanıyor."
  exit 0
fi

echo "==> Commit + Push"
git add -A
git commit -m "$MESSAGE"
git push -u origin "$BRANCH"

echo "==> Yayınlandı: https://github.com/ConquerorIron/emor/tree/$BRANCH"
echo "Hatırlatma: Sunucuda yayına almak için: ssh admin@10.2.30.67 'cd /var/www/emor && ./deploy.sh'"
