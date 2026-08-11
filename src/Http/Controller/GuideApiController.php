<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Core\Auth;
use SCM\Core\Csrf;
use SCM\Core\Database;
use SCM\Http\Response\JsonResponse;

final class GuideApiController
{
  private Database $db;
  private Csrf $csrf;

  public function __construct(Database $db, Csrf $csrf)
  {
    $this->db = $db;
    $this->csrf = $csrf;
  }

  /** @param array<string,mixed> $input */
  public function correspondenceRead(array $input): never
  {
    $this->verify($input);
    $table = $this->db->table('jet_cct_correspondencias');
    $classification = $this->text($input, 'clasificacion');
    $responsible = $this->text($input, 'quien_corresponde');
    $search = $this->text($input, 'busqueda');
    $where = ['1 = 1'];
    $params = [];

    if ($classification !== '') {
      $where[] = '`clasificacion` = ?';
      $params[] = $classification;
    }
    if ($responsible !== '') {
      $where[] = '`quien_corresponde` = ?';
      $params[] = $responsible;
    }
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = '(`descripcion` LIKE ? OR `observaciones` LIKE ?)';
      $params[] = $like;
      $params[] = $like;
    }

    $rows = $this->db->getResults(
      "SELECT `_ID`, `descripcion`, `clasificacion`, `quien_corresponde`,
              `fundamento_legal`, `reembolso`, `observaciones`
         FROM `{$table}`
        WHERE " . implode(' AND ', $where) . "
        ORDER BY `clasificacion` ASC, `descripcion` ASC",
      $params
    );
    JsonResponse::success(['rows' => $rows]);
  }

  /** @param array<string,mixed> $input */
  public function correspondenceSave(array $input): never
  {
    $this->verify($input);
    $id = (int) ($input['id'] ?? 0);
    $data = [
      'descripcion' => $this->text($input, 'descripcion'),
      'clasificacion' => $this->text($input, 'clasificacion'),
      'quien_corresponde' => $this->text($input, 'quien_corresponde'),
      'fundamento_legal' => $this->text($input, 'fundamento_legal'),
      'reembolso' => $this->text($input, 'reembolso'),
      'observaciones' => $this->text($input, 'observaciones'),
      'cct_modified' => date('Y-m-d H:i:s'),
      'cct_author_id' => Auth::userId(),
    ];
    if ($data['descripcion'] === '' || $data['clasificacion'] === '' || $data['quien_corresponde'] === '') {
      JsonResponse::error('Situación, Clasificación y Responsable son requeridos.');
    }
    $this->saveRow('jet_cct_correspondencias', $id, $data, 'Correspondencia');
  }

  /** @param array<string,mixed> $input */
  public function correspondenceDelete(array $input): never
  {
    $this->verify($input);
    $this->deleteRow('jet_cct_correspondencias', (int) ($input['id'] ?? 0), ['admin'], 'Correspondencia');
  }

  /** @param array<string,mixed> $input */
  public function responseRead(array $input): never
  {
    $this->verify($input);
    $table = $this->db->table('jet_cct_respuesta_de_ticket');
    $where = ['1 = 1'];
    $params = [];
    foreach (['categoria', 'estado', 'situacion', 'respuesta'] as $field) {
      $value = $this->text($input, $field);
      if ($value === '') {
        continue;
      }
      if ($field === 'categoria') {
        $where[] = '`categoria` = ?';
        $params[] = $value;
      } else {
        $where[] = '`' . $field . '` LIKE ?';
        $params[] = '%' . $this->db->escapeLike($value) . '%';
      }
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `categoria`, `estado`, `situacion`, `respuesta`
         FROM `{$table}` WHERE " . implode(' AND ', $where) . "
        ORDER BY `categoria` ASC, `situacion` ASC",
      $params
    );
    JsonResponse::success(['rows' => $rows]);
  }

  /** @param array<string,mixed> $input */
  public function responseSave(array $input): never
  {
    $this->verify($input);
    $id = (int) ($input['id'] ?? 0);
    $data = [
      'categoria' => $this->text($input, 'categoria'),
      'estado' => $this->text($input, 'estado'),
      'situacion' => $this->text($input, 'situacion'),
      'respuesta' => trim((string) ($input['respuesta'] ?? '')),
      'cct_modified' => date('Y-m-d H:i:s'),
      'cct_author_id' => Auth::userId(),
    ];
    if ($data['respuesta'] === '') {
      JsonResponse::error('El campo Respuesta es requerido.');
    }
    $this->saveRow('jet_cct_respuesta_de_ticket', $id, $data, 'Respuesta');
  }

  /** @param array<string,mixed> $input */
  public function responseDelete(array $input): never
  {
    $this->verify($input);
    $this->deleteRow('jet_cct_respuesta_de_ticket', (int) ($input['id'] ?? 0), ['admin', 'editor'], 'Respuesta');
  }

  /** @param array<string,mixed> $input */
  public function articleRead(array $input): never
  {
    $this->verify($input);
    $table = $this->db->table('jet_cct_articulos_codigo');
    $category = $this->text($input, 'categoria');
    $search = $this->text($input, 'busqueda');
    $where = ['1 = 1'];
    $params = [];
    if ($category !== '') {
      $where[] = '`categoria` = ?';
      $params[] = $category;
    }
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = '(`codigo_civil` LIKE ? OR CAST(`_ID` AS CHAR) LIKE ?)';
      $params[] = $like;
      $params[] = $like;
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `categoria`, `codigo_civil` FROM `{$table}`
        WHERE " . implode(' AND ', $where) . " ORDER BY `categoria` ASC, `_ID` ASC",
      $params
    );
    JsonResponse::success(['rows' => $rows]);
  }

  /** @param array<string,mixed> $input */
  public function articleSave(array $input): never
  {
    $this->verify($input);
    $id = (int) ($input['id'] ?? 0);
    $data = [
      'categoria' => $this->text($input, 'categoria'),
      'codigo_civil' => trim((string) ($input['codigo_civil'] ?? '')),
      'cct_modified' => date('Y-m-d H:i:s'),
      'cct_author_id' => Auth::userId(),
    ];
    if ($data['categoria'] === '' || $data['codigo_civil'] === '') {
      JsonResponse::error('Categoría y Contenido son requeridos.');
    }
    $this->saveRow('jet_cct_articulos_codigo', $id, $data, 'Artículo');
  }

  /** @param array<string,mixed> $input */
  public function articleDelete(array $input): never
  {
    $this->verify($input);
    $this->deleteRow('jet_cct_articulos_codigo', (int) ($input['id'] ?? 0), ['admin'], 'Artículo');
  }

  /** @param array<string,mixed> $input */
  public function articleCategories(array $input): never
  {
    $this->verify($input);
    $table = $this->db->table('jet_cct_articulos_codigo');
    $rows = $this->db->getResults(
      "SELECT DISTINCT `categoria` FROM `{$table}` WHERE `categoria` != '' ORDER BY `categoria` ASC"
    );
    JsonResponse::success(['categories' => array_values(array_filter(array_column($rows, 'categoria')))]);
  }

  /** @param array<string,mixed> $input */
  private function verify(array $input): void
  {
    if (!$this->csrf->verify('scm_nonce', (string) ($input['nonce'] ?? ''), false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }
  }

  /** @param array<string,mixed> $data */
  private function saveRow(string $tableName, int $id, array $data, string $label): never
  {
    $table = $this->db->table($tableName);
    $isMasculine = $label === 'Artículo';
    if ($id > 0) {
      $this->db->update($table, $data, ['_ID' => $id]);
      JsonResponse::success([
        'message' => $label . ($isMasculine ? ' actualizado.' : ' actualizada.'),
        'id' => $id,
      ]);
    }
    $data['cct_created'] = date('Y-m-d H:i:s');
    $data['cct_status'] = 'publish';
    $this->db->insert($table, $data);
    JsonResponse::success([
      'message' => $label . ($isMasculine ? ' creado.' : ' creada.'),
      'id' => (int) $this->db->lastInsertId(),
    ]);
  }

  /** @param string[] $allowedRoles */
  private function deleteRow(string $tableName, int $id, array $allowedRoles, string $label): never
  {
    if (!in_array(Auth::userRol(), $allowedRoles, true)) {
      JsonResponse::error('Sin permisos para eliminar.', 403);
    }
    if ($id <= 0) {
      JsonResponse::error('ID inválido.');
    }
    $this->db->delete($this->db->table($tableName), ['_ID' => $id]);
    JsonResponse::success([
      'message' => $label . ($label === 'Artículo' ? ' eliminado.' : ' eliminada.'),
    ]);
  }

  /** @param array<string,mixed> $input */
  private function text(array $input, string $key): string
  {
    return trim(strip_tags((string) ($input[$key] ?? '')));
  }
}
