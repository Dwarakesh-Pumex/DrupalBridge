<?php

namespace Drupal\drupalbridge\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Shows status of all registered form adapters.
 */
class AdapterStatusController extends ControllerBase {

  public function index(): array {
  $manager  = \Drupal::service('drupalbridge.form_adapter_manager');
  $adapters = $manager->getAllAdapters();

  $rows = [];

  foreach ($adapters as $adapter) {

    $name = $adapter->getName();

    // Decide tier
    $requiredTier = ($name === 'Generic Form API') ? 'Starter' : 'Free';

    // Check if available
    $available = $manager->isAvailable($name);

    $status = $available
      ? ['data' => '✅ Available', 'style' => 'color: green']
      : ['data' => '🔒 Starter Required', 'style' => 'color: orange'];

    $rows[] = [
      $name,
      $requiredTier,
      $status,
    ];
  }

  $build['table'] = [
    '#type'   => 'table',
    '#header' => [
      $this->t('Adapter'),
      $this->t('Required Tier'),
      $this->t('Status'),
    ],
    '#rows'   => $rows,
  ];

  // Upgrade message
  $licenceService = \Drupal::service('drupalbridge.licence_service');
  if (!$licenceService->isValid()) {
    $build['upgrade'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="messages messages--warning">
        <strong>You are on the Free tier.</strong>
        Upgrade to Starter to unlock all form integrations.
        <a href="https://drupalbridge.com/upgrade">Upgrade now →</a>
      </div>',
    ];
  }

  return $build;
}

}