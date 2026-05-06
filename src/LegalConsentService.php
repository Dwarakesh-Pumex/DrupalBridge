<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds legalConsentOptions payload for HubSpot API calls.
 * Required for GDPR compliance when submitting contacts.
 */
class LegalConsentService {

  protected ConfigFactoryInterface $configFactory;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * Build legalConsentOptions for a contact submission.
   *
   * @param array $options
   *   Override options:
   *   - consent_text: Custom consent text shown to user
   *   - subscription_type_id: HubSpot subscription type ID
   *   - communications_consent: Whether user consented to communications
   *
   * @return array
   *   legalConsentOptions array ready for HubSpot API payload.
   */
  public function build(array $options = []): array {
    $config = $this->configFactory->get('drupalbridge.settings');

    $consentText = $options['consent_text']
      ?? $config->get('gdpr_consent_text')
      ?? 'I agree to allow this company to store and process my personal data.';

    $communicationsConsent = $options['communications_consent'] ?? TRUE;

    $subscriptionTypeId = $options['subscription_type_id']
      ?? $config->get('gdpr_subscription_type_id')
      ?? NULL;

    $legalConsent = [
      'consent' => [
        'consentToProcess' => TRUE,
        'text'             => $consentText,
      ],
    ];

    // Add communications consent if subscription type is configured
    if ($subscriptionTypeId) {
      $legalConsent['consent']['communications'] = [
        [
          'value'              => $communicationsConsent,
          'subscriptionTypeId' => (int) $subscriptionTypeId,
          'text'               => $consentText,
        ],
      ];
    }

    return $legalConsent;
  }

  /**
   * Check if GDPR mode is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory
      ->get('drupalbridge.settings')
      ->get('gdpr_enabled');
  }

}