<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nuevo Seguimiento</title>
</head>

<body style="margin:0; padding:0; background-color:#f5f5f5; font-family:Arial, sans-serif;">

  <table align="center" width="800" cellpadding="0" cellspacing="0" style="border:3px solid #ebecec; background:#ffffff;">
    <tr>
      <td>
        <!-- Puedes agregar un banner aquí si lo deseas -->
      </td>
    </tr>

    <tr>
      <td style="padding:20px; text-align:center; color:#061d49;">
        <h3 style="font-size:16px; margin-bottom:20px;">Apreciado/a {name}</h3>
        <p style="font-weight:500; margin:10px 0;">
          Se ha registrado un nuevo seguimiento:
        </p>
        
        <!-- Bloque de detalles del evento (si existe) -->
        <div style="background-color: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: left;">
          <p style="margin: 5px 0;"><strong>Evento:</strong> {titulo_evento}</p>
          <p style="margin: 5px 0;"><strong>Inicio:</strong> {fecha_inicio_evento}</p>
          <p style="margin: 5px 0;"><strong>Fin:</strong> {fecha_fin_evento}</p>
        </div>

        <p style="font-weight:500; margin:10px 0; background-color: #f0f0f0; padding: 10px; border-radius: 5px;">
          "{detalle}"
        </p>
        <p style="font-weight:500; margin:10px 0;">
          <b>Registrado por:</b> {usuario}
        </p>
        <p style="font-weight:500; margin:10px 0;">
          <b>Fecha:</b> {fecha}
        </p>
      </td>
    </tr>

    {bloque_accion}

    <tr>
      <td style="background:#f59120; text-align:center; font-weight:600; font-size:18px; padding:18px; color:white;">
        Una empresa para lograr sus sueños.
      </td>
    </tr>
  </table>

</body>

</html>
