<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\App;
use SCM\Core\Database;
use SCM\Core\Settings;

final class InternalNotificationRecipients
{
  public const SETTINGS_KEY = 'internal_admin_notifications';

  /** @return int[] */
  public static function idsForAction(Database $db, string $action): array
  {
    $actionKey = self::sanitizeActionKey($action);
    if ($actionKey === '') {
      return [];
    }

    $raw = self::settings($db)->get(self::SETTINGS_KEY, []);
    $settings = is_array($raw) ? $raw : [];
    $ids = [];
    foreach ((array) ($settings[$actionKey] ?? []) as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }

    return array_values($ids);
  }

  /** @return string[] */
  public static function emailsForAction(Database $db, string $action): array
  {
    $emails = [];
    foreach (self::contactsForAction($db, $action) as $funcionario) {
      $email = trim((string) ($funcionario['email'] ?? ''));
      if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emails[strtolower($email)] = $email;
      }
    }

    return array_values($emails);
  }

  /** @return array<int,array<string,string>> */
  public static function contactsForAction(Database $db, string $action): array
  {
    $selectedIds = self::idsForAction($db, $action);
    if ($selectedIds === []) {
      return [];
    }

    $selected = array_fill_keys(array_map('strval', $selectedIds), true);
    $contacts = [];
    foreach (FuncionarioOptions::panelFuncionarios($db, new SchemaInspector($db), 'primary') as $funcionario) {
      $id = (string) ((int) ($funcionario['id'] ?? 0));
      if ($id === '0' || !isset($selected[$id])) {
        continue;
      }
      $contacts[] = $funcionario;
    }

    return $contacts;
  }

  private static function sanitizeActionKey(string $action): string
  {
    return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($action))) ?: '';
  }

  private static function settings(Database $db): Settings
  {
    try {
      return App::settings();
    } catch (\Throwable $exception) {
      return new Settings($db, true);
    }
  }
}
