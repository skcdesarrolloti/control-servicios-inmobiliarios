<?php

declare(strict_types=1);

namespace SCM\Modules\PreventiveReview;

/**
 * Checklist de la revisión preventiva, replicada del formulario JetForm
 * "Añadir revisión preventiva" (repeaters con preguntas del glosario 175).
 *
 * Cada repeater se guarda serializado en su columna homónima de
 * jet_cct_revision_preventiva como [0 => [pregunta_N_<clave> => valor, observaciones_<clave> => texto]].
 * "alert" indica la respuesta que representa un hallazgo, para resaltarla.
 */
final class PreventiveReviewChecklist
{
  /** @return array<int,array{key:string,title:string,description:string,step:string,blocks:array<int,array{key:string,label:string,questions:array<int,array{label:string,alert:string,type?:string}>,observations?:string}>}> */
  public static function groups(): array
  {
    return [
      [
        'key' => 'estructura',
        'step' => 'inmueble-estructura',
        'title' => 'Estructura y seguridad',
        'description' => 'Integridad de paredes, techos, suelos, escaleras y barandillas; humedad, moho o daños que requieran reparación.',
        'blocks' => [
          ['key' => 'paredes_techos_pinturas', 'label' => 'Paredes, techos y pinturas', 'questions' => [
            ['label' => '¿Hay grietas, humedad o moho visibles?', 'alert' => 'Si'],
            ['label' => '¿Hay señales de desgaste o daño estructural?', 'alert' => 'Si'],
            ['label' => '¿Hay áreas con pintura descascarada o dañada?', 'alert' => 'Si'],
            ['label' => '¿Hay manchas de humedad o moho?', 'alert' => 'Si'],
            ['label' => '¿Se requieren reparaciones menores como parches de yeso o pintura?', 'alert' => 'Si'],
          ]],
          ['key' => 'cubiertas', 'label' => 'Cubiertas, cielos rasos', 'questions' => [
            ['label' => '¿Hay presencia de humedad en los cielos rasos y paredes?', 'alert' => 'Si'],
            ['label' => '¿Los canales y bajantes (si aplica) se encuentran libres y despejados?', 'alert' => 'No'],
          ]],
          ['key' => 'suelos', 'label' => 'Suelos', 'questions' => [
            ['label' => '¿Están nivelados y sin grietas?', 'alert' => 'No'],
            ['label' => '¿Hay áreas desgastadas o levantadas?', 'alert' => 'Si'],
            ['label' => '¿Hay áreas levantadas, desgastadas o dañadas?', 'alert' => 'Si'],
            ['label' => '¿Las alfombras están limpias y sin manchas?', 'alert' => 'No'],
          ]],
          ['key' => 'escaleras', 'label' => 'Escaleras y barandillas', 'questions' => [
            ['label' => '¿Están firmemente aseguradas?', 'alert' => 'No'],
            ['label' => '¿Hay signos de desgaste o daños?', 'alert' => 'Si'],
          ]],
        ],
      ],
      [
        'key' => 'electricas',
        'step' => 'inmueble-instalaciones',
        'title' => 'Instalaciones eléctricas',
        'description' => 'Estado de enchufes, interruptores, tablero de distribución, luces y cableado.',
        'blocks' => [
          ['key' => 'enchufes', 'label' => 'Enchufes e interruptores', 'questions' => [
            ['label' => '¿Funcionan correctamente?', 'alert' => 'No'],
            ['label' => '¿Están firmemente sujetos a la pared?', 'alert' => 'No'],
          ]],
          ['key' => 'tablero', 'label' => 'Tablero de distribución', 'questions' => [
            ['label' => '¿Está en buen estado y bien etiquetado?', 'alert' => 'No'],
            ['label' => '¿Hay algún signo de sobrecalentamiento o daño?', 'alert' => 'Si'],
          ]],
          ['key' => 'iluminacion', 'label' => 'Iluminación', 'questions' => [
            ['label' => '¿Todas las luces funcionan correctamente?', 'alert' => 'No'],
            ['label' => '¿Hay bombillas fundidas o parpadeantes?', 'alert' => 'Si'],
          ]],
          ['key' => 'cableado', 'label' => 'Cableado', 'questions' => [
            ['label' => '¿Hay cables expuestos o desgastados?', 'alert' => 'Si'],
          ]],
        ],
      ],
      [
        'key' => 'fontaneria',
        'step' => 'inmueble-instalaciones',
        'title' => 'Instalaciones de fontanería',
        'description' => 'Fugas en grifos, tuberías y sanitarios; funcionamiento del drenaje y calentador.',
        'blocks' => [
          ['key' => 'tuberias', 'label' => 'Grifos y tuberías', 'questions' => [
            ['label' => '¿Hay fugas de agua?', 'alert' => 'Si'],
            ['label' => '¿Funcionan correctamente todos los grifos?', 'alert' => 'No'],
          ]],
          ['key' => 'sanitarios', 'label' => 'Sanitarios', 'questions' => [
            ['label' => '¿Los inodoros, lavabos y duchas están en buen estado?', 'alert' => 'No'],
            ['label' => '¿El agua drena correctamente?', 'alert' => 'No'],
          ]],
          ['key' => 'termotanque', 'label' => 'Calentador / termotanque', 'questions' => [
            ['label' => '¿Está funcionando adecuadamente?', 'alert' => 'No'],
            ['label' => '¿Hay signos de fuga o corrosión?', 'alert' => 'Si'],
          ]],
        ],
      ],
      [
        'key' => 'climatizacion',
        'step' => 'inmueble-espacios',
        'title' => 'Calefacción, refrigeración y ventilación',
        'description' => 'Estado y funcionamiento de aires acondicionados, calefactores y ventilación.',
        'blocks' => [
          ['key' => 'aire_acondicionado', 'label' => 'Calefactores y aires acondicionados', 'questions' => [
            ['label' => '¿Están limpios y funcionando correctamente?', 'alert' => 'No'],
            ['label' => '¿Hay señales de fugas o mal funcionamiento?', 'alert' => 'Si'],
          ]],
          ['key' => 'ventilacion', 'label' => 'Ventilación', 'questions' => [
            ['label' => '¿Las rejillas de ventilación están limpias y despejadas?', 'alert' => 'No'],
            ['label' => '¿Funciona correctamente el sistema de ventilación?', 'alert' => 'No'],
          ]],
        ],
      ],
      [
        'key' => 'puertas',
        'step' => 'inmueble-espacios',
        'title' => 'Puertas y ventanas',
        'description' => 'Apertura y cierre, cerraduras, bisagras, sellos y aislantes.',
        'blocks' => [
          ['key' => 'puertas', 'label' => 'Funcionamiento', 'questions' => [
            ['label' => '¿Abren y cierran sin problemas?', 'alert' => 'No'],
            ['label' => '¿Las cerraduras funcionan correctamente?', 'alert' => 'No'],
            ['label' => '¿Hay signos de desgaste excesivos?', 'alert' => 'Si'],
          ]],
          ['key' => 'sellos', 'label' => 'Sellos y aislantes', 'questions' => [
            ['label' => '¿Hay sellos desgastados o faltantes?', 'alert' => 'Si'],
            ['label' => '¿Hay corrientes de aire que pasen a través de las ventanas cerradas?', 'alert' => 'Si'],
          ]],
        ],
      ],
      [
        'key' => 'mobiliario',
        'step' => 'inmueble-espacios',
        'title' => 'Mobiliario y electrodomésticos (si aplican)',
        'description' => 'Muebles y electrodomésticos arrendados con el inmueble.',
        'blocks' => [
          ['key' => 'muebles', 'label' => 'Muebles', 'questions' => [
            ['label' => '¿Están en buen estado y sin daños?', 'alert' => 'No'],
            ['label' => '¿Hay signos de desgaste excesivo?', 'alert' => 'Si'],
          ]],
          ['key' => 'electrodomesticos', 'label' => 'Electrodomésticos', 'questions' => [
            ['label' => '¿Funcionan correctamente todos los electrodomésticos proporcionados?', 'alert' => 'No'],
            ['label' => '¿Hay señales de mal funcionamiento o necesidad de reparación?', 'alert' => 'Si'],
          ]],
        ],
      ],
      [
        'key' => 'zonas_comunes',
        'step' => 'inmueble-espacios',
        'title' => 'Zonas comunes (si aplican)',
        'description' => 'Limpieza, mantenimiento, accesibilidad y respeto de las normas del inmueble.',
        'blocks' => [
          ['key' => 'zonas_comunes', 'label' => 'Limpieza, mantenimiento y accesibilidad', 'questions' => [
            ['label' => '¿Las áreas comunes están limpias y bien mantenidas?', 'alert' => 'No'],
            ['label' => '¿Hay daños o desgaste en las áreas comunes?', 'alert' => 'Si'],
            ['label' => '¿Están las áreas comunes accesibles y seguras?', 'alert' => 'No'],
            ['label' => '¿Se están respetando las normas del inmueble (ruidos, mantenimiento, etc.)?', 'alert' => 'No'],
          ]],
        ],
      ],
      [
        'key' => 'contrato',
        'step' => 'contrato',
        'title' => 'Desde el punto de vista del contrato de arrendamiento',
        'description' => 'Cumplimiento de términos, ocupación, modificaciones y comunicaciones del arrendatario.',
        'blocks' => [
          ['key' => 'cumplimiento', 'label' => 'Cumplimiento de términos del contrato', 'questions' => [], 'observations' => 'Describa el uso principal que actualmente desarrolla en el inmueble'],
          ['key' => 'ocupacion', 'label' => 'Ocupación', 'questions' => [
            ['label' => '¿Quiénes están habitando el inmueble?', 'alert' => '', 'type' => 'text'],
          ]],
          ['key' => 'modificaciones', 'label' => 'Modificaciones o daños', 'questions' => [
            ['label' => '¿Se han realizado modificaciones no autorizadas al inmueble?', 'alert' => 'Si'],
            ['label' => '¿Existen daños significativos causados por el inquilino?', 'alert' => 'Si'],
          ]],
          ['key' => 'notificaciones', 'label' => 'Notificaciones y comunicaciones', 'questions' => [
            ['label' => '¿El inquilino ha notificado formalmente problemas o solicitudes de mantenimiento?', 'alert' => ''],
            ['label' => '¿Se han resuelto todas las solicitudes de manera oportuna?', 'alert' => 'No'],
          ]],
        ],
      ],
    ];
  }

  /** @return array<string,array{key:string,label:string,questions:array<int,array<string,string>>,observations?:string}> */
  public static function blocks(): array
  {
    $out = [];
    foreach (self::groups() as $group) {
      foreach ($group['blocks'] as $block) {
        $out[$block['key']] = $block;
      }
    }
    return $out;
  }

  public static function questionField(string $blockKey, int $index): string
  {
    return 'pregunta_' . ($index + 1) . '_' . $blockKey;
  }

  public static function observationField(string $blockKey): string
  {
    return 'observaciones_' . $blockKey;
  }

  /** @return array<string,string> */
  public static function answerOptions(): array
  {
    return ['Si' => 'Sí', 'No' => 'No'];
  }

  /**
   * Lee el valor guardado de un repeater (serializado/JSON) y devuelve la primera fila.
   *
   * @return array<string,string>
   */
  public static function storedRow(mixed $raw): array
  {
    $rows = [];
    if (is_array($raw)) {
      $rows = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
      $decoded = @unserialize($raw, ['allowed_classes' => false]);
      if (!is_array($decoded)) {
        $decoded = json_decode($raw, true);
      }
      $rows = is_array($decoded) ? $decoded : [];
    }
    $first = $rows ? reset($rows) : [];
    if (!is_array($first)) {
      return [];
    }
    $out = [];
    foreach ($first as $key => $value) {
      if (is_string($key) && is_scalar($value)) {
        $out[$key] = trim((string) $value);
      }
    }
    return $out;
  }

  /** Cuenta respuestas que representan un hallazgo según la checklist. */
  public static function findingsCount(array $review): int
  {
    $count = 0;
    foreach (self::blocks() as $key => $block) {
      $row = self::storedRow($review[$key] ?? '');
      foreach ($block['questions'] as $index => $question) {
        $alert = (string) ($question['alert'] ?? '');
        $value = $row[self::questionField($key, $index)] ?? '';
        if ($alert !== '' && strcasecmp($value, $alert) === 0) {
          $count++;
        }
      }
    }
    return $count;
  }
}
