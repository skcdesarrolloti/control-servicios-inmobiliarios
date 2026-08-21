<?php

namespace SCM\Support;

final class HistoryLinkMap
{
  /**
   * Campos que, si aparecen en un registro de historial, se convierten en botones.
   *
   * Para agregar uno nuevo:
   * 'campo_en_bd' => ['label' => 'Texto del boton', 'base' => 'https://sitio/ruta/?numero='],
   *
   * El valor del campo se agrega al final de "base" con rawurlencode().
   *
   * @return array<string,array{label:string,base:string}>
   */
  public static function idButtons(): array
  {
    return [
      // Tickets
      'id_ticket_cliente' => ['label' => 'Ver ticket cliente', 'base' => 'https://sucasainmobiliaria.com.co/ticket/?id_ticket='],
      'id_ticket_danos_entrega' => ['label' => 'Ver ticket de danos encontrados', 'base' => 'https://sucasainmobiliaria.com.co/ticket/?id_ticket='],
      'id_ticket_danos_recibo' => ['label' => 'Ver acta de revision de desocupacion', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-revision-de-desocupacion/?numero='],

      // Revisiones
      'id_revision_preventiva' => ['label' => 'Ver revision preventiva', 'base' => 'https://sucasainmobiliaria.com.co/revision-preventiva/?numero='],
      'id_revision_correctiva' => ['label' => 'Ver revision correctiva', 'base' => 'https://sucasainmobiliaria.com.co/revision-correctiva/?numero='],
      'id_revision_entrega' => ['label' => 'Ver revision de entrega', 'base' => 'https://sucasainmobiliaria.com.co/revision-de-entrega/?numero='],
      'id_revision_recibo' => ['label' => 'Ver revision de recibo', 'base' => 'https://sucasainmobiliaria.com.co/revision-de-recibo/?numero='],
      'id_revision_sp' => ['label' => 'Ver revision de servicios publicos', 'base' => 'https://sucasainmobiliaria.com.co/revision-de-servicios-publicos/?numero='],
      'id_revision_servicios_publicos' => ['label' => 'Ver revision de servicios publicos', 'base' => 'https://sucasainmobiliaria.com.co/revision-de-servicios-publicos/?numero='],

      // Inmueble / inventario
      'id_inmueble' => ['label' => 'Ver inmueble en web', 'base' => 'https://sucasainmobiliaria.com.co/inmuebles/inmueble/'],
      'id_inventario' => ['label' => 'Ver inventario inmueble', 'base' => 'https://sucasainmobiliaria.com.co/inventario-inmueble/?numero='],

      // Cotizaciones
      'id_cotizacion_mantenimiento' => ['label' => 'Ver cotizacion de mantenimiento', 'base' => 'https://sucasainmobiliaria.com.co/cotizacion-de-mantenimiento/?numero='],
      'id_cotizacion_comercial' => ['label' => 'Ver cotizacion de inmuebles', 'base' => 'https://sucasainmobiliaria.com.co/cotizacion-de-inmuebles/?numero='],

      // Actas
      'id_acta_satisfaccion' => ['label' => 'Ver acta de satisfaccion', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-satisfaccion/?numero='],
      'id_acta_entrega' => ['label' => 'Ver acta de entrega', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-entrega/?numero='],
      'id_acta_recibo' => ['label' => 'Ver acta de recibo', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-recibo/?numero='],
      'id_acta_revision' => ['label' => 'Ver acta de revision de desocupacion', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-revision-de-desocupacion/?numero='],
      'id_acta_revision_notificacion' => ['label' => 'Ver acta de notificacion de ingreso', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-notificacion-de-ingreso/?numero='],
      'id_acta_revision_desocupacion' => ['label' => 'Ver acta de revision de desocupacion', 'base' => 'https://sucasainmobiliaria.com.co/acta-de-revision-de-desocupacion/?numero='],

      // Contratos / ordenes / cierres
      'id_contrato' => ['label' => 'Ver contrato de mandato', 'base' => 'https://sucasainmobiliaria.com.co/contrato-de-mandato/?numero='],
      'contrato' => ['label' => 'Ver contrato de mandato', 'base' => 'https://sucasainmobiliaria.com.co/contrato-de-mandato/?numero='],
      'id_contrato_mandato' => ['label' => 'Ver contrato de mandato', 'base' => 'https://sucasainmobiliaria.com.co/contrato-de-mandato/?numero='],
      'id_orden' => ['label' => 'Ver orden', 'base' => 'https://sucasainmobiliaria.com.co/orden/?numero='],
      'id_cierre' => ['label' => 'Ver cierre', 'base' => 'https://sucasainmobiliaria.com.co/hoja-de-cierre/?numero='],

      // Aseguradora
      'id_estudio_aseguradora' => ['label' => 'Ver estudio aseguradora', 'base' => 'https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio='],
    ];
  }
}
