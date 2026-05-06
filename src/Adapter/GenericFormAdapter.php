<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Generic adapter for any Drupal Form API form.
 * Used as fallback when no specific adapter matches.
 */
class GenericFormAdapter implements FormAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(string $formId): bool {
    // This is a fallback — supports any form
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function normalise(array $rawData): array {
  $contactData = [];

  // ✅ EMAIL (strict match only)
  foreach ($rawData as $key => $value) {
    if (in_array($key, ['email', 'mail', 'user_mail'])) {

      if (is_array($value)) {
        $value = $value['value'] ?? $value[0]['value'] ?? $value[0] ?? '';
      }

      if (!empty($value)) {
        $contactData['email'] = trim($value);
        break;
      }
    }
  }

  // FIRST NAME
  foreach ($rawData as $key => $value) {
    if (stripos($key, 'first') !== FALSE) {

      if (is_array($value)) {
        $value = $value['value'] ?? $value[0]['value'] ?? $value[0] ?? '';
      }

      $contactData['firstname'] = trim((string) $value);
    }
  }

  // LAST NAME
  foreach ($rawData as $key => $value) {
    if (stripos($key, 'last') !== FALSE) {

      if (is_array($value)) {
        $value = $value['value'] ?? $value[0]['value'] ?? $value[0] ?? '';
      }

      $contactData['lastname'] = trim((string) $value);
    }
  }

  return $contactData;
}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'Generic Form API';
  }

}