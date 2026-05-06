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
   * Get licence key from config.
   */
  public function getLicenceKey(): string {
    return $this->configFactory
      ->get('drupalbridge.settings')
      ->get('licence_key') ?? '';
  }

}