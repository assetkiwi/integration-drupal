<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\assetkiwi_connect\OAuth\OAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the OAuth2 callback from asset.kiwi's authorization server.
 */
class OAuthController extends ControllerBase {

  protected OAuthManager $oauthManager;

  public function __construct(OAuthManager $oauthManager) {
    $this->oauthManager = $oauthManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('assetkiwi_connect.oauth_manager'));
  }

  /**
   * Callback endpoint for the OAuth2 authorization code grant flow.
   *
   * The authorization server redirects the user here with ?code=...&state=...
   * after the user approves the authorization request.
   */
  public function callback(Request $request): RedirectResponse {
    $code = $request->query->get('code');
    $state = $request->query->get('state');

    if (!$code) {
      $this->messenger()->addError($this->t('Authorization failed. No authorization code was received.'));
      return $this->redirect('<front>');
    }

    $session = $request->getSession();
    $storedState = $session->get('assetkiwi_oauth_state');
    $codeVerifier = $session->get('assetkiwi_oauth_code_verifier');

    // Consume — never reuse.
    $session->remove('assetkiwi_oauth_state');
    $session->remove('assetkiwi_oauth_code_verifier');

    if (!$storedState || !hash_equals($storedState, (string) $state)) {
      $this->messenger()->addError($this->t('Invalid OAuth state. Please try again.'));
      return $this->redirect('<front>');
    }

    $tokenData = $this->oauthManager->exchangeCode($code, (string) $state, $codeVerifier ?? '');

    if (!$tokenData || empty($tokenData['access_token'])) {
      $this->messenger()->addError($this->t('Failed to connect to asset.kiwi. Please try again.'));
      return $this->redirect('<front>');
    }

    $this->oauthManager->storeToken((int) $this->currentUser()->id(), $tokenData);

    $this->messenger()->addStatus($this->t('Successfully connected to asset.kiwi.'));

    // Redirect back to the page the user was on when they started the flow.
    $session = $request->getSession();
    $returnTo = $session->get('assetkiwi_oauth_return_to', '/');
    $session->remove('assetkiwi_oauth_return_to');
    return new RedirectResponse($returnTo);
  }

}
