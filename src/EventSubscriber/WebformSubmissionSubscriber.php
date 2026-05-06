<?php

namespace Drupal\drupalbridge\EventSubscriber;

use Drupal\drupalbridge\CommerceSync;
use Drupal\drupalbridge\HubSpotClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\webform\Event\WebformSubmissionInsertEvent;

class WebformSubmissionSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected HubSpotClient $hubspotClient,
    protected CommerceSync $commerceSync,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      WebformSubmissionInsertEvent::class => 'onSubmit',
    ];
  }

  public function onSubmit(WebformSubmissionInsertEvent $event): void {
    $submission = $event->getWebformSubmission();
    $webformId  = $submission->getWebform()->id();
    $data       = $submission->getData();

    $settingsConfig = $this->configFactory->get('drupalbridge.settings');
    $mappingConfig  = $this->configFactory->get('drupalbridge.field_mapping');

    $triggerForms = $settingsConfig->get('commerce_deal_trigger_forms') ?? [];
    $allDealConfigs = $mappingConfig->get('deal_config') ?? [];
    $dealConfig     = $allDealConfigs[$webformId] ?? [];

    $email = $this->resolveEmail($data, $mappingConfig);

    if (empty($email)) {
      return;
    }

    // --- Always upsert contact ---
    $this->upsertContact($data, $email, $mappingConfig);

    // --- If this is a high-intent form, create deal + ticket ---
    if (in_array($webformId, $triggerForms) && !empty($dealConfig['enabled'])) {
      $dealId = $this->commerceSync->createDeal([
        'label'    => $dealConfig['title'] ?: ('Form submission: ' . $webformId),
        'total'    => $dealConfig['amount'] ?? 0,
        'stage'    => $dealConfig['stage']  ?? 'appointmentscheduled',
        'pipeline' => $dealConfig['pipeline'] ?? 'default',
        'email'    => $email,
        'items'    => $dealConfig['line_items'] ?? [],
        'order_id' => $submission->id(),
      ]);

      // Ticket
      $this->commerceSync->createTicket([
        'subject'  => 'Form enquiry: ' . $webformId,
        'content'  => 'Submitted via webform: ' . $webformId . ' (submission #' . $submission->id() . ')',
        'priority' => 'MEDIUM',
        'email'    => $email,
      ]);
    }
  }

  /**
   * Resolve the email from submitted data using field mappings.
   */
  private function resolveEmail(array $data, $mappingConfig): ?string {
    $mappings = $mappingConfig->get('mappings') ?? [];

    foreach ($mappings as $safeKey => $hubspotProp) {
      if ($hubspotProp === 'email') {
        $drupalKey = str_replace('__', '.', $safeKey);
        if (!empty($data[$drupalKey])) {
          return $data[$drupalKey];
        }
      }
    }

    // Fallback — check common keys directly
    foreach (['email', 'mail', 'email_address'] as $key) {
      if (!empty($data[$key])) {
        return $data[$key];
      }
    }

    return NULL;
  }

  /**
   * Upsert contact to HubSpot using field mappings.
   */
  private function upsertContact(array $data, string $email, $mappingConfig): void {
    $mappings   = $mappingConfig->get('mappings') ?? [];
    $properties = ['email' => $email];

    foreach ($mappings as $safeKey => $hubspotProp) {
      $drupalKey = str_replace('__', '.', $safeKey);
      $value     = $data[$drupalKey] ?? NULL;

      // Handle composite address fields (webform_address)
      if (str_contains($drupalKey, '.')) {
        [$parent, $sub] = explode('.', $drupalKey, 2);
        $value = $data[$parent][$sub] ?? NULL;
      }

      if (!empty($value)) {
        $properties[$hubspotProp] = $value;
      }
    }

    try {
      $this->hubspotClient->upsertContact($properties);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Webform contact upsert failed: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }
}