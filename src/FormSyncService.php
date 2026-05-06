<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupalbridge\SyncLogService;

class FormSyncService {

  protected HubSpotClient $hubspotClient;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ConfigFactoryInterface $configFactory;
  protected SyncLogService $syncLogService;

  public function __construct(
    HubSpotClient $hubspotClient,
    LoggerChannelFactoryInterface $loggerFactory,
    ConfigFactoryInterface $configFactory,
    SyncLogService $syncLogService
  ) {
    $this->hubspotClient   = $hubspotClient;
    $this->loggerFactory   = $loggerFactory;
    $this->configFactory   = $configFactory;
    $this->syncLogService  = $syncLogService;
  }

  /**
   * Main entry point called by Webform handler.
   */
  public function syncSubmission(array $formValues): void {
    try {
      $mappedData = $this->mapFormValues($formValues);
      $this->createOrUpdateContact($mappedData);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'HubSpot sync failed: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Create or update contact using HubSpot batch upsert.
   */
  public function createOrUpdateContact(array $data): array {
    if (empty($data['email'])) {
      throw new \Exception('Email is required for HubSpot sync.');
    }

    $email     = $data['email'];
    $startTime = microtime(TRUE);

    // Read lifecycle stage from config
    $configLifecycle = $this->configFactory
      ->get('drupalbridge.settings')
      ->get('static_values.lifecyclestage') ?? 'lead';

    $properties                   = $data;
    $properties['lifecyclestage'] = $data['lifecyclestage'] ?? $configLifecycle;
    unset($properties['email']);

    $this->loggerFactory->get('drupalbridge')->notice(
      'HubSpot upsert start: @email lifecyclestage=@stage',
      [
        '@email' => $email,
        '@stage' => $properties['lifecyclestage'],
      ]
    );

    $payload = [
      'inputs' => [
        [
          'idProperty' => 'email',
          'id'         => $email,
          'properties' => $properties,
        ],
      ],
    ];

    // Add legalConsentOptions if GDPR enabled
    try {
      if (\Drupal::hasService('drupalbridge.legal_consent_service')) {
        $legalConsentService = \Drupal::service('drupalbridge.legal_consent_service');
        if ($legalConsentService->isEnabled()) {
          $payload['inputs'][0]['legalConsentOptions'] = $legalConsentService->build();
        }
      }
    }
    catch (\Exception $e) {
      // Continue without consent
    }

    try {
      $response = $this->hubspotClient->post(
        '/crm/v3/objects/contacts/batch/upsert',
        $payload
      );

      $syncTime  = microtime(TRUE) - $startTime;
      $hubspotId = $response['results'][0]['id'] ?? NULL;

      // Record sync time for performance monitoring
      try {
        if (\Drupal::hasService('drupalbridge.rate_limit_service')) {
          \Drupal::service('drupalbridge.rate_limit_service')
            ->recordSyncTime($syncTime, $email);
        }
      }
      catch (\Exception $e) {
        // Continue
      }

      // Log success — EXACTLY 5 arguments, no $data
      try {
        $this->syncLogService->log(
          $email,
          'synced',
          'form_sync',
          NULL,
          $hubspotId
        );
      }
      catch (\Exception $e) {
        // Continue even if log fails
      }

      $this->loggerFactory->get('drupalbridge')->notice(
        'HubSpot upsert success: @email (sync time: @time seconds)',
        [
          '@email' => $email,
          '@time'  => round($syncTime, 3),
        ]
      );

      return $response;

    }
    catch (\Exception $e) {
      // Record error for alerting
      try {
        if (\Drupal::hasService('drupalbridge.rate_limit_service')) {
          \Drupal::service('drupalbridge.rate_limit_service')
            ->recordError($e->getMessage(), $email);
        }
      }
      catch (\Exception $re) {
        // Continue
      }

      // Log failure — EXACTLY 5 arguments, no $data
      try {
        $this->syncLogService->log(
          $email,
          'failed',
          'form_sync',
          $e->getMessage(),
          NULL
        );
      }
      catch (\Exception $le) {
        // Continue
      }

      throw $e;
    }
  }

  /**
   * Handle nested HubSpot field mapping with dot notation.
   */
  private function resolveNestedValue(
    array $formValues,
    string $drupalField
  ): string {
    if (str_contains($drupalField, '.')) {
      [$parent, $child] = explode('.', $drupalField, 2);
      $parentValue = $formValues[$parent] ?? [];

      if (is_array($parentValue)) {
        return $parentValue[$child]
          ?? $parentValue[0][$child]
          ?? '';
      }
      return '';
    }

    $value = $formValues[$drupalField] ?? '';
    if (is_array($value)) {
      return $value[0]['value'] ?? $value[0] ?? '';
    }
    return $value;
  }

  /**
   * Map Drupal fields to HubSpot fields using saved config mappings.
   */
  public function mapFormValues(array $formValues): array {
    $savedMappings = $this->configFactory
      ->get('drupalbridge.field_mapping')
      ->get('mappings') ?? [];

    $staticValues = $this->configFactory
      ->get('drupalbridge.field_mapping')
      ->get('static_values') ?? [];

    if (empty($savedMappings)) {
      return array_merge([
        'email'          => $formValues['field_email_id'][0]['value'] ?? '',
        'firstname'      => $formValues['field_first_name'][0]['value'] ?? '',
        'lastname'       => $formValues['field_last_name'][0]['value'] ?? '',
        'lifecyclestage' => 'lead',
      ], $staticValues);
    }

    $lifecycleOverride = $this->configFactory
      ->get('drupalbridge.field_mapping')
      ->get('lifecycle_stage');

    $contactData = [];
    $contactData['lifecyclestage'] = !empty($lifecycleOverride)
      ? $lifecycleOverride
      : ($staticValues['lifecyclestage'] ?? 'lead');

    foreach ($savedMappings as $safeKey => $hubspotProperty) {
      $drupalField = str_replace('__', '.', $safeKey);
      $value       = '';

      if (str_contains($drupalField, '.')) {
        [$parent, $child] = explode('.', $drupalField, 2);
        $parentValue = $formValues[$parent] ?? [];

        if (is_array($parentValue)) {
          $value = $parentValue[$child]
            ?? $parentValue[0][$child]
            ?? '';
        }
      }
      else {
        if (!empty($formValues[$drupalField])) {
          $value = is_array($formValues[$drupalField])
            ? ($formValues[$drupalField][0]['value'] ?? '')
            : $formValues[$drupalField];
        }
        else {
          $value = $this->resolveNestedValue($formValues, $drupalField);
        }
      }

      if (!empty($value)) {
        $contactData[$hubspotProperty] = $value;
      }
    }

    foreach ($staticValues as $hubspotProperty => $staticValue) {
      $contactData[$hubspotProperty] = $staticValue;
    }

    if (empty($contactData['lifecyclestage'])) {
      $contactData['lifecyclestage'] = 'lead';
    }

    return $contactData;
  }

}