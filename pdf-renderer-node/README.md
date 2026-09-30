# Renderizador privado de PDF para cotizaciones

Esta aplicación Node recibe el HTML que envía el panel PHP y devuelve el PDF con Chromium. Su ruta `POST /forms/chromium/convert/html` es compatible con la integración actual del panel. Requiere autenticación básica y HTTPS en producción.

## Crear la aplicación en Hostinger Cloud

1. En hPanel, entra a **Sitios web → Añadir sitio web → Desplegar aplicación web** y elige **Node.js**. Usa un **subdominio nuevo**, por ejemplo `pdf.tudominio.com`; conserva el sitio PHP actual.
2. Selecciona **Subir archivos** y carga un ZIP cuyo nivel superior contenga `package.json`, `package-lock.json`, `server.js` y `.puppeteerrc.cjs`. No incluyas `node_modules`, `.cache` ni `.env`.
3. Si hPanel solicita el tipo de aplicación, elige **Other/Otro**, Node.js **22**, archivo de entrada `server.js` y puerto **3000**. El comando de inicio es `npm start`; no se requiere comando de compilación.
4. En **Variables de entorno**, agrega `PDF_SERVICE_USER` y `PDF_SERVICE_PASSWORD`. Usa una contraseña aleatoria larga y diferente de las cuentas del panel. No la guardes en Git.
5. Despliega. Abre `https://pdf.tudominio.com/health`: debe responder `{"ok":true}`. Este chequeo arranca Chromium y confirma que está disponible. Si devuelve 503, consulta los registros de construcción y ejecución de Hostinger; el entorno no logró iniciar el navegador.
6. En el `.env` **del panel PHP**, configura:

   ```env
   SCM_GOTENBERG_URL=https://pdf.tudominio.com
   SCM_GOTENBERG_USERNAME=el_mismo_PDF_SERVICE_USER
   SCM_GOTENBERG_PASSWORD=el_mismo_PDF_SERVICE_PASSWORD
   ```

7. Guarda el `.env` y prueba **Enviar cotización**. El panel envía el HTML a la app Node. Si la app no responde, conserva la generación local de respaldo.

Hostinger permite [subir una app Node.js](https://www.hostinger.com/support/how-to-deploy-a-nodejs-website-in-hostinger/) y [configurar variables de entorno](https://www.hostinger.com/support/how-to-add-environment-variables-during-node-js-application-deployment/). La descarga de Chromium ocurre durante `npm install` de Puppeteer. Si el despliegue falla, revisa los [registros de despliegue](https://www.hostinger.com/support/how-to-troubleshoot-a-failed-node-js-deployment-using-build-logs/). Node.js por sí solo no confirma que Chromium pueda ejecutarse en ese plan: compruébalo con `/health` antes de conectar el PHP.

## Probar localmente

```powershell
cd pdf-renderer-node
npm ci
$env:PDF_SERVICE_USER='usuario-prueba'
$env:PDF_SERVICE_PASSWORD='clave-larga-de-prueba'
npm start
```

En otra terminal, abre `http://localhost:3000/health`. Para probar la conversión:

```powershell
curl.exe -u usuario-prueba:clave-larga-de-prueba -F 'files=@prueba.html;filename=index.html;type=text/html' -o prueba.pdf http://localhost:3000/forms/chromium/convert/html
```
