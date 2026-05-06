<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Manages form adapters and resolves the correct one for a given form.
 */
class FormAdapterManager {

  protected array $adapters = [];

  public function __construct() {
    $this->adapters = [
      new ContactFormAdapter(),
      new WebformAdapter(),
      new GenericFormAdapter(),
    ];
  }

  public function getAllAdapters(): array {
    return $this->adapters;
  }

  public function getAdapter(string $formId): FormAdapterInterface {
    foreach ($this->adapters as $adapter) {
      if ($adapter->supports($formId) && !($adapter instanceof GenericFormAdapter)) {
        return $adapter;
      }
    }

    return new GenericFormAdapter();
  }

  public function normalise(string $formId, array $rawData): array {
    $adapter = $this->getAdapter($formId);

    // 🔒 Tier check
    if (!$this->isAvailable($adapter->getName())) {

      \Drupal::messenger()->addWarning(
        $this->getUpgradePrompt($adapter->getName())
      );

      \Drupal::logger('drupalbridge')->warning(
        'Adapter blocked by plan: @adapter',
        ['@adapter' => $adapter->getName()]
      );

      return [];
    }

    \Drupal::logger('drupalbridge')->notice(
      'Using adapter: @adapter for form: @form',
      [
        '@adapter' => $adapter->getName(),
        '@form'    => $formId,
      ]
    );

    return $adapter->normalise($rawData);
  }

  public function isAvailable(string $adapterName): bool {

  $plan = \Drupal::config('drupalbridge.settings')->get('plan') ?? 'free';

  $freeAdapters = [
    'Drupal Contact Form',
    'Drupal Webform',
    'Generic Form API',
  ];

  $starterAdapters = [
    'Generic Form API',
  ];

  if ($plan === 'free') {
    return in_array($adapterName, $freeAdapters);
  }

  if ($plan === 'starter') {
    return in_array($adapterName, array_merge($freeAdapters, $starterAdapters));
  }

  return TRUE;
}

  public function getUpgradePrompt(string $adapterName): string {
    return "The {$adapterName} adapter requires Starter plan. Upgrade to unlock.";
  }
}