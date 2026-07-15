#!/usr/bin/env bash
#
# Збірка release-архіву плагіна AI PDF Generator.
#
# Результат: build/ai-pdf-generator-<version>.zip — повний архів
# із vendor/, готовий до завантаження через «Плагіни → Додати новий».
#
# Використання: ./build.sh

set -euo pipefail

PLUGIN_SLUG="ai-pdf-generator"
SRC_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
BUILD_DIR="${SRC_DIR}/build"

# Версія — з заголовка головного файлу плагіна.
VERSION="$( grep -m1 -E '^\s*\*\s*Version:' "${SRC_DIR}/${PLUGIN_SLUG}.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' )"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Збірка ${ZIP_NAME}"

# 1. Свіжі production-залежності (без dev, з оптимізованим автолоадом).
echo "==> composer install --no-dev"
composer install --working-dir="${SRC_DIR}" --no-dev --optimize-autoloader --no-interaction --quiet

# 2. Чистий staging: копіюємо тільки потрібне, у теку з ім'ям slug —
#    WordPress розпакує архів у wp-content/plugins/ai-pdf-generator/.
STAGING="$( mktemp -d )"
trap 'rm -rf "${STAGING}"' EXIT

mkdir -p "${STAGING}/${PLUGIN_SLUG}"
rsync -a "${SRC_DIR}/" "${STAGING}/${PLUGIN_SLUG}/" \
    --exclude '.git' \
    --exclude '.gitignore' \
    --exclude '.gitattributes' \
    --exclude 'build.sh' \
    --exclude 'build/' \
    --exclude '*.zip' \
    --exclude '.DS_Store' \
    --exclude 'Thumbs.db' \
    --exclude '.idea' \
    --exclude '.vscode' \
    --exclude 'composer.lock' \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude '*.log'

# 3. Пакуємо.
mkdir -p "${BUILD_DIR}"
rm -f "${BUILD_DIR}/${ZIP_NAME}"
( cd "${STAGING}" && zip -rq "${BUILD_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}" )

echo "==> Готово: build/${ZIP_NAME} ($( du -h "${BUILD_DIR}/${ZIP_NAME}" | cut -f1 | tr -d ' ' ))"
echo "==> Вміст (перші 15 записів):"
# awk замість head: head закриває pipe достроково і через pipefail
# скрипт завершувався б помилковим кодом 141 (SIGPIPE).
unzip -l "${BUILD_DIR}/${ZIP_NAME}" | awk 'NR <= 15'
