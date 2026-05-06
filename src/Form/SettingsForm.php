<?php

namespace Drupal\drupalbridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;
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
    $form['#tree'] = TRUE;
    $config = $this->config('drupalbridge.settings');

    // ----------------------------
    // OAUTH CLIENT CREDENTIALS
    // ----------------------------
    $form['oauth_client_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('HubSpot App Client ID'),
      '#description'   => $this->t('Found in your HubSpot Public App → Auth tab.'),
      '#default_value' => $config->get('oauth_client_id') ?? '',
    ];

    $form['oauth_client_secret'] = [
  '#type' => 'password',
  '#title' => $this->t('HubSpot App Client Secret'),
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
            Portal ID: <strong>' . $portalId . '</strong><br>
            Token expires: <strong>'
              . \Drupal::service('date.formatter')
                ->format($tokenExpires, 'short')
              . '</strong>
            &nbsp;
            <a href="/drupalbridge/oauth/authorize" class="button button--small">Reconnect</a>
            &nbsp;
            <a href="/drupalbridge/oauth/disconnect" class="button button--small">Disconnect</a>
          </div>
        ',
      ];
    }
    else {
      $form['connection_status'] = [
        '#type'   => 'markup',
        '#markup' => '
          <div class="messages messages--warning">
            ⚠️ <strong>Not connected to HubSpot.</strong>
            Enter Client ID and Secret above then click below.
          </div>
          <div style="margin: 15px 0;">
            <a href="/drupalbridge/oauth/authorize" class="button button--primary">
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
      '#title' => $this->t('HubSpot Tracking'),
      '#open'  => TRUE,
    ];

    $form['tracking']['tracking_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable tracking'),
      '#default_value' => $config->get('tracking_enabled') ?? 0,
    ];

    $form['tracking']['tracking_exclude_admin'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Exclude admin users'),
      '#default_value' => $config->get('tracking_exclude_admin') ?? 1,
    ];

    $form['tracking']['tracking_exclude_paths'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Exclude paths (regex)'),
      '#default_value' => $config->get('tracking_exclude_paths') ?? "/admin\n/user",
    ];

    // ----------------------------
    // USER SYNC
    // ----------------------------
    $form['user_sync'] = [
      '#type'  => 'details',
      '#title' => $this->t('User Sync'),
      '#open'  => TRUE,
    ];

    $form['user_sync']['user_sync_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable user sync'),
      '#default_value' => $config->get('user_sync_enabled') ?? 0,
    ];

    $form['user_sync']['user_sync_exclude_roles'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Exclude roles'),
      '#options'       => $this->getRoleOptions(),
      '#default_value' => $config->get('user_sync_exclude_roles') ?? [],
    ];

    $mapping      = $config->get('user_mappings') ?? [];
    $field_options = $this->getUserFieldOptions();

    $form['user_sync']['user_field_mapping'] = [
      '#type'  => 'details',
      '#title' => $this->t('User Field Mapping'),
      '#open'  => TRUE,
    ];

    $form['user_sync']['user_field_mapping']['firstname_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('First name field'),
      '#options'       => $field_options,
      '#empty_option'  => $this->t('- None -'),
      '#default_value' => $mapping['firstname'] ?? '',
    ];

    $form['user_sync']['user_field_mapping']['lastname_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Last name field'),
      '#options'       => $field_options,
      '#empty_option'  => $this->t('- None -'),
      '#default_value' => $mapping['lastname'] ?? '',
    ];

    // ----------------------------
    // GDPR
    // ----------------------------
    $form['gdpr'] = [
      '#type'  => 'details',
      '#title' => $this->t('GDPR Settings'),
      '#open'  => FALSE,
    ];

    $form['gdpr']['gdpr_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable GDPR legalConsentOptions'),
      '#default_value' => $config->get('gdpr_enabled') ?? 0,
    ];

    $form['gdpr']['gdpr_consent_text'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Consent Text'),
      '#default_value' => $config->get('gdpr_consent_text')
        ?? 'I agree to allow this company to store and process my personal data.',
      '#rows'          => 3,
    ];

    $form['gdpr']['gdpr_subscription_type_id'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Subscription Type ID'),
      '#default_value' => $config->get('gdpr_subscription_type_id') ?? '',
    ];

    // ----------------------------
    // COMMERCE
    // ----------------------------
    $form['commerce'] = [
      '#type'  => 'details',
      '#title' => $this->t('Commerce & Deals [Pro]'),
      '#open'  => FALSE,
    ];

    $form['commerce']['commerce_sync_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Commerce Sync'),
      '#default_value' => $config->get('commerce_sync_enabled') ?? 0,
    ];

    $form['commerce']['commerce_deal_stage'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Default Deal Stage'),
      '#default_value' => $config->get('commerce_deal_stage') ?? 'closedwon',
    ];

    $form['commerce']['commerce_pipeline'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Pipeline ID'),
      '#default_value' => $config->get('commerce_pipeline') ?? 'default',
    ];

    $form['commerce']['commerce_deal_trigger_forms'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('High-intent form IDs (deal triggers)'),
      '#default_value' => implode("\n",
        $config->get('commerce_deal_trigger_forms') ?? []
      ),
      '#rows'          => 4,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  $config = $this->configFactory->getEditable('drupalbridge.settings');
  $clientId = trim($form_state->getValue('oauth_client_id'));
  

  if (empty($clientId)) {
   \Drupal::messenger()->addError('Client ID cannot be empty.');
   return;
  }

  // Get existing secret
  $existingSecret = $config->get('oauth_client_secret');
  $newSecret = trim($form_state->getValue('oauth_client_secret'));
  $finalSecret = !empty($newSecret) ? $newSecret : $existingSecret;

  $userMappings = [
    'firstname' => $form_state->getValue('firstname_field'),
    'lastname'  => $form_state->getValue('lastname_field'),
  ];

  \Drupal::logger('debug')->notice('<pre>@data</pre>', [
  '@data' => print_r($form_state->getValues(), TRUE),
]);

  $config
    ->set('oauth_client_id', trim($form_state->getValue('oauth_client_id')))
    ->set('oauth_client_secret', $finalSecret)
    ->set('sync_mode', $form_state->getValue('sync_mode'))
    ->set('tracking_enabled', $form_state->getValue('tracking_enabled'))
    ->set('tracking_exclude_admin', $form_state->getValue('tracking_exclude_admin'))
    ->set('tracking_exclude_paths', $form_state->getValue('tracking_exclude_paths'))
    ->set('user_sync_enabled', $form_state->getValue('user_sync_enabled'))
    ->set('user_sync_exclude_roles',
      array_filter($form_state->getValue('user_sync_exclude_roles') ?? [])
    )
    ->set('user_mappings', $userMappings)
    ->set('gdpr_enabled', $form_state->getValue('gdpr_enabled'))
    ->set('gdpr_consent_text', $form_state->getValue('gdpr_consent_text'))
    ->set('gdpr_subscription_type_id', $form_state->getValue('gdpr_subscription_type_id'))
    ->set('commerce_sync_enabled', $form_state->getValue('commerce_sync_enabled'))
    ->set('commerce_deal_stage', $form_state->getValue('commerce_deal_stage'))
    ->set('commerce_pipeline', $form_state->getValue('commerce_pipeline'))
    ->set('commerce_deal_trigger_forms',
      array_filter(array_map('trim',
        explode("\n", $form_state->getValue('commerce_deal_trigger_forms') ?? '')
      ))
    )
    ->save();
    

  \Drupal::messenger()->addStatus($this->t('Settings saved successfully.'));
}

}