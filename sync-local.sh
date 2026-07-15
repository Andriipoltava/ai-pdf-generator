#!/usr/bin/env bash
# Синхронізує код плагіна з робочого репо в локальний WordPress (local.dev).
# vendor/ не чіпаємо — mPDF там уже встановлений.
set -euo pipefail
SRC="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DST="/Users/andrii_1/Sites/adscalePlugin/wp-content/plugins/ai-pdf-generator"
rsync -a --delete "$SRC/includes/" "$DST/includes/"
rsync -a --delete "$SRC/assets/"   "$DST/assets/"
rsync -a "$SRC/ai-pdf-generator.php" "$DST/ai-pdf-generator.php"
echo "Synced to local.dev"
