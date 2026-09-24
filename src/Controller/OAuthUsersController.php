<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\assetkiwi_connect\Form\AssetKiwiOauthUsersForm;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles row actions for the asset.kiwi OAuth users list.
 */
class OAuthUsersController extends ControllerBase {

  protected UserDataInterface $userData;
  protected CsrfTokenGenerator $csrfToken;

  public function __construct(UserDataInterface $userData, FormBuilderInterface $formBuilder, CsrfTokenGenerator $csrfToken) {
    $this->userData = $userData;
    $this->formBuilder = $formBuilder;
    $this->csrfToken = $csrfToken;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
      $container->get('form_builder'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Revokes a user's stored asset.kiwi OAuth token.
   *
   * The route only requires the 'administer assetkiwi' permission; the CSRF
   * token is checked here manually (see AssetKiwiOauthUsersForm, where it's
   * generated the same way) rather than via a '_csrf_token' route
   * requirement, because Htmx::post() always collects cacheable metadata
   * when building its URL, which makes Drupal's route CSRF processor emit an
   * unresolved lazy-render placeholder instead of a real token.
   *
   * Returns the freshly rebuilt users form so the caller's HTMX request can
   * select and swap in the updated #assetkiwi-connect-oauth-users-wrapper.
   */
  public function revoke(int $uid, Request $request): array {
    if (!$this->csrfToken->validate($request->query->get('token', ''), 'assetkiwi_connect_oauth_revoke:' . $uid)) {
      throw new AccessDeniedHttpException();
    }

    $this->userData->delete('assetkiwi_connect', $uid);
    return $this->formBuilder->getForm(AssetKiwiOauthUsersForm::class);
  }

}
