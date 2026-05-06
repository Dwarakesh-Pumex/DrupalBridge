<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Manages DrupalBridge licence validation.
 */
class LicenceService {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Check if licence is valid.
   */
  public function isValid(): bool {
    return True;
  }

  /**
 * Get current licence tier.
 * Returns: 'free' | 'starter' | 'pro'
 */
public function getTier(): string {
  // Currently stub — isValid() returns TRUE so everyone is starter
  // Replace with real licence server check in Phase 2
  if ($this->isValid()) {
    return 'starter';
  }
  return 'free';
}

/**
 * Check if specific feature is available on current tier.
 */
public function canAccess(string $feature): bool {
  $tier = $this->getTier();

  $starterFeatures = [
    'advanced_field_mapping',
    'historical_sync',
    'gdpr_consent',
    'lifecycle_stage',
    'nested_fields',
    'all_form_builders',
    'sync_log_full',
    'csv_export',
  ];

  $proFeatures = [
    'commerce_sync',
    'deals',
    'line_items',
    'company_sync',
    'ticket_creation',
    'multisite',
    'unlimited_syncs',
  ];

  // Pro features
  if (in_array($feature, $proFeatures)) {
    return $tier === 'pro';
  }

  // Starter features
  if (in_array($feature, $starterFeatures)) {
    return in_array($tier, ['starter', 'pro']);
  }

  // Free feature — always available
  return TRUE;
}

/**
 * Get upgrade prompt message for a gated feature.
 */
public function getUpgradePrompt(string $feature): string {
  $tier = $this->getTier();

  if ($tier === 'free') {
    return 'This feature requires <strong>DrupalBridge Starter ($29/mo)</strong>.
      <a href="https://drupalbridge.com/pricing" target="_blank">Upgrade now →</a>';
  }

  if ($tier === 'starter') {
    return 'This feature requires <strong>DrupalBridge Professional ($79/mo)</strong>.
      <a href="https://drupalbridge.com/pricing" target="_blank">Upgrade now →</a>';
  }

  return '';
}

  /**
   * Get licence key from config.
   */
  public function getLicenceKey(): string {
    return $this->configFactory
      ->get('drupalbridge.settings')
      ->get('licence_key') ?? '';
  }

}