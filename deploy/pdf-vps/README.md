# Servicio de PDF en el VPS

El panel genera cotizaciones, órdenes, actas, informes y créditos en PDF enviando el HTML a un servicio
compatible con Gotenberg (`SCM_GOTENBERG_URL`). Este servicio corre en el VPS para que los procesos de
Chromium no cuenten en el límite de procesos del hosting compartido.

- **Gotenberg 8** (Chromium) en Docker, sin puertos públicos, con usuario y contraseña.
- **Caddy** delante, con certificado HTTPS automático (el panel exige HTTPS).
- Chromium se reinicia cada 50 PDF, la cola admite 20 peticiones y cada PDF tiene hasta 90 s.

## Instalar

1. En el DNS del dominio crea un registro **A** `pdf` → IP del VPS (queda `pdf.sucasainmobiliaria.com.co`).
2. En hPanel → VPS → **Terminal** (o por SSH como root):

   ```bash
   curl -fsSL https://raw.githubusercontent.com/skcdesarrolloti/control-servicios-inmobiliarios/main/deploy/pdf-vps/install.sh -o install.sh
   bash install.sh pdf.sucasainmobiliaria.com.co
   ```

3. Al final imprime las tres líneas `SCM_GOTENBERG_*`. Ponlas en el `.env` del panel en el hosting
   (reemplazando las anteriores) y prueba desde el hosting: `php bin/check-pdf-service.php`.
4. Con el panel funcionando, elimina en hPanel la app Node.js del PDF que estaba en el hosting.

El script se puede volver a ejecutar: conserva la contraseña de `/opt/skc-pdf/.env` y actualiza las imágenes.

## Operación

```bash
cd /opt/skc-pdf
docker compose ps                 # estado
docker compose logs --tail=80     # errores recientes
docker compose pull && docker compose up -d   # actualizar
```

Si el VPS no responde, el panel reintenta una vez y luego muestra "No se pudo generar … con Chromium".
