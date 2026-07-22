<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\OAuth;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserDataInterface;
use GuzzleHttp\ClientInterface;

/**
 * Manages the per-user OAuth2 flow for asset.kiwi.
 *
 * When oauth_mode is 'per_user', each Drupal user authenticates individually
 * against the asset.kiwi OAuth2 authorization server using the authorization
 * code grant with PKCE. Their access token is stored via UserDataInterface
 * and retrieved for API requests instead of the shared config token.
 */
class OAuthManager {

  protected ConfigFactoryInterface $configFactory;
  protected UserDataInterface $userData;
  protected AccountProxyInterface $currentUser;
  protected StateInterface $state;
  protected ClientInterface $httpClient;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    UserDataInterface $userData,
    AccountProxyInterface $currentUser,
    StateInterface $state,
    ClientInterface $httpClient,
  ) {
    $this->configFactory = $configFactory;
    $this->userData = $userData;
    $this->currentUser = $currentUser;
    $this->state = $state;
    $this->httpClient = $httpClient;
  }

  /**
   * Whether per-user OAuth mode is enabled.
   */
  public function isEnabled(): bool {
    return $this->configFactory->get('assetkiwi_connect.settings')->get('oauth_mode') === 'per_user';
  }

  /**
   * Builds the authorization URL for the current user to begin the OAuth flow.
   *
   * Generates the PKCE code verifier/challenge pair and an anti-CSRF state
   * token, stores them in the session, and returns the full authorization URL.
   *
   * @param string|null $returnTo
   *   An optional path to redirect back to after the OAuth callback completes.
   *   If null, the current request URI is stored as the return destination.
   */
  public function getAuthorizationUrl(?string $returnTo = NULL): string {
    $config = $this->configFactory->get('assetkiwi_connect.settings');

    $codeVerifier = $this->generateCodeVerifier();
    $codeChallenge = $this->generateCodeChallenge($codeVerifier);
    $state = \Drupal::csrfToken()->get('assetkiwi_oauth');

    // Store in session for the callback to verify and consume.
    $session = \Drupal::request()->getSession();
    $session->set('assetkiwi_oauth_code_verifier', $codeVerifier);
    $session->set('assetkiwi_oauth_state', $state);

    // Store return-to path so the callback can redirect the user back.
    if ($returnTo !== NULL) {
      $session->set('assetkiwi_oauth_return_to', $returnTo);
    }
    else {
      $session->set('assetkiwi_oauth_return_to', \Drupal::request()->getRequestUri());
    }

    $params = [
      'client_id' => $config->get('oauth_client_id'),
      'redirect_uri' => $this->getRedirectUri(),
      'response_type' => 'code',
      'scope' => implode(' ', $config->get('oauth_scopes') ?: ['assets:read']),
      'state' => $state,
      'code_challenge' => $codeChallenge,
      'code_challenge_method' => 'S256',
    ];

    return $this->getAuthorizeUrl($config) . '?' . http_build_query($params);
  }

  /**
   * Exchanges an authorization code for an access token.
   *
   * @return array|null
   *   The token data (access_token, expires_in, scope, etc.) or NULL on failure.
   */
  public function exchangeCode(string $code, string $state, string $codeVerifier): ?array {
    $config = $this->configFactory->get('assetkiwi_connect.settings');

    try {
      $response = $this->httpClient->post($this->getTokenUrl($config), [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'client_id' => $config->get('oauth_client_id'),
          'client_secret' => $config->get('oauth_client_secret'),
          'redirect_uri' => $this->getRedirectUri(),
          'code_verifier' => $codeVerifier,
        ],
      ]);

      return json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Stores an access token for the given user.
   */
  public function storeToken(int $uid, array $tokenData): void {
    $this->userData->set('assetkiwi_connect', $uid, 'oauth_access_token', $tokenData['access_token']);
    $this->userData->set('assetkiwi_connect', $uid, 'oauth_token_expires', time() + ($tokenData['expires_in'] ?? 3600));
    $this->userData->set('assetkiwi_connect', $uid, 'oauth_scope', $tokenData['scope'] ?? '');
  }

  /**
   * Retrieves the stored access token for a user, if still valid.
   *
   * @return string|null
   *   The access token, or NULL if absent or expired.
   */
  public function getToken(int $uid): ?string {
    $token = $this->userData->get('assetkiwi_connect', $uid, 'oauth_access_token');
    $expires = $this->userData->get('assetkiwi_connect', $uid, 'oauth_token_expires');

    if (!$token || ($expires && $expires < time())) {
      return NULL;
    }

    return $token;
  }

  /**
   * The redirect URI that the authorization server will call back to.
   *
   * Forces https rather than trusting Symfony's request-scheme detection
   * (Request::getSchemeAndHttpHost()), which reports http behind a
   * TLS-terminating reverse proxy unless Drupal's $settings['reverse_proxy']
   * trust config is set correctly — easy to get wrong, and this mismatch
   * silently breaks the OAuth flow since the registered Redirect URI on the
   * OAuth client won't match. Every real deployment of this module serves
   * over https, so hardcoding it here is safe and removes the dependency on
   * that infra config being right.
   */
  public function getRedirectUri(): string {
    return 'https://' . \Drupal::request()->getHttpHost() . '/assetkiwi/oauth/callback';
  }

  /**
   * The asset.kiwi OAuth2 authorization endpoint, derived from api_url.
   *
   * asset.kiwi's OAuth routes are fixed (/oauth/authorize, /oauth/token), so
   * there's no need to make the admin type them in separately — they'd just
   * be another way to get the DAM URL wrong.
   */
  protected function getAuthorizeUrl(ImmutableConfig $config): string {
    return rtrim((string) $config->get('api_url'), '/') . '/oauth/authorize';
  }

  /**
   * The asset.kiwi OAuth2 token endpoint, derived from api_url.
   */
  protected function getTokenUrl(ImmutableConfig $config): string {
    return rtrim((string) $config->get('api_url'), '/') . '/oauth/token';
  }

  /**
   * Generates a cryptographically random PKCE code verifier.
   */
  protected function generateCodeVerifier(): string {
    return bin2hex(random_bytes(32));
  }

  /**
   * Derives a PKCE S256 code challenge from the given verifier.
   */
  protected function generateCodeChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

}
