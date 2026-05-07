<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Manages DrupalBridge licence validation.
 */
class LicenceService {

  protected ConfigFactoryInterface $configFactory;

  // Cache tier for current request
  protected ?string $cachedTier = NULL;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get current licence tier.
   * Returns: 'free' | 'starter' | 'pro'
   */
  public function getTier(): string {
    // Return cached value if already determined this request
    if ($this->cachedTier !== NULL) {
      return $this->cachedTier;
    }

    $licenceKey = $this->getLicenceKey();

    // No licence key — free tier
    if (empty($licenceKey)) {
      $this->cachedTier = 'free';
      return 'free';
    }

    // Check cached tier from last server validation (1 hour cache)
    $config      = $this->configFactory->get('drupalbridge.settings');
    $cachedTier  = $config->get('licence_tier_cache') ?? '';
    $cacheExpiry = $config->get('licence_cache_expiry') ?? 0;

    if (!empty($cachedTier) && time() < $cacheExpiry) {
      $this->cachedTier = $cachedTier;
      return $cachedTier;
    }

    // Validate licence key format locally first
    // Format: DB-XXXX-XXXX-XXXX (starter) or DBP-XXXX-XXXX-XXXX (pro)
    if (str_starts_with($licenceKey, 'DBP-')) {
      $tier = 'pro';
    }
    elseif (str_starts_with($licenceKey, 'DB-')) {
      $tier = 'starter';
    }
    else {
      $tier = 'free';
    }

    // Cache the result for 1 hour
    \Drupal::configFactory()
      ->getEditable('drupalbridge.settings')
      ->set('licence_tier_cache', $tier)
      ->set('licence_cache_expiry', time() + 3600)
      ->save();

    $this->cachedTier = $tier;
    return $tier;
  }

  /**
   * Check if licence is valid (not free tier).
   */
  public function isValid(): bool {
    return $this->getTier() !== 'free';
  }

  /**
   * Check if specific feature is available on current tier.
   */
  public function canAccess(string $feature): bool {
    $tier = $this->getTier();

    $freeFeatures = [
      'contact_sync',
      'webform_handler',
      'contact_form_handler',
      'basic_field_mapping',
      'tracking_script',
      'user_sync',
      'sync_log_basic',
      'gdpr_webhook',
    ];

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

    // Free features — always available
    if (in_array($feature, $freeFeatures)) {
      return TRUE;
    }

    // Starter features — available on starter and pro
    if (in_array($feature, $starterFeatures)) {
      return in_array($tier, ['starter', 'pro']);
    }

    // Pro features — available on pro only
    if (in_array($feature, $proFeatures)) {
      return $tier === 'pro';
    }

    // Unknown feature — deny by default
    return FALSE;
  }

  /**
   * Get upgrade prompt message for a gated feature.
   */
  public function getUpgradePrompt(string $feature): string {
    $tier = $this->getTier();

    if ($tier === 'free') {
      return '🔒 This feature requires <strong>DrupalBridge Starter ($29/mo)</strong>.
        <a href="https://drupalbridge.com/pricing" target="_blank">Upgrade now →</a>';
    }

    if ($tier === 'starter') {
      return '🔒 This feature requires <strong>DrupalBridge Professional ($79/mo)</strong>.
        <a href="https://drupalbridge.com/pricing" target="_blank">Upgrade now →</a>';
    }

    return '';
  }

  /**
   * Check monthly sync limit for current tier.
   * Returns TRUE if syncs are still available.
   */
  public function checkSyncLimit(): bool {
    $tier   = $this->getTier();
    $limits = [
      'free'    => 500,
      'starter' => 5000,
      'pro'     => PHP_INT_MAX,
    ];

    $limit     = $limits[$tier] ?? 500;
    $usedSyncs = $this->getMonthlySyncCount();

    if ($usedSyncs >= $limit) {
      \Drupal::logger('drupalbridge')->warning(
        'Monthly sync limit reached. Tier: @tier Limit: @limit Used: @used',
        [
          '@tier'  => $tier,
          '@limit' => $limit,
          '@used'  => $usedSyncs,
        ]
      );
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get monthly sync count from sync log.
   */
  private function getMonthlySyncCount(): int {
    try {
      $startOfMonth = mktime(0, 0, 0, (int) date('n'), 1, (int) date('Y'));

      return (int) \Drupal::database()
        ->select('drupalbridge_sync_log', 'l')
        ->condition('l.status', 'synced')
        ->condition('l.created', $startOfMonth, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get licence key from config.
   */
  public function getLicenceKey(): string {
    return $this->configFactory
      ->get('drupalbridge.settings')
      ->get('licence_key') ?? '';
  }

  /**
   * Get tier label for display.
   */
  public function getTierLabel(): string {
    return match ($this->getTier()) {
      'starter' => 'Starter',
      'pro'     => 'Professional',
      default   => 'Free',
    };
  }

  /**
   * Get monthly sync usage summary.
   */
  public function getSyncUsage(): array {
    $tier   = $this->getTier();
    $limits = [
      'free'    => 500,
      'starter' => 5000,
      'pro'     => PHP_INT_MAX,
    ];

    $limit = $limits[$tier] ?? 500;
    $used  = $this->getMonthlySyncCount();

    return [
      'tier'       => $tier,
      'used'       => $used,
      'limit'      => $limit === PHP_INT_MAX ? 'Unlimited' : $limit,
      'remaining'  => $limit === PHP_INT_MAX
        ? 'Unlimited'
        : max(0, $limit - $used),
      'percentage' => $limit === PHP_INT_MAX
        ? 0
        : min(100, round(($used / $limit) * 100)),
    ];
  }

}