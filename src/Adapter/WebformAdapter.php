<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Adapter for Drupal Webform module.
 */
class WebformAdapter implements FormAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(string $formId): bool {
    return str_starts_with($formId, 'webform_submission_');
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
        $webformField = str_replace('__', '.', $safeKey);
        $value        = '';

        if (str_contains($webformField, '.')) {
          [$parent, $child] = explode('.', $webformField, 2);
          $parentValue = $rawData[$parent] ?? [];

          if (is_array($parentValue)) {
            $value = $parentValue[$child]
              ?? $parentValue[0][$child]
              ?? '';
          }
        }
        else {
          $value = $rawData[$webformField] ?? '';
        }

        if (!empty($value)) {
          $contactData[$hubspotProperty] = $value;
        }
      }
    }
    else {
      // Fallback guessing
      $emailFields = ['email', 'email_address', 'your_email'];
      foreach ($emailFields as $field) {
        if (!empty($rawData[$field])) {
          $contactData['email'] = $rawData[$field];
          break;
        }
      }

      $contactData['firstname'] = $rawData['firstname']
        ?? $rawData['first_name']
        ?? '';
      $contactData['lastname']  = $rawData['lastname']
        ?? $rawData['last_name']
        ?? '';
    }

    return $contactData;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'Drupal Webform';
  }

}