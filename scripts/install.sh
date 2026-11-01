#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
node -e 'if(Number(process.versions.node.split(".")[0])<24)throw Error("Se requiere Node.js 24 o superior")'
umask 077
if [ ! -f .env ]; then cp .env.example .env; fi
mkdir -p data
chmod 700 data
npm ci
printf '\nInstalación lista. Configurá el administrador con npm run admin:setup y ejecutá npm start.\n'
