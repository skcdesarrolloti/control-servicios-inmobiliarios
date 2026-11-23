<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Modules\OwnerCredits\OwnerCreditService;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditSimulator;
use SCM\Modules\OwnerCredits\OwnerCreditUsuryRate;

/**
 * Herramientas › Simulador de crédito: el funcionario simula valor, plazo y tasa para darle detalles a un propietario,
 * arrendatario u otra persona. No registra nada ni consulta contratos; usa la configuración de Créditos a propietarios
 * (tasa de referencia, usura y tope de plazo) y el mismo plan de pagos del liquidador.
 */
final class CreditSimulationService
{
  public const RECIPIENTS = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'codeudor' => 'Codeudor', 'otro' => 'Otra persona'];

  /** @param array<string,mixed> $settings OwnerCreditSettings::read() */
  public function __construct(private array $settings) {}

  /** Valores con que abre el formulario. @return array{rate:float,usury:float,policy_rate:float,max_term:int,default_term:int,purposes:list<string>} */
  public function defaults(): array
  {
    return [
      'rate' => OwnerCreditSimulator::rate($this->settings),
      'usury' => OwnerCreditUsuryRate::monthly($this->settings),
      'policy_rate' => (float) $this->settings['reference_rate'] * (1 - (float) $this->settings['policy_discount']),
      'max_term' => (int) $this->settings['max_term'],
      'default_term' => min((int) $this->settings['default_term'], OwnerCreditSimulator::MAX_TERM),
      'purposes' => OwnerCreditSettings::PURPOSES,
    ];
  }

  /**
   * Lee y valida lo que escribió el funcionario. @param array<string,mixed> $raw
   * @return array{recipient:string,name:string,document:string,purpose:string,amount:float,term:int,rate:float,disbursed_ts:int,notes:string}
   * @throws \InvalidArgumentException
   */
  public function input(array $raw): array
  {
    $text = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($raw[$key] ?? '')) ?? ''), 0, $max);
    $recipient = (string) ($raw['destinatario'] ?? 'propietario');
    if (!isset(self::RECIPIENTS[$recipient])) $recipient = 'propietario';
    $amount = OwnerCreditService::money((string) ($raw['valor'] ?? ''));
    if ($amount < 1000) throw new \InvalidArgumentException('Escribe el valor del crédito.');
    if ($amount > 10000000000) throw new \InvalidArgumentException('El valor del crédito es demasiado alto para simular.');
    $term = (int) ($raw['plazo'] ?? 0);
    if ($term < 1 || $term > OwnerCreditSimulator::MAX_TERM) throw new \InvalidArgumentException('El plazo debe estar entre 1 y ' . OwnerCreditSimulator::MAX_TERM . ' meses.');
    $rateText = str_replace(',', '.', trim((string) ($raw['tasa'] ?? '')));
    if ($rateText === '' || !is_numeric($rateText) || (float) $rateText <= 0) throw new \InvalidArgumentException('Escribe la tasa mensual (N.M.V.) en porcentaje, por ejemplo 2,42.');
    $rate = (float) $rateText / 100;
    $usury = OwnerCreditUsuryRate::monthly($this->settings);
    if ($usury > 0 && $rate > $usury + 1e-9) throw new \InvalidArgumentException('La tasa supera la usura vigente (' . self::percent($usury, 4) . ' mensual). Bájala para simular.');
    $date = trim((string) ($raw['desembolso'] ?? ''));
    $disbursed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? (int) strtotime($date . ' 12:00:00') : 0;
    $purpose = $text('destinacion', 80);
    return [
      'recipient' => $recipient, 'name' => $text('nombre', 120), 'document' => $text('documento', 40),
      'purpose' => $purpose, 'amount' => round($amount, 2), 'term' => $term, 'rate' => $rate,
      'disbursed_ts' => $disbursed > 0 ? $disbursed : (int) strtotime(date('Y-m-d') . ' 12:00:00'),
      'notes' => mb_substr(trim((string) ($raw['observaciones'] ?? '')), 0, 600),
    ];
  }

  /**
   * Simulación completa: plan, comparativo de plazos y avisos de la política.
   * @param array<string,mixed> $input input()
   * @return array{input:array<string,mixed>,simulation:array<string,mixed>,compare:list<array<string,mixed>>,warnings:list<string>}
   */
  public function simulate(array $input): array
  {
    $simulation = OwnerCreditSimulator::simulate((float) $input['amount'], (int) $input['term'], (float) $input['rate'], (int) $input['disbursed_ts']);
    $compare = OwnerCreditSimulator::compare((float) $input['amount'], (float) $input['rate'], [...OwnerCreditSimulator::COMPARE_TERMS, (int) $input['term']]);
    return ['input' => $input, 'simulation' => $simulation, 'compare' => $compare, 'warnings' => $this->warnings($input)];
  }

  /** Lo que el funcionario debe tener presente antes de dar las cifras. @return list<string> */
  public function warnings(array $input): array
  {
    $defaults = $this->defaults();
    $warnings = [];
    if ((int) $input['term'] > $defaults['max_term']) {
      $warnings[] = 'El plazo supera el tope de la política de créditos (' . $defaults['max_term'] . ' meses). En créditos a propietarios el plazo también depende de la vigencia de sus contratos.';
    }
    if ((float) $input['rate'] < $defaults['policy_rate'] - 1e-9) {
      $warnings[] = 'La tasa está por debajo del mínimo de la política (' . self::percent($defaults['policy_rate'], 4) . ' mensual): aprobarla así queda fuera de política.';
    }
    if (!in_array($input['recipient'], OwnerCreditService::AVAILABLE_BENEFICIARY_TYPES, true)) {
      $warnings[] = 'Por ahora el liquidador solo registra créditos a propietarios: para ' . mb_strtolower(self::RECIPIENTS[$input['recipient']]) . ' esta simulación es solo informativa.';
    }
    if (OwnerCreditUsuryRate::staleMessage($this->settings) !== '') {
      $warnings[] = 'La tasa de usura guardada no está vigente hoy: confirma la tasa antes de darle las cifras al cliente.';
    }
    return $warnings;
  }

  /**
   * Texto corto para pegar en WhatsApp o en un correo, firmado como "Nombre - Cargo - Cel. 300…".
   * @param array<string,mixed> $result simulate() @param array{name?:string,cargo?:string,phone?:string} $signer
   */
  public static function summary(array $result, array $signer): string
  {
    $input = $result['input'];
    $sim = $result['simulation'];
    $lines = ['Simulación de crédito · SKC SuCasa Inmobiliaria'];
    $who = trim((string) $input['name']);
    if ($who !== '') $lines[] = 'Cliente: ' . $who . ' (' . self::RECIPIENTS[$input['recipient']] . ')';
    if ((string) $input['purpose'] !== '') $lines[] = 'Destinación: ' . $input['purpose'];
    $lines[] = 'Valor: ' . self::money((float) $sim['amount']);
    $lines[] = 'Plazo: ' . (int) $sim['term'] . ((int) $sim['term'] === 1 ? ' mes' : ' meses');
    $lines[] = 'Tasa: ' . self::percent((float) $sim['rate'], 2) . ' mensual (' . self::percent((float) $sim['effective_annual'], 2) . ' E.A.)';
    $lines[] = 'Cuota mensual estimada: ' . self::money((float) $sim['payment']);
    if ((int) $sim['first_due_ts'] > 0) $lines[] = 'Primera cuota: ' . date('d/m/Y', (int) $sim['first_due_ts']) . ' · Última: ' . date('d/m/Y', (int) $sim['last_due_ts']);
    $lines[] = 'Total intereses: ' . self::money((float) $sim['total_interest']) . ' · Total a pagar: ' . self::money((float) $sim['total']);
    $lines[] = '';
    $lines[] = OwnerCreditService::DISCLAIMER;
    $line = self::signerLine($signer);
    if ($line !== '') {
      $lines[] = '';
      $lines[] = 'Atentamente, ' . $line;
    }
    return implode("\n", $lines);
  }

  /** "Nombre - Cargo - Cel. 3001234567": celular nacional sin indicativo y sin correo; omite lo que falte. */
  public static function signerLine(array $signer): string
  {
    $phone = preg_replace('/\D+/', '', (string) ($signer['phone'] ?? '')) ?: '';
    if (strlen($phone) === 12 && str_starts_with($phone, '57')) $phone = substr($phone, 2);
    return implode(' - ', array_filter([trim((string) ($signer['name'] ?? '')), trim((string) ($signer['cargo'] ?? '')), $phone !== '' ? 'Cel. ' . $phone : ''], static fn(string $value): bool => $value !== ''));
  }

  public static function money(float $value): string
  {
    return '$' . number_format(round($value), 0, ',', '.');
  }

  public static function percent(float $value, int $decimals): string
  {
    return rtrim(rtrim(number_format($value * 100, $decimals, ',', '.'), '0'), ',') . ' %';
  }
}
