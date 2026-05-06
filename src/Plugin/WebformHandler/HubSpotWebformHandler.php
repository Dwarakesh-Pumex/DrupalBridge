<?php

namespace Drupal\drupalbridge\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\drupalbridge\FormSyncService;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * HubSpot Webform Handler.
 *
 * @WebformHandler(
 *   id = "drupalbridge_hubspot",
 *   label = @Translation("DrupalBridge HubSpot"),
 *   category = @Translation("CRM"),
 *   description = @Translation("Syncs webform submissions to HubSpot CRM."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class HubSpotWebformHandler extends WebformHandlerBase {

  protected FormSyncService $formSyncService;

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
    $instance->formSyncService = $container->get('drupalbridge.form_sync');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(
  WebformSubmissionInterface $webform_submission,
  $update = TRUE
): void {

  static $processed = [];

  $submissionId = $webform_submission->id();

  if (isset($processed[$submissionId])) {
    return;
  }

  $processed[$submissionId] = TRUE;

  $values    = $webform_submission->getData();
  $webformId = $webform_submission->getWebform()->id();

  \Drupal::logger('drupalbridge')->notice(
    'DrupalBridge Webform handler fired. Webform: @webform',
    ['@webform' => $webformId]
  );

  $contactData = $this->formSyncService->mapFormValues($values);

  if (empty($contactData['email'])) {
    \Drupal::logger('drupalbridge')->warning(
      'Webform sync skipped — no email found. Webform: @webform',
      ['@webform' => $webformId]
    );
    return;
  }

  try {
    // -----------------------
    // 1. Sync contact
    // -----------------------
    $this->formSyncService->createOrUpdateContact($contactData);

    // -----------------------
    // 2. Deal trigger check
    // -----------------------
    $config = \Drupal::config('drupalbridge.settings');
    $triggerForms = $config->get('commerce_deal_trigger_forms') ?? [];

    if (in_array($webformId, $triggerForms)) {

      $dealSettings = $config->get('deal_form_settings.' . $webformId) ?? [];

      if (!empty($dealSettings)) {

        $commerceSync = \Drupal::service('drupalbridge.commerce_sync');

        $dealData = $this->buildDealData($dealSettings, $contactData, $values);

        $dealId = $commerceSync->createDeal($dealData);

        if ($dealId) {
          \Drupal::logger('drupalbridge')->notice(
            'Deal created from form @form: @deal',
            ['@form' => $webformId, '@deal' => $dealId]
          );
        }
      }
    }

    \Drupal::messenger()->addStatus(
      t('Thank you! Your details have been successfully saved.')
    );

    // After successful contact sync add this:
$allDealConfigs = \Drupal::config('drupalbridge.field_mapping')
  ->get('deal_config') ?? [];

$dealConfig = $allDealConfigs[$webformId] ?? NULL;

if (!empty($dealConfig) && !empty($dealConfig['enabled'])) {
  $licenceService = \Drupal::service('drupalbridge.licence_service');

  if ($licenceService->isValid()) {
    $commerceSync = \Drupal::service('drupalbridge.commerce_sync');

    // Build deal title — replace email token
    $dealTitle = str_replace(
      '[contact:email]',
      $contactData['email'] ?? '',
      $dealConfig['title'] ?? 'Form submission'
    );

    $dealData = [
      'label'    => $dealTitle,
      'email'    => $contactData['email'] ?? '',
      'total'    => $dealConfig['amount'] ?? 0,
      'stage'    => $dealConfig['stage'] ?? 'appointmentscheduled',
      'pipeline' => $dealConfig['pipeline'] ?? 'default',
      'items'    => $dealConfig['line_items'] ?? [],
    ];

    $dealId = $commerceSync->createDeal($dealData);

    if ($dealId) {
      \Drupal::logger('drupalbridge')->notice(
        'Deal @deal created from webform @webform submission.',
        ['@deal' => $dealId, '@webform' => $webformId]
      );
    }
  }
  else {
    \Drupal::logger('drupalbridge')->notice(
      'Deal creation skipped — Pro tier required.'
    );
  }
}

  }
  catch (\Exception $e) {

  \Drupal::logger('drupalbridge')->error(
    'Webform sync failed: @error',
    ['@error' => $e->getMessage()]
  );

  \Drupal::messenger()->addWarning(
    t('Submission saved, but sync failed. Will retry.')
  );

  // 🔥 ADD THIS BLOCK
  try {
    $queue = \Drupal::queue('drupalbridge_sync_worker');

    $queue->createItem([
      'contact_data' => $contactData,  // ✅ CRITICAL FIX
      'raw_values'   => $values,       // optional fallback
      'attempts'     => 0,
      'source'       => 'webform',
      'webform_id'   => $webformId,
    ]);

    \Drupal::logger('drupalbridge')->notice(
      'Submission queued for retry: @email',
      ['@email' => $contactData['email'] ?? 'unknown']
    );

  }
  catch (\Exception $queueError) {
    \Drupal::logger('drupalbridge')->error(
      'Failed to queue submission: @error',
      ['@error' => $queueError->getMessage()]
    );
  }
}
}

  /**
   * Map webform values to HubSpot contact properties.
   */

  /**
   * Fallback field mapping.
   */
  private function guessFieldMapping(array $values): array {
    $fieldGuesses = [
      'email'     => ['email', 'email_address', 'your_email'],
      'firstname' => ['firstname', 'first_name', 'name'],
      'lastname'  => ['lastname', 'last_name'],
      'phone'     => ['phone', 'phone_number', 'telephone', 'mobile'],
    ];

    $contactData = [];

    foreach ($fieldGuesses as $hubspotProperty => $candidates) {
      foreach ($candidates as $field) {
        if (!empty($values[$field])) {
          $contactData[$hubspotProperty] = $values[$field];
          break;
        }
      }
    }

    return $contactData;
  }

  /**
   * Handler configuration form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['info'] = [
    '#type'   => 'markup',
    '#markup' => '
      <div class="messages messages--info">
        <strong>DrupalBridge HubSpot Handler</strong><br>
        This handler syncs form submissions to HubSpot as contacts.<br><br>
        <strong>Setup checklist:</strong>
        <ol>
          <li> API token saved in <a href="/admin/config/services/drupalbridge">DrupalBridge Settings</a></li>
          <li> Field mappings configured in <a href="/admin/config/services/drupalbridge/field-mapping">Field Mapping</a></li>
          <li> At minimum: email field mapped to HubSpot Email property</li>
        </ol>
        <strong>Common issue:</strong> If contacts are not appearing in HubSpot check the
        <a href="/admin/reports/dblog">Recent Log Messages</a> for drupalbridge errors.
      </div>
    ',
  ];

    return $form;
  }

}