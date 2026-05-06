<?php

namespace Drupal\drupalbridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupalbridge\HubSpotClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FieldMappingForm extends ConfigFormBase {

  protected HubSpotClient $hubspotClient;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->hubspotClient = $container->get('drupalbridge.hubspot_client');
    return $instance;
  }

  protected function getEditableConfigNames(): array {
    return ['drupalbridge.field_mapping'];
  }

  public function getFormId(): string {
    return 'drupalbridge_field_mapping_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupalbridge.field_mapping');

    $settingsConfig = \Drupal::config('drupalbridge.settings');
    $allowedForms = $settingsConfig->get('commerce_deal_trigger_forms') ?? [];

    $savedMappings     = $config->get('mappings') ?? [];
    $savedUserMappings = $config->get('user_mappings') ?? [];

    $drupalFields      = $this->getDrupalContactFields();
    $hubspotProperties = $this->getHubSpotPropertyOptions();
    $userFields        = $this->getDrupalUserFields();

    $form['intro'] = [
  '#type'   => 'markup',
  '#markup' => '
    <div class="messages messages--info">
      <strong>How field mapping works:</strong>
      <ul>
        <li>Select a <strong>Drupal field</strong> from your webform on the left</li>
        <li>Select the matching <strong>HubSpot property</strong> on the right</li>
        <li>At minimum you must map your email field to <strong>Email</strong></li>
        <li>Address fields expand automatically into sub-fields (street, city, state, ZIP, country)</li>
        <li>If the HubSpot property dropdown is empty check that your API token is saved in Settings</li>
      </ul>
      <strong>Common issue:</strong> If a field is not appearing in the Drupal dropdown make sure it is added to your webform first.
    </div>
  ',
];

    // ----------------------------
    // WEBFORM MAPPINGS
    // ----------------------------
    $form['webform_section'] = [
      '#type'  => 'details',
      '#title' => $this->t('Webform Field Mappings'),
      '#open'  => TRUE,
    ];

    $form['webform_section']['mappings_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'mappings-wrapper'],
      '#tree'       => TRUE,
    ];

    if ($form_state->get('mappings') === NULL) {
      $rows = [];
      foreach ($savedMappings as $safeKey => $hubspotProperty) {
        $rows[] = [
          'drupal'  => str_replace('__', '.', $safeKey),
          'hubspot' => $hubspotProperty,
        ];
      }
      $form_state->set('mappings', $rows);
    }

    $mappings = $form_state->get('mappings') ?? [];

    foreach ($mappings as $index => $row) {
      $form['webform_section']['mappings_wrapper'][$index] = [
        '#type' => 'fieldset',
      ];

      $form['webform_section']['mappings_wrapper'][$index]['drupal_field'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Drupal Field'),
        '#options'       => $drupalFields,
        '#empty_option'  => $this->t('-- Select --'),
        '#default_value' => $row['drupal'] ?? '',
      ];

      $form['webform_section']['mappings_wrapper'][$index]['hubspot_property'] = [
        '#type'          => 'select',
        '#title'         => $this->t('HubSpot Property'),
        '#options'       => $hubspotProperties,
        '#empty_option'  => $this->t('-- Select --'),
        '#default_value' => $row['hubspot'] ?? '',
      ];

      $form['webform_section']['mappings_wrapper'][$index]['remove'] = [
        '#type'   => 'submit',
        '#value'  => $this->t('Remove'),
        '#name'   => 'remove_' . $index,
        '#submit' => ['::removeRow'],
        '#ajax'   => [
          'callback' => '::ajaxCallback',
          'wrapper'  => 'mappings-wrapper',
        ],
      ];
    }

    $form['webform_section']['add_row'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Add Mapping'),
      '#submit' => ['::addRow'],
      '#ajax'   => [
        'callback' => '::ajaxCallback',
        'wrapper'  => 'mappings-wrapper',
      ],
    ];

    // ----------------------------
    // USER FIELD MAPPINGS
    // ----------------------------
    $form['user_section'] = [
      '#type'  => 'details',
      '#title' => $this->t('User Field Mappings'),
      '#open'  => TRUE,
    ];

    $form['user_section']['user_mappings_wrapper'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    if ($form_state->get('user_mappings') === NULL) {
      $rows = [];
      foreach ($savedUserMappings as $drupal => $hubspot) {
        $rows[] = [
          'drupal'  => $drupal,
          'hubspot' => $hubspot,
        ];
      }
      $form_state->set('user_mappings', $rows);
    }

    $userMappings = $form_state->get('user_mappings') ?? [];

    foreach ($userMappings as $index => $row) {
      $form['user_section']['user_mappings_wrapper'][$index] = [
        '#type' => 'fieldset',
      ];

      $form['user_section']['user_mappings_wrapper'][$index]['drupal_field'] = [
        '#type'          => 'select',
        '#title'         => $this->t('User Field'),
        '#options'       => $userFields,
        '#empty_option'  => $this->t('-- Select --'),
        '#default_value' => $row['drupal'] ?? '',
      ];

      $form['user_section']['user_mappings_wrapper'][$index]['hubspot_property'] = [
        '#type'          => 'select',
        '#title'         => $this->t('HubSpot Property'),
        '#options'       => $hubspotProperties,
        '#empty_option'  => $this->t('-- Select --'),
        '#default_value' => $row['hubspot'] ?? '',
      ];

      $form['user_section']['user_mappings_wrapper'][$index]['remove_user'] = [
        '#type'   => 'submit',
        '#value'  => $this->t('Remove'),
        '#name'   => 'remove_user_' . $index,
        '#submit' => ['::removeUserRow'],
        '#ajax'   => [
          'callback' => '::userAjaxCallback',
          'wrapper'  => 'user-mappings-wrapper',
        ],
      ];
    }

    $form['user_section']['user_mappings_wrapper']['#attributes']['id'] = 'user-mappings-wrapper';

    $form['user_section']['add_user_row'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Add User Mapping'),
      '#submit' => ['::addUserRow'],
      '#ajax'   => [
        'callback' => '::userAjaxCallback',
        'wrapper'  => 'user-mappings-wrapper',
      ],
    ];

     // ----------------------------
// DEAL CONFIGURATION
// ----------------------------
$webformOptions = [];
if (!is_array($webformOptions)) {
  $webformOptions = [];
}
$webforms = \Drupal::entityTypeManager()
  ->getStorage('webform')
  ->loadMultiple();

foreach ($webforms as $webform) {
  if (in_array($webform->id(), $allowedForms)) {
    $webformOptions[$webform->id()] = $webform->label();
  }
}

// Selected webform (safe handling)
// Resolve selected webform
$selectedWebform = $form_state->get('target_webform');

if (!$selectedWebform) {
  $selectedWebform = $form_state->getValue('target_webform');
}

if (!$selectedWebform || !isset($webformOptions[$selectedWebform])) {
  $selectedWebform = !empty($webformOptions) ? array_key_first($webformOptions) : NULL;
}

// Load saved configs FIRST so $savedDealConfig is available below
$allDealConfigs  = $config->get('deal_config') ?? [];
$savedDealConfig = $selectedWebform ? ($allDealConfigs[$selectedWebform] ?? []) : [];

// Detect webform switch → reset per-form state
$previousWebform = $form_state->get('loaded_webform');

if ($previousWebform !== $selectedWebform) {
  $form_state->set('line_items',       $savedDealConfig['line_items'] ?? []);
  $form_state->set('loaded_webform',   $selectedWebform);
  $form_state->setValue('deal_title',    $savedDealConfig['title']    ?? '');
  $form_state->setValue('deal_amount',   $savedDealConfig['amount']   ?? 0);
  $form_state->setValue('deal_stage',    $savedDealConfig['stage']    ?? 'appointmentscheduled');
  $form_state->setValue('deal_pipeline', $savedDealConfig['pipeline'] ?? 'default');
}

// Persist it
$form_state->set('target_webform', $selectedWebform);

// Wrapper (AJAX target)
$form['deal_config_wrapper'] = [
  '#type' => 'container',
  '#attributes' => ['id' => 'deal-config-wrapper'],
  '#tree' => TRUE,
];

if (empty($webformOptions)) {

  $form['deal_config_wrapper']['empty'] = [
    '#markup' => '<p>No eligible forms configured.</p>',
  ];

}
else {

  // ----------------------------
  // Webform selector
  // ----------------------------
  $form['deal_config_wrapper']['target_webform'] = [
  '#type' => 'select',
  '#title' => $this->t('Select Webform'),
  '#options' => $webformOptions,
  '#default_value' => $selectedWebform,
  '#ajax' => [
    'callback' => '::reloadDealConfig',
    'wrapper' => 'deal-config-wrapper',
  ],
  '#executes_submit_callback' => FALSE,
];

  // ----------------------------
  // Deal Section
  // ----------------------------
  $form['deal_config_wrapper']['deal_section'] = [
    '#type'  => 'details',
    '#title' => $this->t('Deal Configuration [Pro]'),
    '#open'  => TRUE,
  ];

  $form['deal_config_wrapper']['deal_section']['deal_enabled'] = [
    '#type' => 'checkbox',
    '#title' => $this->t('Create HubSpot Deal on submission'),
    '#default_value' => $savedDealConfig['enabled'] ?? 0,
  ];

  $form['deal_config_wrapper']['deal_section']['deal_title'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Deal Title'),
    '#default_value' => $savedDealConfig['title'] ?? '',
    '#states' => [
      'visible' => [
        ':input[name="deal_enabled"]' => ['checked' => TRUE],
      ],
    ],
  ];

  $form['deal_config_wrapper']['deal_section']['deal_amount'] = [
    '#type' => 'number',
    '#title' => $this->t('Deal Amount'),
    '#default_value' => $savedDealConfig['amount'] ?? 0,
    '#states' => [
      'visible' => [
        ':input[name="deal_enabled"]' => ['checked' => TRUE],
      ],
    ],
  ];

  $form['deal_config_wrapper']['deal_section']['deal_stage'] = [
    '#type' => 'select',
    '#title' => $this->t('Deal Stage'),
    '#options' => [
      'appointmentscheduled' => 'Appointment Scheduled',
      'qualifiedtobuy' => 'Qualified to Buy',
      'closedwon' => 'Closed Won',
      'closedlost' => 'Closed Lost',
    ],
    '#default_value' => $savedDealConfig['stage'] ?? 'appointmentscheduled',
  ];

  $form['deal_config_wrapper']['deal_section']['deal_pipeline'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Pipeline ID'),
    '#default_value' => $savedDealConfig['pipeline'] ?? 'default',
  ];

  // ----------------------------
  // LINE ITEMS
  // ----------------------------
  $form['deal_config_wrapper']['deal_section']['line_items_wrapper'] = [
    '#type' => 'container',
    '#attributes' => ['id' => 'line-items-wrapper'],
    '#tree' => TRUE,
  ];


  $lineItems = $form_state->get('line_items') ?? [];

  foreach ($lineItems as $index => $item) {

    $form['deal_config_wrapper']['deal_section']['line_items_wrapper'][$index]['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Item'),
      '#default_value' => $item['title'] ?? '',
    ];

    $form['deal_config_wrapper']['deal_section']['line_items_wrapper'][$index]['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Price'),
      '#default_value' => $item['price'] ?? 0,
    ];

    $form['deal_config_wrapper']['deal_section']['line_items_wrapper'][$index]['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Qty'),
      '#default_value' => $item['quantity'] ?? 1,
    ];

    $form['deal_config_wrapper']['deal_section']['line_items_wrapper'][$index]['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#name' => 'remove_line_item_' . $index,
      '#submit' => ['::removeLineItem'],
      '#ajax' => [
        'callback' => '::lineItemsAjaxCallback',
        'wrapper' => 'line-items-wrapper',
      ],
    ];
  }

  $form['deal_config_wrapper']['deal_section']['add_line_item'] = [
    '#type' => 'submit',
    '#value' => $this->t('Add Line Item'),
    '#submit' => ['::addLineItem'],
    '#ajax' => [
      'callback' => '::lineItemsAjaxCallback',
      'wrapper' => 'line-items-wrapper',
    ],
  ];
}
    // ----------------------------
    // LIFECYCLE STAGE
    // ----------------------------
    $form['lifecycle_stage'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Lifecycle Stage for This Form'),
      '#description'   => $this->t('Override the default lifecycle stage for submissions from this form.'),
      '#options'       => [
        ''            => $this->t('-- Use default --'),
        'lead'        => $this->t('Lead'),
        'subscriber'  => $this->t('Subscriber'),
        'opportunity' => $this->t('Opportunity'),
        'customer'    => $this->t('Customer'),
      ],
      '#default_value' => $config->get('lifecycle_stage') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['webform_section']['mappings_wrapper'];
  }

  public function userAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['user_section']['user_mappings_wrapper'];
  }

  public function addRow(array &$form, FormStateInterface $form_state) {
    $mappings   = $form_state->get('mappings') ?? [];
    $submitted  = $form_state->getValue('mappings_wrapper') ?? [];

    foreach ($submitted as $index => $row) {
      $mappings[$index] = [
        'drupal'  => $row['drupal_field'] ?? '',
        'hubspot' => $row['hubspot_property'] ?? '',
      ];
    }

    $mappings[] = ['drupal' => '', 'hubspot' => ''];
    $form_state->set('mappings', $mappings);
    $form_state->setRebuild();
  }

  public function removeRow(array &$form, FormStateInterface $form_state) {
    $trigger  = $form_state->getTriggeringElement()['#name'];
    $index    = (int) str_replace('remove_', '', $trigger);
    $mappings = $form_state->get('mappings') ?? [];

    unset($mappings[$index]);
    $form_state->set('mappings', array_values($mappings));
    $form_state->setRebuild();
  }

  public function addUserRow(array &$form, FormStateInterface $form_state) {
    $mappings = $form_state->get('user_mappings') ?? [];
    $mappings[] = ['drupal' => '', 'hubspot' => ''];
    $form_state->set('user_mappings', $mappings);
    $form_state->setRebuild();
  }

  public function removeUserRow(array &$form, FormStateInterface $form_state) {
    $trigger  = $form_state->getTriggeringElement()['#name'];
    $index    = (int) str_replace('remove_user_', '', $trigger);
    $mappings = $form_state->get('user_mappings') ?? [];

    unset($mappings[$index]);
    $form_state->set('user_mappings', array_values($mappings));
    $form_state->setRebuild();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {

  // --- Webform field mappings ---
  $values   = $form_state->getValue('mappings_wrapper') ?? [];
  $mappings = [];

  foreach ($values as $row) {
    $drupalField     = $row['drupal_field'] ?? '';
    $hubspotProperty = $row['hubspot_property'] ?? '';

    if (!empty($drupalField) && !empty($hubspotProperty)) {
      $safeKey            = str_replace('.', '__', $drupalField);
      $mappings[$safeKey] = $hubspotProperty;
    }
  }

  // --- User mappings ---
  $userValues   = $form_state->getValue('user_mappings_wrapper') ?? [];
  $userMappings = [];

  foreach ($userValues as $row) {
    $drupalField     = $row['drupal_field'] ?? '';
    $hubspotProperty = $row['hubspot_property'] ?? '';

    if (!empty($drupalField) && !empty($hubspotProperty)) {
      $userMappings[$drupalField] = $hubspotProperty;
    }
  }

  // --- Deal config (all nested under deal_config_wrapper) ---
  $dealConfigValues = $form_state->getValue('deal_config_wrapper') ?? [];
  $dealSection      = $dealConfigValues['deal_section'] ?? [];
  $lineItemValues   = $dealSection['line_items_wrapper'] ?? [];
  $lineItems        = [];

  foreach ($lineItemValues as $row) {
    if (!empty($row['title'])) {
      $lineItems[] = [
        'title'    => $row['title'],
        'price'    => (float) ($row['price'] ?? 0),
        'quantity' => (int)   ($row['quantity'] ?? 1),
      ];
    }
  }

  $dealConfig = [
    'enabled'    => (bool)  ($dealSection['deal_enabled'] ?? 0),
    'title'      =>          $dealSection['deal_title']   ?? '',
    'amount'     => (float) ($dealSection['deal_amount']  ?? 0),
    'stage'      =>          $dealSection['deal_stage']   ?? 'appointmentscheduled',
    'pipeline'   =>          $dealSection['deal_pipeline'] ?? 'default',
    'line_items' => $lineItems,
  ];

  // --- Save per webform ---
  $config         = $this->config('drupalbridge.field_mapping');
  $allDealConfigs = $config->get('deal_config') ?? [];
  $webformId      = $dealConfigValues['target_webform'] ?? NULL;

  if (!empty($webformId)) {
    $allDealConfigs[$webformId] = $dealConfig;
  }

  $config
    ->set('mappings',        $mappings)
    ->set('user_mappings',   $userMappings)
    ->set('lifecycle_stage', $form_state->getValue('lifecycle_stage'))
    ->set('deal_config',     $allDealConfigs)
    ->save();

  \Drupal::messenger()->addStatus($this->t('Field mappings saved successfully.'));
  parent::submitForm($form, $form_state);
}

public function lineItemsAjaxCallback(array &$form, FormStateInterface $form_state) {
  return $form['deal_config_wrapper']['deal_section']['line_items_wrapper'];
}

public function reloadDealConfig(array &$form, FormStateInterface $form_state) {
  $form_state->set('loaded_webform', NULL);
  $form_state->setRebuild();
  return $form['deal_config_wrapper'];
}

public function addLineItem(array &$form, FormStateInterface $form_state) {
  $items = $form_state->get('line_items') ?? [];

  $submitted = $form_state->getValue([
    'deal_config_wrapper', 'deal_section', 'line_items_wrapper'
  ]) ?? [];

  foreach ($submitted as $index => $row) {
    $items[$index] = [
      'title'    => $row['title']    ?? '',
      'price'    => $row['price']    ?? 0,
      'quantity' => $row['quantity'] ?? 1,
    ];
  }

  $items[] = ['title' => '', 'price' => 0, 'quantity' => 1];
  $form_state->set('line_items', $items);
  $form_state->setRebuild();
}

  /**
   * Get Drupal fields from all webforms.
   */
  private function getDrupalContactFields(): array {
    $fields = [];

    try {
      $webformStorage = \Drupal::entityTypeManager()->getStorage('webform');
      $webforms       = $webformStorage->loadMultiple();

      foreach ($webforms as $webform) {
        foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
          $type = $element['#type'] ?? '';

          if (in_array($type, ['markup', 'hidden', 'container', 'actions'], TRUE)) {
            continue;
          }

          $label = $element['#title'] ?? $key;

          if ($type === 'webform_address') {
            $fields[$key . '.address']        = $label . ' — Street Address (' . $key . '.address)';
            $fields[$key . '.city']           = $label . ' — City (' . $key . '.city)';
            $fields[$key . '.state_province'] = $label . ' — State (' . $key . '.state_province)';
            $fields[$key . '.postal_code']    = $label . ' — ZIP (' . $key . '.postal_code)';
            $fields[$key . '.country']        = $label . ' — Country (' . $key . '.country)';
          }
          else {
            $fields[$key] = $label . ' (' . $key . ')';
          }
        }
      }

      ksort($fields);
    }
    catch (\Exception $e) {
      \Drupal::logger('drupalbridge')->error(
        'Could not load webform fields: @error',
        ['@error' => $e->getMessage()]
      );
    }

    return $fields;
  }

  /**
   * Get Drupal user fields.
   */
  private function getDrupalUserFields(): array {
    $fields = [
      'name'             => 'Username (name)',
      'mail'             => 'Email (mail)',
      'field_first_name' => 'First Name (field_first_name)',
      'field_last_name'  => 'Last Name (field_last_name)',
    ];

    // Also load any custom user fields
    try {
      $fieldDefinitions = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('user', 'user');

      foreach ($fieldDefinitions as $fieldName => $definition) {
        if (str_starts_with($fieldName, 'field_') && !isset($fields[$fieldName])) {
          $fields[$fieldName] = $definition->getLabel() . ' (' . $fieldName . ')';
        }
      }
    }
    catch (\Exception $e) {
      // Use defaults
    }

    return $fields;
  }

  /**
   * Get HubSpot contact properties dynamically from API.
   */
  private function getHubSpotPropertyOptions(): array {
    $options = [];

    try {
      $properties = $this->hubspotClient->getContactProperties();

      foreach ($properties as $property) {
        $name  = $property['name'] ?? '';
        $label = $property['label'] ?? $name;
        $type  = $property['fieldType'] ?? $property['type'] ?? 'text';

        if (!empty($name)) {
          $options[$name] = $label . ' (' . $type . ')';
        }
      }

      asort($options);

    }
    catch (\Exception $e) {
      \Drupal::logger('drupalbridge')->error(
        'Could not fetch HubSpot properties: @error',
        ['@error' => $e->getMessage()]
      );

      // Fallback
      $options = [
        'email'          => 'Email (text)',
        'firstname'      => 'First Name (text)',
        'lastname'       => 'Last Name (text)',
        'phone'          => 'Phone (text)',
        'company'        => 'Company (text)',
        'address'        => 'Street Address (text)',
        'city'           => 'City (text)',
        'state'          => 'State (text)',
        'zip'            => 'ZIP (text)',
        'country'        => 'Country (text)',
        'lifecyclestage' => 'Lifecycle Stage (select)',
        'message'        => 'Message (textarea)',
      ];
    }

    return $options;
  }

public function removeLineItem(array &$form, FormStateInterface $form_state) {
  $trigger = $form_state->getTriggeringElement()['#name'];
  $index   = (int) str_replace('remove_line_item_', '', $trigger);
  $items   = $form_state->get('line_items') ?? [];

  unset($items[$index]);
  $form_state->set('line_items', array_values($items));
  $form_state->setRebuild();
}


}