<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Handles HubSpot Deal, Company and Ticket creation.
 * Requires DrupalBridge Pro tier.
 */
class CommerceSync {

  protected HubSpotClient $hubspotClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    HubSpotClient $hubspotClient,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->hubspotClient = $hubspotClient;
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
  }

  // -------------------------------------------------------------------------
  // DEALS
  // -------------------------------------------------------------------------

  /**
   * Create a HubSpot deal from a Commerce order or webform submission.
   *
   * @param array $orderData
   *   Keys: order_id, order_number, total, currency, label, email, items[],
   *         stage, pipeline, company, website, phone, city, country, contact_id
   *
   * @return string|null
   *   HubSpot deal ID or null on failure.
   */
  public function createDeal(array $orderData): ?string {
    try {
      // Prefer human-readable order_number, fall back to order_id, then 'unknown'
      $orderRef = $orderData['order_number']
  ?? (!empty($orderData['order_id']) ? '#' . $orderData['order_id'] : NULL)
  ?? 'unknown';

      $dealName = $orderData['label'] ?? 'Order ' . $orderRef;

      $payload = [
        'properties' => [
          'dealname'    => $dealName,
          'amount'      => $orderData['total'] ?? 0,
          'dealstage'   => $orderData['stage']    ?? $this->getDefaultDealStage(),
          'pipeline'    => $orderData['pipeline'] ?? $this->getDefaultPipeline(),
          'closedate'   => date('Y-m-d'),
          'description' => 'Synced from Drupal Commerce order ' . $orderRef,
        ],
      ];

      $response = $this->hubspotClient->post('/crm/v3/objects/deals', $payload);
      $dealId   = $response['id'] ?? NULL;

      if ($dealId) {
        $this->loggerFactory->get('drupalbridge')->notice(
          'HubSpot deal created: @deal for order @order',
          ['@deal' => $dealId, '@order' => $orderRef]
        );

        // Associate deal → contact
        if (!empty($orderData['email'])) {
          $this->associateDealToContact($dealId, $orderData['email']);
        }

        // Sync line items
        if (!empty($orderData['items'])) {
          $this->syncLineItems($dealId, $orderData['items']);
        }

        // Create support ticket
        // Create support ticket and associate it to the deal
        if (!empty($orderData['email'])) {
           $ticketId = $this->createTicket([
          'subject'  => 'Order: ' . $dealName,
          'content'  => 'Automatically created from Drupal Commerce order ' . $orderRef,
          'priority' => 'MEDIUM',
          'email'    => $orderData['email'],
        ]);

  if ($ticketId) {
    $this->associateTicketToDeal($ticketId, $dealId);
  }
}

        // Create/update company and associate to contact
        if (!empty($orderData['company'])) {
          $companyId = $this->createOrUpdateCompany([
            'name'    => $orderData['company'],
            'website' => $orderData['website'] ?? '',
            'phone'   => $orderData['phone']   ?? '',
            'city'    => $orderData['city']     ?? '',
            'country' => $orderData['country']  ?? '',
          ]);

          if ($companyId && !empty($orderData['contact_id'])) {
            $this->associateCompanyToContact($companyId, $orderData['contact_id']);
          }
        }
      }

      return $dealId;

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to create HubSpot deal for order @order: @error',
        [
          '@order' => $orderData['order_number'] ?? $orderData['order_id'] ?? 'unknown',
          '@error' => $e->getMessage(),
        ]
      );
      return NULL;
    }
  }

  public function associateTicketToDeal(string $ticketId, string $dealId): void {
  try {
    $this->hubspotClient->post(
      '/crm/v3/associations/tickets/deals/batch/create',
      [
        'inputs' => [[
          'from' => ['id' => $ticketId],
          'to'   => ['id' => $dealId],
          'type' => 'ticket_to_deal',
        ]],
      ]
    );

    $this->loggerFactory->get('drupalbridge')->notice(
      'Ticket @ticket associated to deal @deal',
      ['@ticket' => $ticketId, '@deal' => $dealId]
    );

  }
  catch (\Exception $e) {
    $this->loggerFactory->get('drupalbridge')->error(
      'Failed to associate ticket @ticket to deal @deal: @error',
      ['@ticket' => $ticketId, '@deal' => $dealId, '@error' => $e->getMessage()]
    );
  }
}

  /**
   * Associate a HubSpot deal to a contact by email.
   */
  public function associateDealToContact(string $dealId, string $email): void {
    try {
      $searchResult = $this->hubspotClient->post(
        '/crm/v3/objects/contacts/search',
        [
          'filterGroups' => [[
            'filters' => [[
              'propertyName' => 'email',
              'operator'     => 'EQ',
              'value'        => $email,
            ]],
          ]],
        ]
      );

      $contactId = $searchResult['results'][0]['id'] ?? NULL;

      if (!$contactId) {
        $this->loggerFactory->get('drupalbridge')->warning(
          'Cannot associate deal @deal — contact not found for @email',
          ['@deal' => $dealId, '@email' => $email]
        );
        return;
      }

      $this->hubspotClient->post(
        '/crm/v3/associations/deals/contacts/batch/create',
        [
          'inputs' => [[
            'from' => ['id' => $dealId],
            'to'   => ['id' => $contactId],
            'type' => 'deal_to_contact',
          ]],
        ]
      );

      $this->loggerFactory->get('drupalbridge')->notice(
        'Deal @deal associated to contact @contact (@email)',
        ['@deal' => $dealId, '@contact' => $contactId, '@email' => $email]
      );

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to associate deal @deal to contact @email: @error',
        ['@deal' => $dealId, '@email' => $email, '@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Sync order line items to HubSpot deal.
   */
  public function syncLineItems(string $dealId, array $items): void {
    foreach ($items as $item) {
      try {
        $lineItemResponse = $this->hubspotClient->post(
          '/crm/v3/objects/line_items',
          [
            'properties' => [
              'name'             => $item['title'] ?? 'Unknown Product',
              'quantity'         => $item['quantity'] ?? 1,
              'price'            => $item['unit_price'] ?? $item['price'] ?? 0,
              'hs_object_source' => 'DRUPAL',
            ],
          ]
        );

        $lineItemId = $lineItemResponse['id'] ?? NULL;

        if ($lineItemId) {
          $this->hubspotClient->post(
            '/crm/v3/associations/line_items/deals/batch/create',
            [
              'inputs' => [[
                'from' => ['id' => $lineItemId],
                'to'   => ['id' => $dealId],
                'type' => 'line_item_to_deal',
              ]],
            ]
          );

          $this->loggerFactory->get('drupalbridge')->notice(
            'Line item @item synced to deal @deal',
            ['@item' => $lineItemId, '@deal' => $dealId]
          );
        }

      }
      catch (\Exception $e) {
        $this->loggerFactory->get('drupalbridge')->error(
          'Failed to sync line item to deal @deal: @error',
          ['@deal' => $dealId, '@error' => $e->getMessage()]
        );
      }
    }
  }

  // -------------------------------------------------------------------------
  // COMPANIES
  // -------------------------------------------------------------------------

  /**
   * Create or update a HubSpot company.
   */
  public function createOrUpdateCompany(array $companyData): ?string {
    try {
      $name = $companyData['name'] ?? '';

      if (empty($name)) {
        return NULL;
      }

      $searchResult = $this->hubspotClient->post(
        '/crm/v3/objects/companies/search',
        [
          'filterGroups' => [[
            'filters' => [[
              'propertyName' => 'name',
              'operator'     => 'EQ',
              'value'        => $name,
            ]],
          ]],
        ]
      );

      $existingId = $searchResult['results'][0]['id'] ?? NULL;

      $properties = [
        'name'    => $name,
        'website' => $companyData['website'] ?? '',
        'phone'   => $companyData['phone']   ?? '',
        'city'    => $companyData['city']    ?? '',
        'country' => $companyData['country'] ?? '',
      ];

      if ($existingId) {
        $this->hubspotClient->patch(
          '/crm/v3/objects/companies/' . $existingId,
          ['properties' => $properties]
        );

        $this->loggerFactory->get('drupalbridge')->notice(
          'HubSpot company updated: @id (@name)',
          ['@id' => $existingId, '@name' => $name]
        );

        return $existingId;
      }
      else {
        $response  = $this->hubspotClient->post(
          '/crm/v3/objects/companies',
          ['properties' => $properties]
        );
        $companyId = $response['id'] ?? NULL;

        $this->loggerFactory->get('drupalbridge')->notice(
          'HubSpot company created: @id (@name)',
          ['@id' => $companyId, '@name' => $name]
        );

        return $companyId;
      }

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to create/update HubSpot company @name: @error',
        ['@name' => $companyData['name'] ?? 'unknown', '@error' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Associate company to contact.
   */
  public function associateCompanyToContact(string $companyId, string $contactId): void {
    try {
      $this->hubspotClient->put(
        '/crm/v3/associations/companies/contacts/batch/create',
        [
          'inputs' => [[
            'from' => ['id' => $companyId],
            'to'   => ['id' => $contactId],
            'type' => 'company_to_contact',
          ]],
        ]
      );

      $this->loggerFactory->get('drupalbridge')->notice(
        'Company @company associated to contact @contact',
        ['@company' => $companyId, '@contact' => $contactId]
      );

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to associate company to contact: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  // -------------------------------------------------------------------------
  // TICKETS
  // -------------------------------------------------------------------------

  /**
   * Create a HubSpot ticket.
   */
  public function createTicket(array $ticketData): ?string {
    try {
      $payload = [
        'properties' => [
          'subject'            => $ticketData['subject']     ?? 'New ticket from Drupal',
          'content'            => $ticketData['content']     ?? '',
          'hs_pipeline'        => $ticketData['pipeline_id'] ?? '0',
          'hs_pipeline_stage'  => $ticketData['stage_id']    ?? '1',
          'hs_ticket_priority' => $ticketData['priority']    ?? 'MEDIUM',
        ],
      ];

      $response = $this->hubspotClient->post('/crm/v3/objects/tickets', $payload);
      $ticketId = $response['id'] ?? NULL;

      if ($ticketId) {
        $this->loggerFactory->get('drupalbridge')->notice(
          'HubSpot ticket created: @id',
          ['@id' => $ticketId]
        );

        if (!empty($ticketData['email'])) {
          $this->associateTicketToContact($ticketId, $ticketData['email']);
        }
      }

      return $ticketId;

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to create HubSpot ticket: @error',
        ['@error' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Associate ticket to contact.
   */
  public function associateTicketToContact(string $ticketId, string $email): void {
    try {
      $searchResult = $this->hubspotClient->post(
        '/crm/v3/objects/contacts/search',
        [
          'filterGroups' => [[
            'filters' => [[
              'propertyName' => 'email',
              'operator'     => 'EQ',
              'value'        => $email,
            ]],
          ]],
        ]
      );

      $contactId = $searchResult['results'][0]['id'] ?? NULL;

      if (!$contactId) {
        return;
      }

      $this->hubspotClient->post(
        '/crm/v3/associations/tickets/contacts/batch/create',
        [
          'inputs' => [[
            'from' => ['id' => $ticketId],
            'to'   => ['id' => $contactId],
            'type' => 'ticket_to_contact',
          ]],
        ]
      );

      $this->loggerFactory->get('drupalbridge')->notice(
        'Ticket @ticket associated to contact @email',
        ['@ticket' => $ticketId, '@email' => $email]
      );

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Failed to associate ticket to contact: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  // -------------------------------------------------------------------------
  // HELPERS
  // -------------------------------------------------------------------------

  private function getDefaultDealStage(): string {
    return $this->configFactory
      ->get('drupalbridge.settings')
      ->get('commerce_deal_stage') ?? 'closedwon';
  }

  private function getDefaultPipeline(): string {
    return $this->configFactory
      ->get('drupalbridge.settings')
      ->get('commerce_pipeline') ?? 'default';
  }

}