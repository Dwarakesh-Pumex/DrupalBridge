<?php

namespace Drupal\drupalbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\drupalbridge\DrupalBridgeConstants;

/**
 * Handles HubSpot OAuth 2.0 authorization flow.
 */
class OAuthController extends ControllerBase {

  /**
   * Step 1 — Redirect admin to HubSpot OAuth consent screen.
   */
  public function authorize(): RedirectResponse {
    $clientId     = DrupalBridgeConstants::HUBSPOT_CLIENT_ID;
    $clientSecret = DrupalBridgeConstants::HUBSPOT_CLIENT_SECRET;

    // Internal settings URL — use Drupal URL generator
    $settingsUrl = Url::fromRoute('drupalbridge.settings')
      ->setAbsolute(TRUE)
      ->toString();

    if (empty($clientId) || empty($clientSecret)) {
      \Drupal::messenger()->addError(
        $this->t('DrupalBridge app is not configured correctly. Please contact support.')
      );
      return new RedirectResponse($settingsUrl);
    }

    // Build redirect URI
    $redirectUri = Url::fromRoute('drupalbridge.oauth.callback')
      ->setAbsolute(TRUE)
      ->toString();

    $scopes = [
      'oauth',
      'crm.objects.contacts.read',
      'crm.objects.contacts.write',
      'crm.objects.companies.read',
      'crm.objects.companies.write',
      'crm.objects.deals.read',
      'crm.objects.deals.write',
      'forms',
      'tickets',
    ];

    // Generate state token — store in State API
    $state = bin2hex(random_bytes(16));
    \Drupal::state()->set('drupalbridge_oauth_state', $state);
    \Drupal::state()->set('drupalbridge_oauth_state_time', time());

    // Build HubSpot OAuth URL
    $authUrl = 'https://app.hubspot.com/oauth/authorize?'
      . http_build_query([
        'client_id'    => $clientId,
        'redirect_uri' => $redirectUri,
        'scope'        => implode(' ', $scopes),
        'state'        => $state,
      ], '', '&', PHP_QUERY_RFC3986);

    \Drupal::logger('drupalbridge')->notice(
      'OAuth initiated. Redirect URI: @uri',
      ['@uri' => $redirectUri]
    );

    // TrustedRedirectResponse for external HubSpot URL
    return new TrustedRedirectResponse($authUrl);
  }

  /**
   * Step 2 — Handle callback from HubSpot.
   */
  public function callback(Request $request): RedirectResponse {
    $code  = $request->query->get('code');
    $state = $request->query->get('state');
    $error = $request->query->get('error');

    // Always use absolute internal URL
    $settingsUrl = Url::fromRoute('drupalbridge.settings')
      ->setAbsolute(TRUE)
      ->toString();

    // Handle denied access
    if ($error) {
      \Drupal::messenger()->addError(
        $this->t('HubSpot connection was denied: @error', [
          '@error' => $error,
        ])
      );
      return new RedirectResponse($settingsUrl);
    }

    // Validate state
    $savedState     = \Drupal::state()->get('drupalbridge_oauth_state');
    $savedStateTime = \Drupal::state()->get('drupalbridge_oauth_state_time', 0);
    $stateExpired   = (time() - $savedStateTime) > 1800;

    \Drupal::logger('drupalbridge')->notice(
      'OAuth callback. Received: @received Saved: @saved Expired: @expired Diff: @diff',
      [
        '@received' => $state ?? 'null',
        '@saved'    => $savedState ?? 'null',
        '@expired'  => $stateExpired ? 'yes' : 'no',
        '@diff'     => time() - $savedStateTime,
      ]
    );

    if ($stateExpired) {
      \Drupal::messenger()->addError(
        $this->t('OAuth session expired. Please try connecting again.')
      );
      \Drupal::state()->delete('drupalbridge_oauth_state');
      \Drupal::state()->delete('drupalbridge_oauth_state_time');
      return new RedirectResponse($settingsUrl);
    }

    if (empty($state) || empty($savedState) || $state !== $savedState) {
      \Drupal::messenger()->addError(
        $this->t('OAuth state mismatch. Please try connecting again.')
      );
      \Drupal::state()->delete('drupalbridge_oauth_state');
      \Drupal::state()->delete('drupalbridge_oauth_state_time');
      return new RedirectResponse($settingsUrl);
    }

    if (empty($code)) {
      \Drupal::messenger()->addError(
        $this->t('No authorisation code received from HubSpot.')
      );
      return new RedirectResponse($settingsUrl);
    }

    // Clear state immediately after validation
    \Drupal::state()->delete('drupalbridge_oauth_state');
    \Drupal::state()->delete('drupalbridge_oauth_state_time');

    // Exchange code for tokens
    try {
      $clientId     = DrupalBridgeConstants::HUBSPOT_CLIENT_ID;
      $clientSecret = DrupalBridgeConstants::HUBSPOT_CLIENT_SECRET;

      $redirectUri = Url::fromRoute('drupalbridge.oauth.callback')
        ->setAbsolute(TRUE)
        ->toString();

      $httpClient = \Drupal::httpClient();

      // Exchange authorization code for access token
      $tokenResponse = $httpClient->post(
        'https://api.hubapi.com/oauth/v1/token',
        [
          'form_params' => [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
          ],
        ]
      );

      $tokenData = json_decode(
        $tokenResponse->getBody()->getContents(),
        TRUE
      );

      \Drupal::logger('drupalbridge')->notice(
        'Token response received: @data',
        ['@data' => json_encode($tokenData)]
      );

      $accessToken  = $tokenData['access_token'] ?? NULL;
      $refreshToken = $tokenData['refresh_token'] ?? NULL;
      $expiresIn    = $tokenData['expires_in'] ?? 1800;

      if (empty($accessToken)) {
        throw new \Exception('No access token received from HubSpot.');
      }

      // Get portal info
      $infoResponse = $httpClient->get(
        'https://api.hubapi.com/oauth/v1/access-tokens/' . $accessToken
      );

      $infoData  = json_decode(
        $infoResponse->getBody()->getContents(),
        TRUE
      );

      $portalId  = $infoData['hub_id'] ?? NULL;
      $hubDomain = $infoData['hub_domain'] ?? '';

      // Save tokens using setData to bypass schema validation
      $editable = \Drupal::configFactory()
        ->getEditable('drupalbridge.settings');

      $data                        = $editable->getRawData();
      $data['api_token']           = $accessToken;
      $data['oauth_refresh_token'] = $refreshToken;
      $data['oauth_token_expires'] = time() + $expiresIn;
      $data['portal_id']           = (string) $portalId;
      $data['hub_domain']          = $hubDomain;

      $editable->setData($data)->save();

      \Drupal::messenger()->addStatus(
        $this->t('✅ Connected to HubSpot! Portal ID: @portal', [
          '@portal' => $portalId,
        ])
      );

      \Drupal::logger('drupalbridge')->notice(
        'OAuth connection established. Portal ID: @portal Hub: @hub',
        [
          '@portal' => $portalId,
          '@hub'    => $hubDomain,
        ]
      );

    }
    catch (\Exception $e) {
      $responseBody = '';
      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $responseBody = (string) $e->getResponse()->getBody();
      }

      \Drupal::logger('drupalbridge')->error(
        'OAuth token exchange failed: @error Response: @response',
        [
          '@error'    => $e->getMessage(),
          '@response' => $responseBody,
        ]
      );

      \Drupal::messenger()->addError(
        $this->t('Connection failed: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }

    return new RedirectResponse($settingsUrl);
  }

  /**
   * Disconnect from HubSpot.
   */
  public function disconnect(): RedirectResponse {
    $editable = \Drupal::configFactory()
      ->getEditable('drupalbridge.settings');

    $data = $editable->getRawData();
    unset(
      $data['api_token'],
      $data['oauth_refresh_token'],
      $data['oauth_token_expires'],
      $data['portal_id'],
      $data['hub_domain']
    );

    $editable->setData($data)->save();

    \Drupal::state()->delete('drupalbridge_oauth_state');
    \Drupal::state()->delete('drupalbridge_oauth_state_time');

    \Drupal::messenger()->addStatus(
      $this->t('DrupalBridge disconnected from HubSpot.')
    );

    $settingsUrl = Url::fromRoute('drupalbridge.settings')
      ->setAbsolute(TRUE)
      ->toString();

    return new RedirectResponse($settingsUrl);
  }

}