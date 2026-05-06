<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Adapter for Drupal EForm module.
 * EForm is a Drupal form builder similar to Gravity Forms.
 */
class EFormAdapter implements FormAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(string $formId): bool {
    return str_starts_with($formId, 'eform_submit_');
  }

  /**
   * {@inheritdoc}
   */
  public function normalise(array $rawData): array {
    $contactData = [];

    $savedMappings = \Drupal::config('drupalbridge.field_mapping')
      ->get('mappings') ?? [];

    if (!empty($savedMappings)) {
      foreach ($savedMappings as $safeKey => $hubspotProperty) {
        $fieldName = str_replace('__', '.', $safeKey);
        $value     = $rawData[$fieldName] ?? '';

        if (is_array($value)) {
          $value = $value[0]['value'] ?? $value[0] ?? '';
        }

        if (!empty($value)) {
          $contactData[$hubspotProperty] = $value;
        }
      }
    }
    else {
      // EForm default field guessing
      $contactData = [
        'email'     => $rawData['field_eform_email']
          ?? $rawData['email']
          ?? '',
        'firstname' => $rawData['field_eform_first_name']
          ?? $rawData['first_name']
          ?? '',
        'lastname'  => $rawData['field_eform_last_name']
          ?? $rawData['last_name']
          ?? '',
      ];
    }

    return $contactData;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'EForm';
  }

  /**
   * EForm requires Starter tier.
   */
  public function requiresStarter(): bool {
    return TRUE;
  }

}