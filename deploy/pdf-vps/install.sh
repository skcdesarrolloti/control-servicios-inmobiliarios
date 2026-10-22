#!/usr/bin/env bash
# Servicio de PDF del panel (Gotenberg + Chromium) en el VPS, con HTTPS automático (Caddy).
# Uso (como root en el VPS):  bash install.sh pdf.sucasainmobiliaria.com.co
# Es idempotente: si ya existe /opt/skc-pdf conserva la contraseña y solo actualiza/reinicia.
set -euo pipefail

DOMAIN="${1:-}"
if [[ -z "$DOMAIN" ]]; then
  echo "Uso: bash install.sh pdf.tudominio.com" >&2
  exit 1
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Ejecútalo como root (sudo bash install.sh $DOMAIN)." >&2
  exit 1
fi

DIR=/opt/skc-pdf
mkdir -p "$DIR"
cd "$DIR"

echo "==> 1/6 Comprobando que el dominio apunte a este VPS"
SERVER_IP="$(curl -4 -fsS https://api.ipify.org || true)"
DOMAIN_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)"
echo "    IP del VPS: ${SERVER_IP:-desconocida} · $DOMAIN -> ${DOMAIN_IP:-sin registro}"
if [[ -z "$DOMAIN_IP" || "$DOMAIN_IP" != "$SERVER_IP" ]]; then
  echo "    El registro A de $DOMAIN debe apuntar a $SERVER_IP antes de seguir (HTTPS no se podrá emitir)." >&2
  exit 1
fi

echo "==> 2/6 Revisando puertos 80 y 443"
if ss -ltnp | grep -E ':(80|443)\s' | grep -vq 'docker-proxy'; then
  echo "    Los puertos 80/443 ya los usa otro programa:" >&2
  ss -ltnp | grep -E ':(80|443)\s' >&2
  echo "    Libéralos o avisa para integrarlo con el proxy existente." >&2
  exit 1
fi

echo "==> 3/6 Instalando Docker si falta"
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker >/dev/null

echo "==> 4/6 Credenciales"
if [[ -f .env ]]; then
  # shellcheck disable=SC1091
  source .env
fi
PDF_USER="${PDF_USER:-skc-panel}"
PDF_PASSWORD="${PDF_PASSWORD:-$(openssl rand -hex 24)}"
cat > .env <<EOF
DOMAIN=$DOMAIN
PDF_USER=$PDF_USER
PDF_PASSWORD=$PDF_PASSWORD
EOF
chmod 600 .env

cat > docker-compose.yml <<'EOF'
services:
  gotenberg:
    image: gotenberg/gotenberg:8
    restart: unless-stopped
    # Solo accesible desde Caddy (red interna de Docker); no publica puertos.
    command:
      - gotenberg
      - --api-timeout=90s
      - --api-enable-basic-auth
      - --chromium-restart-after=50
      - --chromium-max-queue-size=20
      - --log-level=warn
    environment:
      GOTENBERG_API_BASIC_AUTH_USERNAME: ${PDF_USER}
      GOTENBERG_API_BASIC_AUTH_PASSWORD: ${PDF_PASSWORD}
    mem_limit: 2g
  caddy:
    image: caddy:2
    restart: unless-stopped
    depends_on:
      - gotenberg
    ports:
      - "80:80"
      - "443:443"
    environment:
      DOMAIN: ${DOMAIN}
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
volumes:
  caddy_data:
  caddy_config:
EOF

cat > Caddyfile <<'EOF'
{$DOMAIN} {
	encode gzip
	request_body {
		max_size 64MB
	}
	reverse_proxy gotenberg:3000 {
		transport http {
			read_timeout 120s
			write_timeout 120s
		}
	}
}
EOF

echo "==> 5/6 Iniciando servicios"
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then
  ufw allow 80/tcp >/dev/null
  ufw allow 443/tcp >/dev/null
fi
docker compose pull -q
docker compose up -d

echo "==> 6/6 Probando un PDF por HTTPS (puede tardar mientras se emite el certificado)"
TMP="$(mktemp -d)"
printf '<!doctype html><html><body><h1>SKC SuCasa Inmobiliaria</h1><p>Prueba del servicio de PDF.</p></body></html>' > "$TMP/index.html"
OK=0
for attempt in $(seq 1 24); do
  CODE="$(curl -s -o "$TMP/test.pdf" -w '%{http_code}' -u "$PDF_USER:$PDF_PASSWORD" \
    -F "files=@$TMP/index.html;filename=index.html;type=text/html" \
    "https://$DOMAIN/forms/chromium/convert/html" || true)"
  if [[ "$CODE" == "200" ]] && head -c 5 "$TMP/test.pdf" | grep -q '%PDF-'; then OK=1; break; fi
  sleep 5
done
rm -rf "$TMP"
if [[ "$OK" != "1" ]]; then
  echo "    No se pudo generar el PDF de prueba (último código HTTP: ${CODE:-sin respuesta})." >&2
  echo "    Revisa: docker compose -f $DIR/docker-compose.yml logs --tail=80" >&2
  exit 1
fi

cat <<EOF

========================================================================
 Servicio de PDF listo en https://$DOMAIN

 Copia estas 3 líneas en el .env del panel PHP (hosting) y en el local:

 SCM_GOTENBERG_URL=https://$DOMAIN
 SCM_GOTENBERG_USERNAME=$PDF_USER
 SCM_GOTENBERG_PASSWORD=$PDF_PASSWORD

 La contraseña queda guardada en $DIR/.env (solo root). No la subas a Git.
========================================================================
EOF
