<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait PublicTicketCreationConcern
{
  public function createTicket(string $actor, string $selectedId, string $lookupValue, array $input): array
  {
    $config = self::getActorConfig($actor);
    if (empty($config)) {
      return ['ok' => false, 'message' => 'Tipo de actor no valido.'];
    }

    $selectedId = trim($selectedId);
    if ($selectedId === '') {
      return ['ok' => false, 'message' => 'Debes seleccionar un registro para crear el PQR.'];
    }

    $asunto = trim((string) ($input['asunto'] ?? ''));
    $descripcion = trim((string) ($input['descripcion'] ?? ''));
    if ($asunto === '' || $descripcion === '') {
      return ['ok' => false, 'message' => 'Asunto y descripcion son obligatorios.'];
    }

    $tipoPqrsInput = (string) ($input['tema_ayuda'] ?? $input['tipo_pqrs'] ?? '');
    $tipoPqrs = $this->normalizePublicPqrTheme($tipoPqrsInput);
    if ($tipoPqrs === '') {
      $defaultTheme = $this->normalizePublicPqrTheme((string) ($config['theme_default'] ?? ''));
      $publicThemes = self::getPqrThemes();
      $tipoPqrs = $defaultTheme !== '' ? $defaultTheme : ($publicThemes[0] ?? self::PQR_THEMES[0]);
    }
    $departamento = $this->getDepartmentForActor($actor);
    $allowWithoutContract = !empty($input['no_contract_commercial'])
      && in_array($actor, ['propietario', 'arrendatario'], true)
      && in_array($tipoPqrs, ['Captacion', 'Avaluo'], true);

    $contract = null;
    $client = null;
    if (!empty($config['requires_contract']) && !$allowWithoutContract) {
      $contract = $this->fetchContractById($selectedId);
      if (!is_array($contract)) {
        return ['ok' => false, 'message' => 'Contrato no encontrado.'];
      }

      if (in_array($actor, ['propietario', 'arrendatario'], true) && $this->requiresDeliveredContractTheme($tipoPqrs) && !$this->isDeliveredContract($contract)) {
        return ['ok' => false, 'message' => 'El contrato seleccionado no esta en estado Entregado. No se puede hacer una solicitud en esta categoria.'];
      }

      if ($lookupValue !== '' && !$this->contractMatchesLookup($actor, $contract, $lookupValue)) {
        return ['ok' => false, 'message' => 'El contrato no coincide con la consulta realizada.'];
      }
    } else {
      $client = $this->fetchClientById($selectedId);
      if (!is_array($client)) {
        return ['ok' => false, 'message' => 'Cliente no encontrado.'];
      }
    }

    $contact = $this->buildRequesterContact($actor, $contract, $client, $input);
    if ($contact['name'] === '' || $contact['phone'] === '' || ($actor !== 'cliente' && $contact['email'] === '')) {
      return ['ok' => false, 'message' => 'Debes completar solicitante, correo y celular.'];
    }

    $responsable = $this->resolveResponsible($contract, $tipoPqrs, $departamento, $actor);

    $ticketResult = $this->insertTicket(
      $actor,
      $config,
      $tipoPqrs,
      $departamento,
      $asunto,
      $descripcion,
      $input,
      $contact,
      $responsable,
      $contract,
      $client
    );

    if (empty($ticketResult['ok'])) {
      return $ticketResult;
    }

    $ticketId = (int) ($ticketResult['ticket_id'] ?? 0);
    $logicalTicket = (string) $ticketId;
    $this->insertPropertyHistory($logicalTicket, $asunto, $responsable, $contract);

    $notify = $this->notifyResponsible(
      $ticketId,
      $logicalTicket,
      $asunto,
      $tipoPqrs,
      $departamento,
      $contact,
      $responsable,
      $contract,
      $client,
      $actor,
      (string) ($config['label'] ?? ucfirst($actor))
    );

    return [
      'ok' => true,
      'message' => 'PQR creado correctamente.',
      'ticket_id' => $logicalTicket,
      'responsable' => $responsable,
      'notifications' => $notify,
    ];
  }

  /** @return array<int,array<string,mixed>> */
}
