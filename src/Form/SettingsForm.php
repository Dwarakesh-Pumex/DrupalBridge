<?php

namespace Drupal\drupalbridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\Role;

/**
 * DrupalBridge admin settings form.
 */
class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['drupalbridge.settings'];
  }

  public function getFormId(): string {
    return 'drupalbridge_settings_form';
  }

  private function getUserFieldOptions(): array {
    $fields  = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('user', 'user');
    $options = [];

    foreach ($fields as $field_name => $field) {
      if (in_array($field_name, [
        'uid', 'uuid', 'langcode', 'preferred_langcode',
        'preferred_admin_langcode', 'roles', 'default_langcode',
      ])) {
        continue;
      }
      $options[$field_name] = $field->getLabel() . " ($field_name)";
    }

    return $options;
  }

  private function getRoleOptions(): array {
    $roles   = Role::loadMultiple();
    $options = [];
    foreach ($roles as $role) {
      $options[$role->id()] = $role->label();
    }
    return $options;
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // NO #tree = TRUE here — keeps all values flat and accessible
    $config = $this->config('drupalbridge.settings');

    // ----------------------------
    // LICENCE STATUS
    // ----------------------------
    $licenceService = \Drupal::service('drupalbridge.licence_service');
    $currentTier    = $licenceService->getTier();
    $tierColors     = [
      'free'    => '#666',
      'starter' => '#0077cc',
      'pro'     => '#cc7700',
    ];
    $tierColor = $tierColors[$currentTier] ?? '#666';

    $form['licence_status'] = [
      '#type'   => 'markup',
      '#markup' => '
        <div style="padding: 12px; background: #f8f9fa;
          border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 20px;">
          <strong>DrupalBridge</strong> &nbsp;|&nbsp;
          Current tier: <strong style="color: ' . $tierColor . ';">'
            . strtoupper($currentTier) . '</strong>
          ' . ($currentTier === 'free' ? '
          &nbsp;&nbsp;
          <a href="https://drupalbridge.com/pricing" target="_blank"
            class="button button--small button--primary">
            Upgrade to Starter →
          </a>' : '') . '
          <br><small style="color: #666; margin-top: 5px; display: block;">
            <a href="https://drupalbridge.com/privacy" target="_blank">Privacy Policy</a> &nbsp;|&nbsp;
            <a href="https://drupalbridge.com/terms" target="_blank">Terms of Service</a> &nbsp;|&nbsp;
            <a href="https://drupalbridge.com/support" target="_blank">Support</a> &nbsp;|&nbsp;
            <a href="https://drupalbridge.com/docs" target="_blank">Documentation</a>
          </small>
        </div>
      ',
    ];

    // ----------------------------
    // LICENCE KEY
    // ----------------------------
    $form['licence_key'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Licence Key'),
      '#description'   => $this->t(
        'Enter your DrupalBridge licence key to unlock Starter or Pro features.
        Purchase at <a href="https://drupalbridge.com/pricing" target="_blank">drupalbridge.com/pricing</a>.'
      ),
      '#default_value' => $config->get('licence_key') ?? '',
      '#placeholder'   => 'DB-XXXX-YYYY-ZZZZ-AAAA',
    ];

    // ----------------------------
    // HUBSPOT OAUTH CREDENTIALS
    // ----------------------------
    $form['oauth_client_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('HubSpot App Client ID'),
      '#description'   => $this->t(
        'Found in your HubSpot Public App → Auth tab at
        <a href="https://app.hubspot.com/developer" target="_blank">
        app.hubspot.com/developer</a>.'
      ),
      '#default_value' => $config->get('oauth_client_id') ?? '',
    ];

    $form['oauth_client_secret'] = [
      '#type'        => 'password',
      '#title'       => $this->t('HubSpot App Client Secret'),
      '#description' => $this->t('Leave empty to keep existing secret.'),
      '#default_value' => '',
    ];

    // ----------------------------
    // CONNECTION STATUS
    // ----------------------------
    $apiToken     = $config->get('api_token');
    $portalId     = $config->get('portal_id');
    $tokenExpires = $config->get('oauth_token_expires') ?? 0;
    $isConnected  = !empty($apiToken) && !empty($portalId);
    $isExpired    = $tokenExpires > 0 && time() > $tokenExpires;

    if ($isConnected && !$isExpired) {
      $form['connection_status'] = [
        '#type'   => 'markup',
        '#markup' => '
          <div class="messages messages--status">
            ✅ <strong>Connected to HubSpot</strong><br>
            Portal ID: <strong>' . htmlspecialchars($portalId) . '</strong><br>
            Token expires: <strong>'
              . \Drupal::service('date.formatter')
                ->format($tokenExpires, 'short')
              . '</strong>
            &nbsp;
            <a href="/drupalbridge/oauth/authorize"
              class="button button--small">Reconnect</a>
            &nbsp;
            <a href="/drupalbridge/oauth/disconnect"
              class="button button--small">Disconnect</a>
          </div>
        ',
      ];
    }
    else {
      $form['connection_status'] = [
        '#type'   => 'markup',
        '#markup' => '
          <div class="messages messages--warning">
            ⚠️ <strong>Not connected to HubSpot.</strong><br>
            Enter your Client ID and Secret above and save,
            then click Connect to HubSpot.
          </div>
          <div style="margin: 15px 0;">
            <a href="/drupalbridge/oauth/authorize"
              class="button button--primary">
              🔗 Connect to HubSpot
            </a>
          </div>
        ',
      ];
    }

    // ----------------------------
    // SYNC MODE
    // ----------------------------
    $form['sync_mode'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Sync Mode'),
      '#description'   => $this->t(
        '<strong>Real-time:</strong> Contacts sync immediately on form submission.<br>
        <strong>Queued:</strong> Submissions sync via cron — better for high-volume sites.'
      ),
      '#options'       => [
        'realtime' => $this->t('Real-time'),
        'queued'   => $this->t('Queued (via cron)'),
      ],
      '#default_value' => $config->get('sync_mode') ?? 'realtime',
    ];

    // ----------------------------
    // TRACKING
    // ----------------------------
    $form['tracking'] = [
      '#type'  => 'details',
      '#title' => $this->t('HubSpot Tracking Script'),
      '#open'  => FALSE,
    ];

    $form['tracking']['tracking_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable HubSpot tracking script'),
      '#description'   => $this->t(
        'Injects the HubSpot tracking script on all pages for analytics and contact identification.'
      ),
      '#default_value' => $config->get('tracking_enabled') ?? 0,
    ];

    $form['tracking']['tracking_exclude_admin'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Exclude admin users from tracking'),
      '#default_value' => $config->get('tracking_exclude_admin') ?? 1,
    ];

    $form['tracking']['tracking_exclude_paths'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Exclude paths (one regex per line)'),
      '#description'   => $this->t('Example: ^/admin'),
      '#default_value' => $config->get('tracking_exclude_paths') ?? "/admin\n/user",
      '#rows'          => 4,
    ];

    // ----------------------------
    // USER SYNC
    // ----------------------------
    $form['user_sync'] = [
      '#type'  => 'details',
      '#title' => $this->t('User Registration Sync'),
      '#open'  => FALSE,
    ];

    $form['user_sync']['user_sync_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Sync Drupal users to HubSpot contacts'),
      '#description'   => $this->t(
        'When users register, update their profile, or are deleted,
        sync changes to HubSpot automatically.'
      ),
      '#default_value' => $config->get('user_sync_enabled') ?? 0,
    ];

    $form['user_sync']['user_sync_exclude_roles'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Exclude these roles from sync'),
      '#options'       => $this->getRoleOptions(),
      '#default_value' => $config->get('user_sync_exclude_roles') ?? [],
    ];

    $mapping       = $config->get('user_mappings') ?? [];
    $field_options = $this->getUserFieldOptions();

    $form['user_sync']['firstname_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('First name field'),
      '#options'       => $field_options,
      '#empty_option'  => $this->t('- None -'),
      '#default_value' => $mapping['firstname'] ?? '',
    ];

    $form['user_sync']['lastname_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Last name field'),
      '#options'       => $field_options,
      '#empty_option'  => $this->t('- None -'),
      '#default_value' => $mapping['lastname'] ?? '',
    ];

    // ----------------------------
    // GDPR [Starter]
    // ----------------------------
    $form['gdpr'] = [
      '#type'  => 'details',
      '#title' => $this->t('GDPR Settings [Starter]'),
      '#open'  => FALSE,
    ];

    if (!$licenceService->canAccess('gdpr_consent')) {
      $form['gdpr']['upgrade_notice'] = [
        '#type'   => 'markup',
        '#markup' => '<div class="messages messages--warning">'
          . $licenceService->getUpgradePrompt('gdpr_consent')
          . '</div>',
      ];
    }

    $form['gdpr']['gdpr_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable GDPR legalConsentOptions'),
      '#description'   => $this->t(
        'Required for HubSpot portals with GDPR enabled.
        Includes consent data in every contact submission.'
      ),
      '#default_value' => $config->get('gdpr_enabled') ?? 0,
      '#disabled'      => !$licenceService->canAccess('gdpr_consent'),
    ];

    $form['gdpr']['gdpr_consent_text'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Consent Text'),
      '#default_value' => $config->get('gdpr_consent_text')
        ?? 'I agree to allow this company to store and process my personal data.',
      '#rows'          => 3,
      '#disabled'      => !$licenceService->canAccess('gdpr_consent'),
    ];

    $form['gdpr']['gdpr_subscription_type_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Subscription Type ID'),
      '#description'   => $this->t(
        'Optional. Found in HubSpot → Settings → Marketing → Email → Subscription Types.'
      ),
      '#default_value' => $config->get('gdpr_subscription_type_id') ?? '',
      '#disabled'      => !$licenceService->canAccess('gdpr_consent'),
    ];

    // ----------------------------
    // COMMERCE [Pro]
    // ----------------------------
    $form['commerce'] = [
      '#type'  => 'details',
      '#title' => $this->t('Commerce & Deals [Professional]'),
      '#open'  => FALSE,
    ];

    if (!$licenceService->canAccess('commerce_sync')) {
      $form['commerce']['upgrade_notice'] = [
        '#type'   => 'markup',
        '#markup' => '<div class="messages messages--warning">'
          . $licenceService->getUpgradePrompt('commerce_sync')
          . '</div>',
      ];
    }

    $form['commerce']['commerce_sync_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Commerce → Deals sync'),
      '#description'   => $this->t(
        'Creates HubSpot Deals automatically when Drupal Commerce orders are paid.'
      ),
      '#default_value' => $config->get('commerce_sync_enabled') ?? 0,
      '#disabled'      => !$licenceService->canAccess('commerce_sync'),
    ];

    $form['commerce']['commerce_deal_stage'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Default Deal Stage'),
      '#description'   => $this->t('HubSpot deal stage ID. Default: closedwon'),
      '#default_value' => $config->get('commerce_deal_stage') ?? 'closedwon',
      '#disabled'      => !$licenceService->canAccess('commerce_sync'),
    ];

    $form['commerce']['commerce_pipeline'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Pipeline ID'),
      '#description'   => $this->t('HubSpot pipeline ID. Default: default'),
      '#default_value' => $config->get('commerce_pipeline') ?? 'default',
      '#disabled'      => !$licenceService->canAccess('commerce_sync'),
    ];

    $form['commerce']['commerce_deal_trigger_forms'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('High-intent form IDs (deal triggers)'),
      '#description'   => $this->t(
        'One form ID per line. Submissions from these forms create HubSpot Deals automatically.'
      ),
      '#default_value' => implode("\n",
        $config->get('commerce_deal_trigger_forms') ?? []
      ),
      '#rows'          => 4,
      '#disabled'      => !$licenceService->canAccess('commerce_sync'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('drupalbridge.settings');

    // OAuth credentials
    $clientId  = trim($form_state->getValue('oauth_client_id') ?? '');
    $newSecret = trim($form_state->getValue('oauth_client_secret') ?? '');
    $finalSecret = !empty($newSecret)
      ? $newSecret
      : $config->get('oauth_client_secret');

    // User field mappings — now flat under user_sync details
    $userMappings = [
      'firstname' => $form_state->getValue(['user_sync', 'firstname_field']) ?? '',
      'lastname'  => $form_state->getValue(['user_sync', 'lastname_field']) ?? '',
    ];

    // Tracking values — nested under tracking details
    $trackingEnabled      = $form_state->getValue(['tracking', 'tracking_enabled']) ?? 0;
    $trackingExcludeAdmin = $form_state->getValue(['tracking', 'tracking_exclude_admin']) ?? 1;
    $trackingExcludePaths = $form_state->getValue(['tracking', 'tracking_exclude_paths']) ?? '';

    // User sync values
    $userSyncEnabled      = $form_state->getValue(['user_sync', 'user_sync_enabled']) ?? 0;
    $userSyncExcludeRoles = $form_state->getValue(['user_sync', 'user_sync_exclude_roles']) ?? [];

    // GDPR values
    $gdprEnabled            = $form_state->getValue(['gdpr', 'gdpr_enabled']) ?? 0;
    $gdprConsentText        = $form_state->getValue(['gdpr', 'gdpr_consent_text']) ?? '';
    $gdprSubscriptionTypeId = $form_state->getValue(['gdpr', 'gdpr_subscription_type_id']) ?? '';

    // Commerce values
    $commerceSyncEnabled      = $form_state->getValue(['commerce', 'commerce_sync_enabled']) ?? 0;
    $commerceDealStage        = $form_state->getValue(['commerce', 'commerce_deal_stage']) ?? 'closedwon';
    $commercePipeline         = $form_state->getValue(['commerce', 'commerce_pipeline']) ?? 'default';
    $commerceDealTriggerForms = array_filter(array_map('trim',
      explode("\n", $form_state->getValue(['commerce', 'commerce_deal_trigger_forms']) ?? '')
    ));

    $config
      ->set('oauth_client_id',    $clientId)
      ->set('oauth_client_secret', $finalSecret)
      ->set('licence_key',        trim($form_state->getValue('licence_key') ?? ''))
      ->set('sync_mode',          $form_state->getValue('sync_mode') ?? 'realtime')
      ->set('tracking_enabled',   $trackingEnabled)
      ->set('tracking_exclude_admin', $trackingExcludeAdmin)
      ->set('tracking_exclude_paths', $trackingExcludePaths)
      ->set('user_sync_enabled',  $userSyncEnabled)
      ->set('user_sync_exclude_roles',
        array_filter($userSyncExcludeRoles)
      )
      ->set('user_mappings',      $userMappings)
      ->set('gdpr_enabled',       $gdprEnabled)
      ->set('gdpr_consent_text',  $gdprConsentText)
      ->set('gdpr_subscription_type_id', $gdprSubscriptionTypeId)
      ->set('commerce_sync_enabled',     $commerceSyncEnabled)
      ->set('commerce_deal_stage',       $commerceDealStage)
      ->set('commerce_pipeline',         $commercePipeline)
      ->set('commerce_deal_trigger_forms', $commerceDealTriggerForms)
      ->save();

    \Drupal::messenger()->addStatus(
      $this->t('DrupalBridge settings saved successfully.')
    );

    parent::submitForm($form, $form_state);
  }

}