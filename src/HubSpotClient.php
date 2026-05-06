<?php

namespace Drupal\drupalbridge;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;


class HubSpotClient {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  protected string $baseUrl = 'https://api.hubapi.com';
  protected int $maxRetries = 3;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
  }

  /**
 * Refresh OAuth access token if expired or about to expire.
 */
private function refreshTokenIfNeeded(): void {
  $config = $this->configFactory->get('drupalbridge.settings');
  $token = $config->get('api_token');
  \Drupal::logger('drupalbridge')->notice('Token used: @t', ['@t' => $token]);
  $expiresAt    = $config->get('oauth_token_expires') ?? 0;
  $refreshToken = $config->get('oauth_refresh_token');

  // Nothing to do if we don't have a refresh token
  if (empty($refreshToken)) {
    return;
  }

  // If token is still valid (more than 5 min left), do nothing
  if (time() < ($expiresAt - 300)) {
    return;
  }

  // 🔒 Initialize variables to avoid warnings
  $accessToken = NULL;
  $newRefresh  = NULL;
  $expiresIn   = 1800;

  try {
    $clientId     = $config->get('oauth_client_id');
    $clientSecret = $config->get('oauth_client_secret');

    $response = $this->httpClient->post(
      'https://api.hubapi.com/oauth/v1/token',
      [
        'form_params' => [
          'grant_type'    => 'refresh_token',
          'client_id'     => $clientId,
          'client_secret' => $clientSecret,
          'refresh_token' => $refreshToken,
        ],
      ]
    );

    $data = json_decode($response->getBody()->getContents(), TRUE);

    $accessToken = $data['access_token'] ?? NULL;
    $expiresIn   = $data['expires_in'] ?? 1800;
    $newRefresh  = $data['refresh_token'] ?? $refreshToken;

  } catch (\Exception $e) {
    \Drupal::logger('drupalbridge')->error(
      'OAuth refresh failed: @error',
      ['@error' => $e->getMessage()]
    );
  }

  // ✅ Only save if we actually got a token
  if (!empty($accessToken)) {
    $editable = \Drupal::configFactory()->getEditable('drupalbridge.settings');
    $data = $editable->getRawData();

    $data['api_token'] = $accessToken;
    $data['oauth_refresh_token'] = $newRefresh;
    $data['oauth_token_expires'] = time() + $expiresIn;

    $editable->setData($data)->save();
  }
}
  
  public function getContactProperties(): array {

  $data = $this->get('/crm/v3/properties/contacts');

  $properties = [];

  if (!empty($data['results'])) {
    foreach ($data['results'] as $property) {

      // Skip hidden/archived fields
      if (!empty($property['hidden']) || !empty($property['archived'])) {
        continue;
      }

      $properties[] = [
        'name'      => $property['name'],
        'label'     => $property['label'] ?? $property['name'],
        'fieldType' => $property['fieldType'] ?? 'text',
        'type'      => $property['type'] ?? 'string',
        'groupName' => $property['groupName'] ?? '',
      ];
    }
  }

  return $properties;
}

  
  public function get(string $endpoint): array {
    return $this->request('GET', $endpoint);
  }

  
  public function post(string $endpoint, array $data = []): array {
    return $this->request('POST', $endpoint, $data);
  }

  
  public function patch(string $endpoint, array $data = []): array {
    return $this->request('PATCH', $endpoint, $data);
  }
  

  public function delete(string $endpoint): array {
   return $this->request('DELETE', $endpoint);
  }

  /**
 * PUT request.
 */
  public function put(string $endpoint, array $data = []): array {
   return $this->request('PUT', $endpoint, $data);
  }

 private function request(string $method, string $endpoint, array $data = []): array {
  // Refresh token if needed first
  $this->refreshTokenIfNeeded();

  // NOW fetch the token after potential refresh
  $token = \Drupal::service('config.storage')
  ->read('drupalbridge.settings')['api_token'] ?? NULL;

  \Drupal::logger('drupalbridge')->notice(
  'TOKEN RAW: @t',
  ['@t' => $token]
);

  if (empty($token)) {
    $this->loggerFactory->get('drupalbridge')->error(
      'HubSpot API call attempted but no token is configured. Endpoint: @endpoint',
      ['@endpoint' => $endpoint]
    );
    throw new \Exception(
      'HubSpot API token is not configured. Please visit the DrupalBridge settings page.'
    );
  }

  $attempt = 0;

  while ($attempt < $this->maxRetries) {
    try {
      $options = [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
        ],
      ];

      if (!empty($data)) {
        $options['json'] = $data;
      }

      $response = $this->httpClient->request(
        $method,
        $this->baseUrl . $endpoint,
        $options
      );

      $this->loggerFactory->get('drupalbridge')->notice(
        'HubSpot API success. Method: @method. Endpoint: @endpoint. Status: @status',
        [
          '@method'   => $method,
          '@endpoint' => $endpoint,
          '@status'   => $response->getStatusCode(),
        ]
      );

      return json_decode(
        $response->getBody()->getContents(),
        TRUE
      ) ?? [];

    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse()
        ? $e->getResponse()->getStatusCode()
        : 0;

      $responseBody = $e->getResponse()
        ? $e->getResponse()->getBody()->getContents()
        : $e->getMessage();

      if ($statusCode === 429) {
        $attempt++;
        $waitSeconds = pow(2, $attempt);

        $this->loggerFactory->get('drupalbridge')->warning(
          'HubSpot rate limit hit. Attempt @attempt of @max. Waiting @seconds seconds. Endpoint: @endpoint',
          [
            '@attempt'  => $attempt,
            '@max'      => $this->maxRetries,
            '@seconds'  => $waitSeconds,
            '@endpoint' => $endpoint,
          ]
        );

        sleep($waitSeconds);
        continue;
      }

      $this->loggerFactory->get('drupalbridge')->error(
        'HubSpot API error. Method: @method. Status: @status. Endpoint: @endpoint. Response: @response',
        [
          '@method'   => $method,
          '@status'   => $statusCode,
          '@endpoint' => $endpoint,
          '@response' => $responseBody,
        ]
      );

      throw $e;
    }
  }

  throw new \Exception(
    'HubSpot rate limit exceeded after ' . $this->maxRetries . ' attempts on ' . $endpoint
  );
}

}