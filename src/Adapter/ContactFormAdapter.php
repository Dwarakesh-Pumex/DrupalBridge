<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Adapter for Drupal Contact module forms.
 */
class ContactFormAdapter implements FormAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(string $formId): bool {
    return str_starts_with($formId, 'contact_message_');
  }

  /**
   * {@inheritdoc}
   */
 public function normalise(array $rawData): array {

  $contactData = [];

  // ---------------------------
  // 1. EMAIL DETECTION (ROBUST)
  // ---------------------------
  $emailFields = ['mail', 'email', 'field_email'];

  foreach ($emailFields as $field) {
    if (!empty($rawData[$field])) {

      $value = $rawData[$field];

      if (is_array($value)) {
        $value = $value[0]['value'] ?? $value[0] ?? '';
      }

      if (!empty($value)) {
        $contactData['email'] = $value;
        break;
      }
    }
  }

  // ---------------------------
  // 2. FIRST NAME
  // ---------------------------
  $contactData['firstname'] =
    $rawData['field_first_name']
    ?? $rawData['name']
    ?? '';

  // ---------------------------
  // 3. LAST NAME
  // ---------------------------
  $contactData['lastname'] =
    $rawData['field_last_name']
    ?? '';

  // ---------------------------
  // 4. OPTIONAL: FIELD MAPPING
  // ---------------------------
  $savedMappings = \Drupal::config('drupalbridge.field_mapping')->get('mappings') ?? [];

  if (!empty($savedMappings)) {
    foreach ($savedMappings as $safeKey => $hubspotProperty) {

      $drupalField = str_replace('__', '.', $safeKey);

      $value = $rawData[$drupalField] ?? '';

      if (is_array($value)) {
        $value = $value[0]['value'] ?? $value[0] ?? '';
      }

      if (!empty($value)) {
        $contactData[$hubspotProperty] = $value;
      }
    }
  }

  return $contactData;
}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'Drupal Contact Form';
  }

}