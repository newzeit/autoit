#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Ejecutar después de revisar y traer la nueva versión. No modifica Git ni .env.
npm run backup
npm ci
npm test
printf '\nActualización verificada. Reiniciá tu servicio de ITurno AI y comprobá /api/health.\n'
