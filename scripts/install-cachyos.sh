#!/usr/bin/env bash
# ============================================================================
# UPM for Nextcloud — CachyOS / Arch Linux developer setup
# ============================================================================
# Installs the toolchain needed to implement and test the app (Option A):
#   - Node.js + pnpm            (TypeScript frontend, Vitest, Playwright)
#   - PHP + Composer            (thin Nextcloud backend)
#   - Docker + Docker Compose   (local Nextcloud instance for testing)
#   - base-devel                (AUR helper prerequisites)
#
# Usage:
#   bash scripts/install-cachyos.sh                 # toolchain only
#   bash scripts/install-cachyos.sh --with-nextcloud # also pull a Nextcloud dev instance
#
# The script is idempotent: re-running it upgrades installed packages and
# skips work that is already done.
# ============================================================================
set -euo pipefail

WITH_NEXTCLOUD=0
for arg in "$@"; do
  case "$arg" in
    --with-nextcloud) WITH_NEXTCLOUD=1 ;;
    -h|--help)
      echo "Usage: bash scripts/install-cachyos.sh [--with-nextcloud]"
      exit 0
      ;;
  esac
done

if [ "$(id -u)" -eq 0 ]; then
  echo "Do not run this script as root; it uses sudo for the few steps that need it."
  exit 1
fi

# --- helpers ---------------------------------------------------------------
have() { command -v "$1" >/dev/null 2>&1; }

sudo_pacman() { sudo pacman -S --noconfirm --needed "$@"; }

aur_helper() {
  if have paru; then echo paru; elif have yay; then echo yay; else echo ""; fi
}

# --- 1. refresh system + base toolchain ------------------------------------
echo "==> Updating system and installing base packages"
sudo pacman --noconfirm -Syu
sudo_pacman base-devel git

# --- 2. Node.js / pnpm ------------------------------------------------------
echo "==> Installing Node.js toolchain"
sudo_pacman nodejs npm pnpm

# --- 3. PHP + Composer (for the Nextcloud backend) -------------------------
echo "==> Installing PHP toolchain"
# Arch bundles curl, mbstring, xml, zip, bz2 and intl inside the main `php`
# package; only a handful of extensions are split out (gd, sqlite, fpm, ...).
sudo_pacman php composer php-fpm php-gd php-sqlite

# --- 4. Docker (local Nextcloud test instance) -----------------------------
echo "==> Installing Docker"
sudo_pacman docker docker-compose docker-buildx

if ! systemctl is-active --quiet docker 2>/dev/null; then
  echo "==> Enabling and starting the Docker service"
  sudo systemctl enable --now docker.service
fi

if ! groups | tr ' ' '\n' | grep -qx docker; then
  echo "==> Adding $(whoami) to the docker group (log out/in to take effect)"
  sudo usermod -aG docker "$(whoami)"
fi

# --- 5. AUR helper (CachyOS usually ships paru already) --------------------
AUR="$(aur_helper)"
if [ -z "$AUR" ]; then
  echo "==> No AUR helper found; installing paru"
  sudo_pacman --asdeps fakeroot debugedit
  git clone https://aur.archlinux.org/paru-bin.git /tmp/paru-bin
  (cd /tmp/paru-bin && makepkg -si --noconfirm)
  rm -rf /tmp/paru-bin
  AUR="$(aur_helper)"
fi
echo "==> AUR helper in use: ${AUR}"

# --- 6. optional: local Nextcloud dev instance via Docker ------------------
if [ "$WITH_NEXTCLOUD" -eq 1 ]; then
  echo "==> Setting up a local Nextcloud dev instance (docker compose)"
  SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
  mkdir -p "$PROJECT_ROOT/.dev"
  cat > "$PROJECT_ROOT/.dev/docker-compose.nextcloud.yml" <<'YAML'
services:
  nextcloud:
    image: nextcloud:28-apache
    ports:
      - "8080:80"
    environment:
      SQLITE_DATABASE: upm
      NEXTCLOUD_ADMIN_USER: admin
      NEXTCLOUD_ADMIN_PASSWORD: admin
    volumes:
      - nextcloud-data:/var/www/html
      - ./apps-extra:/var/www/html/custom_apps/upm
volumes:
  nextcloud-data:
YAML
  echo "    compose file written to .dev/docker-compose.nextcloud.yml"
  echo "    start it with:  docker compose -f .dev/docker-compose.nextcloud.yml up -d"
  echo "    the upm app is bind-mounted from .dev/apps-extra -> copy/symlink the app there"
fi

# --- 7. summary ------------------------------------------------------------
echo ""
echo "=============================================================="
echo " Setup complete."
echo "  node        $(node --version 2>/dev/null || echo 'n/a')"
echo "  npm         $(npm --version 2>/dev/null || echo 'n/a')"
echo "  pnpm        $(pnpm --version 2>/dev/null || echo 'n/a')"
echo "  php         $(php --version 2>/dev/null | head -1 || echo 'n/a')"
echo "  composer    $(composer --version 2>/dev/null | head -1 || echo 'n/a')"
echo "  docker      $(docker --version 2>/dev/null || echo 'n/a')"
echo "=============================================================="
echo "Next steps:"
echo "  1. log out and back in (docker group change)"
echo "  2. npm install   (install project JS deps)"
echo "  3. npx playwright install chromium   (browser for UI tests)"
[ "$WITH_NEXTCLOUD" -eq 1 ] && \
  echo "  4. docker compose -f .dev/docker-compose.nextcloud.yml up -d"
