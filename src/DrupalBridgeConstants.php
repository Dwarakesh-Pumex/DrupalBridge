<?php

namespace Drupal\drupalbridge;

/**
 * DrupalBridge application constants.
 */
class DrupalBridgeConstants {

  /**
   * Your HubSpot OAuth App Client ID from developers.hubspot.com
   * Replace with your actual Client ID.
   */
  const HUBSPOT_CLIENT_ID = '2d96c8bf-a889-4436-afc3-0ba26b811570';

  /**
   * Your HubSpot OAuth App Client Secret.
   * Never expose this in logs or the UI.
   */
  const HUBSPOT_CLIENT_SECRET = '4acb2a4d-6604-4d85-a99c-429de3f5040f';

  // DrupalBridge URLs
  const WEBSITE_URL = 'https://drupalbridge.com';
  const PRICING_URL = 'https://drupalbridge.com/pricing';
  const DOCS_URL    = 'https://drupalbridge.com/docs';
  const SUPPORT_URL = 'https://drupalbridge.com/support';
  const PRIVACY_URL = 'https://drupalbridge.com/privacy';
  const TERMS_URL   = 'https://drupalbridge.com/terms';

}