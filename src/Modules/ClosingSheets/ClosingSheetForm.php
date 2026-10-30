<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Campos de la hoja de cierre de negocio, tal como los tenía el formulario JetForm "Añadir hoja de cierre de negocio".
 *
 * - `name` es la columna de jet_cct_cierres (el formulario original usaba otros nombres en los horarios, el
 *   parqueadero, el referido PPH, la cuenta de la copropiedad y el incremento).
 * - `options`: clave de ClosingSheetService::options() (glosario o tabla) o lista fija (valor => etiqueta si difieren).
 * - `when`: se muestra (y se valida) solo si cada [campo, valores] se cumple; en un checkbox basta un valor marcado.
 * - `readonly`: viene del contrato de mandato y no se cambia aquí.
 */
final class ClosingSheetForm
{
  /** @var list<array{0:string,1:list<array<string,mixed>>}> título => campos, en el orden del formulario */
  public const SECTIONS = [
    ['Datos del negocio', [
      ['name' => 'id_ticket', 'type' => 'number', 'label' => '#Ticket', 'required' => true, 'help' => 'Por favor ingresa el numero de ticket por el cual atendio al cliente'],
      ['name' => 'tipo_inmueble', 'type' => 'select', 'label' => 'Tipo de inmueble', 'required' => true, 'options' => 'tipo_inmueble', 'help' => 'Selecciona el tipo de inmueble a arrendar.'],
      ['name' => 'gestion_inmueble', 'type' => 'select', 'label' => 'Gestion', 'required' => true, 'options' => 'gestion_inmueble', 'help' => 'Selecciona el tipo de negocio a arrendar.'],
      ['name' => 'destinacion_inmueble', 'type' => 'select', 'label' => 'Destinacion', 'required' => true, 'options' => 'destinacion_inmueble', 'help' => 'Selecciona el destino a arrendar.'],
      ['name' => 'incluye_iva', 'type' => 'select', 'label' => '¿El canon incluye iva?', 'options' => 'si_no', 'readonly' => true, 'help' => 'El canon lleva iva o no.'],
      ['name' => 'meses_contrato', 'type' => 'number', 'label' => 'Meses de contrato', 'readonly' => true, 'help' => 'Esta es la cantidad de meses que durara el contrato.'],
      ['name' => 'valor_canon', 'type' => 'text', 'label' => 'Canon', 'readonly' => true, 'help' => 'Este es el valor del canon mensual.'],
      ['name' => 'valor_administracion', 'type' => 'text', 'label' => 'Administracion', 'readonly' => true, 'help' => 'Este es el valor de la administracion mensual.'],
      ['name' => 'iva_total_precio', 'type' => 'text', 'label' => 'Iva del canon', 'readonly' => true, 'help' => 'Este es el valor del iva del canon.'],
      ['name' => 'tasa_administracion', 'type' => 'number', 'label' => 'Porcentaje de comision', 'readonly' => true, 'help' => 'Este es el porcentaje que se le cobrara como comision al propietario.'],
      ['name' => 'porcentaje_incremento', 'type' => 'text', 'label' => 'Incremento pactado', 'required' => true, 'readonly' => true, 'help' => 'Este es el porcentaje que se determino con el propietario para el aumento del valor del canon. NOTA: solamente se aceptan numero y para decimales . EJEMPLO: 5 4.10 etc.'],
      ['name' => 'derechos_inmobiliarios', 'type' => 'number', 'label' => 'Derechos inmobiliarios', 'required' => true, 'help' => 'Coloca los derechos pactados con el arrendatario.'],
      ['name' => 'dias_gracia', 'type' => 'text', 'label' => 'Dias de gracia', 'help' => 'Esta es la cantidad de dias de gracia que el propietario otorgo a este contrato.'],
      ['name' => 'direccion', 'type' => 'text', 'label' => 'Direccion fisica del inmueble', 'required' => true, 'help' => 'Está es la dirección del inmueble. NOTA: recuerda que es la fisica.'],
    ]],
    ['Servicios públicos', [
      ['name' => 'servicios_publicos', 'type' => 'checkbox', 'label' => 'Servicios publicos', 'options' => 'servicios_publicos', 'help' => 'Marque los servicios disponibles en este inmueble.'],
      ['name' => 'luz', 'type' => 'text', 'label' => 'Luz', 'when' => [['servicios_publicos', ['Energia']]], 'help' => 'Ingrese el número de NIC..'],
      ['name' => 'agua', 'type' => 'text', 'label' => 'Agua', 'when' => [['servicios_publicos', ['Agua']]], 'help' => 'Ingrese el número de poliza.'],
      ['name' => 'gas', 'type' => 'text', 'label' => 'Gas', 'when' => [['servicios_publicos', ['Gas']]], 'help' => 'Ingrese el número de contrato.'],
    ]],
    ['Propiedad horizontal', [
      ['name' => 'propiedad_horizontal', 'type' => 'select', 'label' => '¿Pertenece a regimen de propiedad horizontal y/o tiene administracion?', 'required' => true, 'options' => 'si_no', 'help' => 'Marca si, si pertenece a un regimen de propiedad horizontal y/o tiene administracion de lo contrario marca no.'],
    ]],
    ['Datos de la copropiedad', [
      ['name' => 'copropiedad', 'type' => 'text', 'label' => 'Copropiedad', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'nit_copropiedad', 'type' => 'text', 'label' => 'Nit', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'administrador', 'type' => 'text', 'label' => 'Administrador', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'correo_copropiedad', 'type' => 'text', 'label' => 'Correo copropiedad', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'celular_copropiedad', 'type' => 'text', 'label' => 'Celular copropiedad', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'tipo_cuenta_copropiedad', 'type' => 'select', 'label' => 'Tipo de cuenta', 'options' => ['Ahorros', 'Corriente'], 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Elige el tipo de cuenta copropiedad.'],
      ['name' => 'cuenta_copropiedad', 'type' => 'text', 'label' => 'Numero de cuenta', 'when' => [['propiedad_horizontal', ['Si']]]],
      ['name' => 'banco_copropiedad', 'type' => 'select', 'label' => 'Banco', 'options' => 'bancos', 'when' => [['propiedad_horizontal', ['Si']]]],
    ]],
    ['Amenidades de la copropiedad', [
      ['name' => 'zonas_sociales', 'type' => 'checkbox', 'label' => 'Zonas sociales', 'options' => 'zonas_sociales', 'when' => [['propiedad_horizontal', ['Si']]]],
    ]],
    ['Mudanza', [
      ['name' => 'horario_mudanza_inicio_1', 'type' => 'time', 'label' => 'Inicio franja 1', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de apertura franja 1.'],
      ['name' => 'horario_mudanza_final_1', 'type' => 'time', 'label' => 'Final franja 1', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de cierre franja 1.'],
      ['name' => 'horario_mudanza_inicio_2', 'type' => 'time', 'label' => 'Inicio franja 2', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de apertura franja 2.'],
      ['name' => 'horario_mudanza_final_2', 'type' => 'time', 'label' => 'Final franja 2', 'required' => true, 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de cierre franja 2.'],
      ['name' => 'horario_mudanza_inicio_sabado', 'type' => 'time', 'label' => 'Inicio sabado', 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de apertura dia sabado. Si la copropiedad no da apartura los dia sabado dejar el campo vacio.'],
      ['name' => 'horario_mudanza_final_sabado', 'type' => 'time', 'label' => 'Final sabado', 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Digita la hora de cierre dia sabado. Si la copropiedad no da apartura los dia sabado dejar el campo vacio.'],
    ]],
    ['Zonas sociales', [
      ['name' => 'horario_zonas_inicio', 'type' => 'time', 'label' => 'Inicio', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Bahia de acceso', 'Baños', 'Barra', 'BBQ', 'Biblioteca', 'Bodega', 'Chorros de agua', 'Cuarto de administracion', 'Duchas', 'Guarderia', 'Juegos de mesa', 'Lavanderia', 'Lobby', 'Mini lockers', 'Multi escenario', 'Plazoleta BBQ', 'Recepcion', 'Sala de ejecutivos', 'Sala de estudio', 'Salon social', 'Sky bar', 'Solarium', 'Spa', 'Zona zen', 'Zonas de espera']]], 'help' => 'Digita la hora de apertura.'],
      ['name' => 'horario_zonas_final', 'type' => 'time', 'label' => 'Final', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Bahia de acceso', 'Baños', 'Barra', 'BBQ', 'Biblioteca', 'Bodega', 'Chorros de agua', 'Cuarto de administracion', 'Duchas', 'Guarderia', 'Juegos de mesa', 'Lavanderia', 'Lobby', 'Mini lockers', 'Multi escenario', 'Plazoleta BBQ', 'Recepcion', 'Sala de ejecutivos', 'Sala de estudio', 'Salon social', 'Sky bar', 'Solarium', 'Spa', 'Zona zen', 'Zonas de espera']]], 'help' => 'Digita la hora de cierre.'],
    ]],
    ['Gimnasio', [
      ['name' => 'horario_gym_inicio', 'type' => 'time', 'label' => 'Inicio', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Gimnasio']]], 'help' => 'Digita la hora de apertura.'],
      ['name' => 'horario_gym_final', 'type' => 'time', 'label' => 'Final', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Gimnasio']]], 'help' => 'Digita la hora de cierre.'],
    ]],
    ['Piscina', [
      ['name' => 'horario_piscina_inicio', 'type' => 'time', 'label' => 'Inicio', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Infinity pool', 'Piscina', 'Piscina infantil', 'Sauna', 'Turco']]], 'help' => 'Digita la hora de apertura.'],
      ['name' => 'horario_piscina_final', 'type' => 'time', 'label' => 'Final', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Infinity pool', 'Piscina', 'Piscina infantil', 'Sauna', 'Turco']]], 'help' => 'Digita la hora de cierre.'],
    ]],
    ['Canchas', [
      ['name' => 'horario_canchas_inicio', 'type' => 'time', 'label' => 'Inicio', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Golf', 'Canchas', 'Polideportivo']]], 'help' => 'Digita la hora de apertura.'],
      ['name' => 'horario_canchas_final', 'type' => 'time', 'label' => 'Final', 'when' => [['propiedad_horizontal', ['Si']], ['zonas_sociales', ['Golf', 'Canchas', 'Polideportivo']]], 'help' => 'Digita la hora de cierre.'],
    ]],
    ['Datos adicionales del inmueble', [
      ['name' => 'canon_supera_valor', 'type' => 'select', 'label' => '¿El valor del canon del arrendamiento supera 2 veces el valor catastral?', 'required' => true, 'options' => 'si_no', 'when' => [['destinacion_inmueble', ['Vivienda']]], 'help' => 'Aplica solo para contratos de vivienda.'],
      ['name' => 'tiene_parqueadero', 'type' => 'select', 'label' => '¿Tiene parqueadero?', 'required' => true, 'options' => 'si_no', 'help' => 'Responde si tiene parqueadero o no'],
      ['name' => 'tipo_parqueadero', 'type' => 'select', 'label' => 'Tipo de parqueadero', 'required' => true, 'options' => 'tipo_parqueadero', 'when' => [['tiene_parqueadero', ['Si']]], 'help' => 'Seleccione el tipo de parqueadero que es.'],
      ['name' => 'parqueaderos', 'type' => 'text', 'label' => 'Parqueadero', 'required' => true, 'when' => [['tiene_parqueadero', ['Si']]], 'help' => 'Coloque la cantidad de parqueaderos que contiene este inmueble.'],
      ['name' => 'numero_parqueadero', 'type' => 'text', 'label' => '# Parqueadero', 'required' => true, 'when' => [['tiene_parqueadero', ['Si']]], 'help' => 'Coloque el numero de parqueadero si tiene mas de 1 separelos con coma.'],
      ['name' => 'tiene_deposito', 'type' => 'select', 'label' => '¿Tiene deposito?', 'required' => true, 'options' => 'si_no', 'help' => 'Responde si tiene deposito o no'],
      ['name' => 'depositos', 'type' => 'text', 'label' => 'Depositos', 'required' => true, 'when' => [['tiene_deposito', ['Si']]], 'help' => 'Coloque la cantidad de depositos que contiene este inmueble.'],
    ]],
    ['Propietario', [
      ['name' => 'tipo_persona_propietario', 'type' => 'select', 'label' => 'Tipo de persona del propietario', 'required' => true, 'options' => ['Natural' => 'Natural', 'Juridica' => 'Jurídica']],
      ['name' => 'tipo_documento_propietario', 'type' => 'select', 'label' => 'Tipo de documento del propietario', 'required' => true, 'options' => ['Cédula de Ciudadania' => 'Cédula de Ciudadanía', 'Nit' => 'NIT', 'Cédula Extranjería' => 'Cédula Extranjería', 'Pasaporte' => 'Pasaporte']],
      ['name' => 'propietario', 'type' => 'text', 'label' => 'Propietario (nombre o empresa)', 'required' => true, 'help' => 'Diligencie o confirme el nombre del propietario; si es persona jurídica, el nombre de la empresa.'],
      ['name' => 'documento_propietario', 'type' => 'text', 'label' => 'Documento o NIT del propietario', 'required' => true, 'help' => 'Diligencie o confirme el documento del propietario.'],
      ['name' => 'correo_propietario', 'type' => 'text', 'label' => 'Correo del propietario', 'required' => true, 'help' => 'Diligencie o confirme el correo del propietario.'],
      ['name' => 'celular_propietario', 'type' => 'text', 'label' => 'Celular del propietario', 'required' => true, 'help' => 'Diligencie o confirme el celular del propietario.'],
      ['name' => 'representante_propietario', 'type' => 'text', 'label' => 'Representante legal del propietario', 'required' => true, 'when' => [['tipo_persona_propietario', ['Juridica']]], 'help' => 'Nombre de quien representa a la empresa.'],
      ['name' => 'documento_representante_propietario', 'type' => 'text', 'label' => 'Documento del representante legal', 'required' => true, 'when' => [['tipo_persona_propietario', ['Juridica']]]],
    ]],
    ['Arrendatario', [
      ['name' => 'tipo_persona_arrendatario', 'type' => 'select', 'label' => 'Tipo de persona del arrendatario', 'required' => true, 'options' => ['Natural' => 'Natural', 'Juridica' => 'Jurídica']],
      ['name' => 'tipo_documento_arrendatario', 'type' => 'select', 'label' => 'Tipo de documento del arrendatario', 'required' => true, 'options' => ['Cédula de Ciudadania' => 'Cédula de Ciudadanía', 'Nit' => 'NIT', 'Cédula Extranjería' => 'Cédula Extranjería', 'Pasaporte' => 'Pasaporte']],
      ['name' => 'arrendatario', 'type' => 'text', 'label' => 'Arrendatario (nombre o empresa)', 'required' => true, 'help' => 'Diligencie o confirme el nombre del arrendatario; si es persona jurídica, el nombre de la empresa.'],
      ['name' => 'documento_arrendatario', 'type' => 'text', 'label' => 'Documento o NIT del arrendatario', 'required' => true, 'help' => 'Diligencie o confirme el documento del arrendatario'],
      ['name' => 'correo_arrendatario', 'type' => 'text', 'label' => 'Correo del arrendatario', 'required' => true, 'help' => 'Diligencie o confirme el correo del arrendatario.'],
      ['name' => 'celular_arrendatario', 'type' => 'text', 'label' => 'Celular del arrendatario', 'required' => true, 'help' => 'Diligencie o confirme el celular del arrendatario.'],
      ['name' => 'representante_arrendatario', 'type' => 'text', 'label' => 'Representante legal del arrendatario', 'required' => true, 'when' => [['tipo_persona_arrendatario', ['Juridica']]], 'help' => 'Nombre de quien representa a la empresa.'],
      ['name' => 'documento_representante_arrendatario', 'type' => 'text', 'label' => 'Documento del representante legal', 'required' => true, 'when' => [['tipo_persona_arrendatario', ['Juridica']]]],
    ]],
    ['Datos adicionales del arrendatario', [
      ['name' => 'tiene_mascotas', 'type' => 'select', 'label' => '¿Tiene mascotas?', 'required' => true, 'options' => 'si_no', 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Responder si o no el inquilino tiene mascotas.'],
      ['name' => 'mascotas', 'type' => 'repeater', 'label' => 'Mascotas', 'when' => [['propiedad_horizontal', ['Si']], ['tiene_mascotas', ['Si']]], 'help' => 'Describa cada mascota que tenga el inquilino'],
      ['name' => 'tiene_vehiculo', 'type' => 'select', 'label' => '¿Tiene vehiculo?', 'required' => true, 'options' => 'si_no', 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Responder si o no si el inquilino tiene vehiculo.'],
      ['name' => 'vehiculo', 'type' => 'repeater', 'label' => 'Vehiculos', 'when' => [['propiedad_horizontal', ['Si']], ['tiene_vehiculo', ['Si']]], 'help' => 'Describa cada vehiculo que tenga el inquilino'],
      ['name' => 'convive_personas', 'type' => 'select', 'label' => '¿Convive con personas?', 'required' => true, 'options' => 'si_no', 'when' => [['propiedad_horizontal', ['Si']]], 'help' => 'Responder si o no el inquilino convive con personas.'],
      ['name' => 'personas', 'type' => 'repeater', 'label' => 'Personas', 'when' => [['propiedad_horizontal', ['Si']], ['convive_personas', ['Si']]], 'help' => 'Describe o rellena la informacion de las personas que conviven con el inquilino.'],
    ]],
    ['Codeudores', [
      ['name' => 'num_codeudores', 'type' => 'select', 'label' => 'Número de codeudores', 'required' => true, 'options' => ['Ninguno', '1', '2', '3', '4'], 'help' => 'Selecciona la cantidad de número de codeudores que tiene este contrato.'],
      ['name' => 'codeudor_1', 'type' => 'text', 'label' => 'Codeudor 1', 'required' => true, 'when' => [['num_codeudores', ['1', '2', '3', '4']]], 'help' => 'Diligencie o confirme el nombre del codeudor.'],
      ['name' => 'documento_codeudor_1', 'type' => 'text', 'label' => 'Documento del codeudor 1', 'required' => true, 'when' => [['num_codeudores', ['1', '2', '3', '4']]], 'help' => 'Diligencie o confirme el documento del codeudor 1'],
      ['name' => 'correo_codeudor_1', 'type' => 'text', 'label' => 'Correo del codeudor 1', 'required' => true, 'when' => [['num_codeudores', ['1', '2', '3', '4']]], 'help' => 'Diligencie o confirme el correo del codeudor.'],
      ['name' => 'celular_codeudor_1', 'type' => 'text', 'label' => 'Celular del codeudor 1', 'required' => true, 'when' => [['num_codeudores', ['1', '2', '3', '4']]], 'help' => 'Diligencie o confirme el celular del codeudor.'],
      ['name' => 'dir_codeudor_1', 'type' => 'text', 'label' => 'Direccion del codeudor 1', 'required' => true, 'when' => [['num_codeudores', ['1', '2', '3', '4']]], 'help' => 'Diligencie o confirme la direccion del codeudor.'],
      ['name' => 'codeudor_2', 'type' => 'text', 'label' => 'Codeudor 2', 'required' => true, 'when' => [['num_codeudores', ['2', '3', '4']]], 'help' => 'Diligencie o confirme el nombre del codeudor.'],
      ['name' => 'documento_codeudor_2', 'type' => 'text', 'label' => 'Documento del codeudor 2', 'required' => true, 'when' => [['num_codeudores', ['2', '3', '4']]], 'help' => 'Diligencie o confirme el documento del codeudor 2'],
      ['name' => 'correo_codeudor_2', 'type' => 'text', 'label' => 'Correo del codeudor 2', 'required' => true, 'when' => [['num_codeudores', ['2', '3', '4']]], 'help' => 'Diligencie o confirme el correo del codeudor.'],
      ['name' => 'celular_codeudor_2', 'type' => 'text', 'label' => 'Celular del codeudor 2', 'required' => true, 'when' => [['num_codeudores', ['2', '3', '4']]], 'help' => 'Diligencie o confirme el celular del codeudor.'],
      ['name' => 'dir_codeudor_2', 'type' => 'text', 'label' => 'Direccion del codeudor 2', 'required' => true, 'when' => [['num_codeudores', ['2', '3', '4']]], 'help' => 'Diligencie o confirme la direccion del codeudor.'],
      ['name' => 'codeudor_3', 'type' => 'text', 'label' => 'Codeudor 3', 'required' => true, 'when' => [['num_codeudores', ['3', '4']]], 'help' => 'Diligencie o confirme el nombre del codeudor.'],
      ['name' => 'documento_codeudor_3', 'type' => 'text', 'label' => 'Documento del codeudor 3', 'required' => true, 'when' => [['num_codeudores', ['3', '4']]], 'help' => 'Diligencie o confirme el documento del codeudor 3'],
      ['name' => 'correo_codeudor_3', 'type' => 'text', 'label' => 'Correo del codeudor 3', 'required' => true, 'when' => [['num_codeudores', ['3', '4']]], 'help' => 'Diligencie o confirme el correo del codeudor.'],
      ['name' => 'celular_codeudor_3', 'type' => 'text', 'label' => 'Celular del codeudor 3', 'required' => true, 'when' => [['num_codeudores', ['3', '4']]], 'help' => 'Diligencie o confirme el celular del codeudor.'],
      ['name' => 'dir_codeudor_3', 'type' => 'text', 'label' => 'Direccion del codeudor 3', 'required' => true, 'when' => [['num_codeudores', ['3', '4']]], 'help' => 'Diligencie o confirme la direccion del codeudor.'],
      ['name' => 'codeudor_4', 'type' => 'text', 'label' => 'Codeudor 4', 'required' => true, 'when' => [['num_codeudores', ['4']]], 'help' => 'Diligencie o confirme el nombre del codeudor.'],
      ['name' => 'documento_codeudor_4', 'type' => 'text', 'label' => 'Documento del codeudor 4', 'required' => true, 'when' => [['num_codeudores', ['4']]], 'help' => 'Diligencie o confirme el documento del codeudor 4'],
      ['name' => 'correo_codeudor_4', 'type' => 'text', 'label' => 'Correo del codeudor 4', 'required' => true, 'when' => [['num_codeudores', ['4']]], 'help' => 'Diligencie o confirme el correo del codeudor.'],
      ['name' => 'celular_codeudor_4', 'type' => 'text', 'label' => 'Celular del codeudor 4', 'required' => true, 'when' => [['num_codeudores', ['4']]], 'help' => 'Diligencie o confirme el celular del codeudor.'],
      ['name' => 'dir_codeudor_4', 'type' => 'text', 'label' => 'Direccion del codeudor 4', 'required' => true, 'when' => [['num_codeudores', ['4']]], 'help' => 'Diligencie o confirme la direccion del codeudor.'],
    ]],
    ['Acuerdos precontractuales y referido PPH', [
      ['name' => 'acuerdos_precontractuales', 'type' => 'checkbox', 'label' => 'Acuerdos precontractuales', 'options' => 'acuerdos', 'help' => 'Marca con una x si se hizo un preacuerdo con dicha persona.'],
      ['name' => 'acuerdos_precontractuales_pro', 'type' => 'textarea', 'label' => 'Acuerdos con propietario', 'when' => [['acuerdos_precontractuales', ['Propietario']]], 'help' => 'Escriba todos los acuerdos que se conversó con el propietario.'],
      ['name' => 'acuerdos_precontractuales_inm', 'type' => 'textarea', 'label' => 'Acuerdos con inmobiliaria', 'when' => [['acuerdos_precontractuales', ['Inmobiliaria']]], 'help' => 'Escriba todos los acuerdos que se conversó con la inmobiliaria.'],
      ['name' => 'acuerdos_precontractuales_arre', 'type' => 'textarea', 'label' => 'Acuerdos con arrendatario', 'when' => [['acuerdos_precontractuales', ['Arrendatario']]], 'help' => 'Escriba todos los acuerdos que se conversó con el arrendatario.'],
      ['name' => 'acuerdos_precontractuales_ext', 'type' => 'textarea', 'label' => 'Acuerdos con externo', 'when' => [['acuerdos_precontractuales', ['Externo']]], 'help' => 'Escriba todos los acuerdos que se conversó con el externo.'],
      ['name' => 'acuerdos_precontractuales_co', 'type' => 'textarea', 'label' => 'Acuerdos con copropiedad', 'when' => [['acuerdos_precontractuales', ['Copropiedad']]], 'help' => 'Escriba todos los acuerdos que se conversó con la copropiedad.'],
      ['name' => 'referido_pph', 'type' => 'select', 'label' => '¿El cliente fue referido por aliado PPH?', 'required' => true, 'options' => 'si_no', 'help' => 'Responde si el cliente fue referido por aliado pph.'],
      ['name' => 'id_pph', 'type' => 'select', 'label' => 'Aliado PPH', 'options' => 'club_pph', 'when' => [['el_cliente_fue_referido_por_aliado_pph', ['Si']]], 'help' => 'Selecciona al aliado que le sugerio o informó de este cliente.'],
    ]],
  ];

  /** Sub-campos de los repetidores (se guardan como arreglo serializado). */
  public const REPEATERS = [
    'mascotas' => [
      ['name' => 'tipo', 'type' => 'select', 'label' => 'Tipo', 'options' => 'tipo_mascota'],
      ['name' => 'raza', 'type' => 'text', 'label' => 'Raza'],
    ],
    'vehiculo' => [
      ['name' => 'tipo', 'type' => 'select', 'label' => 'Tipo', 'options' => 'tipo_vehiculo'],
      ['name' => 'placa', 'type' => 'text', 'label' => 'Placa'],
    ],
    'personas' => [
      ['name' => 'nombre', 'type' => 'text', 'label' => 'Nombre'],
      ['name' => 'edad', 'type' => 'number', 'label' => 'Edad'],
      ['name' => 'identificacion', 'type' => 'text', 'label' => 'Identificacion'],
      ['name' => 'parentesco', 'type' => 'select', 'label' => 'Parentesco', 'options' => 'parentesco'],
      ['name' => 'correo', 'type' => 'text', 'label' => 'Correo'],
      ['name' => 'celular', 'type' => 'text', 'label' => 'Celular'],
      ['name' => 'ocupacion', 'type' => 'select', 'label' => 'Ocupacion', 'options' => 'ocupacion'],
    ],
  ];

  /**
   * Lista de chequeo (paso 2): columna de observaciones => [grupo, visible si, ítems [columna del estado, texto, etiqueta en
   * las observaciones]]. Las observaciones de cada ítem se juntan en esa columna con el mismo HTML que dejaba el formulario.
   */
  public const CHECKLIST = [
    'ob_documentos_juridicos' => ['Documentos jurídicos', null, [
      ['estado_certificado_tradicion', 'Certificado de Tradición y Libertad no mayor a treinta (30) días', 'Certificado de Tradición y Libertad no mayor a treinta (30) días:'],
      ['estado_copia_escritura', 'Copia de escritura pública', 'Copia de escritura pública:'],
      ['estado_factura_predial', 'Factura del predial', 'Factura del predial:'],
    ]],
    'ob_servicios_publicos' => ['Documentos de servicios públicos y conexos', null, [
      ['estado_servicio_agua', 'Ultima Factura Servicio de Acueducto y Alcantarillado ( Anexar factura fisica - Estado de Pago ( Al dia- En Mora) especificar', 'Ultima Factura Servicio de Acueducto y Alcantarillado:'],
      ['estado_servicio_energia', 'Ultima Factura Servicio de Energía ( Anexar factura fisica - Estado de Pago ( Al dia- En Mora) especificar)', 'Ultima Factura Servicio de Energía:'],
      ['estado_servicio_gas', 'Ultima Factura Servicio de Gas ( Anexar factura fisica - Estado de Pago ( Al dia- En Mora) especificar)', 'Ultima Factura Servicio de Gas:'],
      ['estado_servicio_internet', 'Ultima Factura Internet ( Anexar factura fisica - Estado de Pago ( Al dia- En Mora) especificar)', 'Ultima Factura Internet:'],
      ['estado_servicio_telefonia', 'Ultima Factura Teléfono Fijo ( Anexar factura fisica - Estado de Pago ( Al dia- En Mora) especificar)', 'Ultima Factura Teléfono Fijo:'],
    ]],
    'ob_cumplimiento_servicio' => ['Cumplimiento de políticas referente a los Servicios Publicos', null, [
      ['estado_amparo_integral', '¿Cuenta con el Amparo integral de Servicios Publicos?', '¿Cuenta con el Amparo integral de Servicios Publicos?'],
      ['estado_servicios_compatidos', 'SI los servicios Publicos son compartidos , se va a instalar medidor interno? Exite autoriaxion para proceder a la instalcion? Especificar cual(es) son los servicios con estas condiciones', '¿Servicios Publicos son compartidos, se va a instalar medidor interno?'],
      ['estado_mora_servicios', 'En caso de Presentarse Mora en los Servicios , Anexar documento donde autorice a que el pago lo Realice la Inmobilirira para descontar de los arriendos', 'En caso de Presentarse Mora en los Servicios:'],
    ]],
    'ob_cumplimiento_estado_inmueble' => ['Cumplimiento de políticas referente al Estado de Conservacion del inmueble', null, [
      ['estado_requiere_reparacion', 'Requiere reparaciones ? Por cuenta del Propietario o por cuetna de la Inmobiliria o por el Arrendatario( Especificar)', '¿Requiere reparaciones?'],
      ['estado_dias_gracia', 'Existen dias de Gracia para compesar reparaciones ( Especificar)', '¿Existen dias de Gracia?'],
    ]],
    'ob_documentos_propietario' => ['Documentos del Propietario', null, [
      ['estado_acreditacion_propietario', 'Documento que acredite la facultad para arrendar cuando no es el propietario', 'Documento que acredite la facultad para arrendar cuando no es el propietario'],
      ['estado_certificado_empresa_pro', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_pro', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_pro', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_pro', 'Rut', 'Rut'],
      ['estado_bancaria_pro', 'Certificado Bancaria', 'Certificado Bancaria'],
    ]],
    'ob_documentos_arrendatario' => ['Documentos del Arrendatario', null, [
      ['estado_certificado_empresa_arre', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_arre', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_arre', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_arre', 'Rut', 'Rut'],
    ]],
    'ob_documentos_co1' => ['Documentos del codeudor 1', [['num_codeudores', ['1', '2', '3', '4']]], [
      ['estado_certificado_empresa_co1', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_co1', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_co1', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_co1', 'Rut', 'Rut'],
    ]],
    'ob_documentos_co2' => ['Documentos del codeudor 2', [['num_codeudores', ['2', '3', '4']]], [
      ['estado_certificado_empresa_co2', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_co2', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_co2', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_co2', 'Rut', 'Rut'],
    ]],
    'ob_documentos_co3' => ['Documentos del codeudor 3', [['num_codeudores', ['3', '4']]], [
      ['estado_certificado_empresa_co3', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_co3', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_co3', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_co3', 'Rut', 'Rut'],
    ]],
    'ob_documentos_co4' => ['Documentos del codeudor 4', [['num_codeudores', ['4']]], [
      ['estado_certificado_empresa_co4', 'Certificado de Existencia y Representación Legal ( Cuando sea empresa)', 'Certificado de Existencia y Representación Legal'],
      ['estado_certificado_camara_co4', 'Certificado de Cámara de Comercio', 'Certificado de Cámara de Comercio'],
      ['estado_documento_co4', 'Fotocopia del Documento de Identidad', 'Fotocopia del Documento de Identidad'],
      ['estado_rut_co4', 'Rut', 'Rut'],
    ]],
    'ob_documentos_copropiedad' => ['Documentos de la Copropiedad', null, [
      ['estado_nombre_administrador', 'Nombre del Administrador', 'Nombre del Administrador'],
      ['estado_correo_copropiedad', 'Correo Electrónico', 'Correo Electrónico'],
      ['estado_celular_copropiedad', 'Números de teléfonos y/o Celulares', 'Números de teléfonos y/o Celulares'],
      ['estado_reglamento_copropiedad', 'Fotocopia del Reglamento de Propiedad Horizontal', 'Fotocopia del Reglamento de Propiedad Horizontal'],
      ['estado_manual_copropiedad', 'Manual de Convivencia', 'Manual de Convivencia'],
      ['estado_rut_copropiedad', 'Rut', 'Rut'],
      ['estado_certificacion_copropiedad', 'Certificación Bancaria', 'Certificación Bancaria'],
      ['estado_cuota_copropiedad', 'Certificación que estipule el Monto de la Cuota de Administración', 'Certificación que estipule el Monto de la Cuota de Administración'],
      ['estado_paz_salvo_copropiedad', 'Certificación de Paz y Salvo Expedido por la Copropiedad', 'Certificación de Paz y Salvo Expedido por la Copropiedad'],
      ['estado_encargado_copropiedad', '¿La Inmobiliria es la Encargada de hacer los Pagos a la Administracion?', '¿La Inmobiliaria es la Encargada de hacer los Pagos a la Administracion?'],
    ]],
    'ob_documentos_aseguradora_cierre' => ['Documentos de la Aseguradora y Hoja de Cierre', null, [
      ['estado_asegurable', 'Asegurable', 'Asegurable'],
      ['estado_cobertura', '¿El Asegurable tiene una Cobertura de 36 Meses?', '¿El Asegurable tiene una Cobertura de 36 Meses?'],
      ['estado_termino_contrato', '¿El contrato es por el termino de 12 meses?', '¿El contrato es por el termino de 12 meses?'],
    ]],
  ];

  /**
   * Separa los campos de cada codeudor ("…_codeudor_N") para mostrarlos en un bloque por persona, con etiquetas cortas.
   * @param list<array<string,mixed>> $fields
   * @return array{0:list<array<string,mixed>>,1:array<string,list<array<string,mixed>>>} sueltos y "Codeudor N" => campos
   */
  public static function personGroups(array $fields): array
  {
    $loose = [];
    $groups = [];
    foreach ($fields as $field) {
      if (preg_match('/(?:^|_)codeudor_(\d)$/', (string) $field['name'], $match) === 1) {
        $groups['Codeudor ' . $match[1]][] = array_merge($field, ['label' => self::shortLabel((string) $field['label']), 'help' => '']);
      } else {
        $loose[] = $field;
      }
    }
    return [$loose, $groups];
  }

  /** "Documento del codeudor 1" => "Documento"; "Codeudor 1" => "Nombre completo". */
  public static function shortLabel(string $label): string
  {
    $label = preg_replace('/^Codeudor \d$/u', 'Nombre completo', $label) ?? $label;
    $label = preg_replace('/\s+del codeudor(?: \d)?$/u', '', $label) ?? $label;
    return str_replace('Direccion', 'Dirección', $label);
  }
}
