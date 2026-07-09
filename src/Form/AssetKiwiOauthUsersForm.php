<?php

namespace Drupal\assetkiwi_connect\Form;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AssetKiwiOauthUsersForm extends FormBase {

  protected UserDataInterface $userData;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected DateFormatterInterface $dateFormatter;
  protected CsrfTokenGenerator $csrfToken;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->userData = $container->get('user.data');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->csrfToken = $container->get('csrf_token');
    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'assetkiwi_connect_oauth_users';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $users = $this->userData->get('assetkiwi_connect');
    if (empty($users)) {
      $form['empty_message'] = [
        '#type' => 'inline_template',
        '#template' => '<p>{{ "No users have authenticated with asset.kiwi yet."|t }}</p>',
      ];

      return $form;
    }

    $form['oauth_users'] = [
      '#type' => 'table',
      '#title' => $this->t('Authenticated users'),
      '#header' => [
        'revoke' => $this->t('Revoke'),
        'user' => $this->t('User'),
        'scopes' => $this->t('Scopes'),
        'expires_at' => $this->t('Expires at'),
      ],
      '#attributes' => [
        'id' => 'assetkiwi-connect-oauth-users-wrapper',
      ],
    ];

    $user_storage = $this->entityTypeManager->getStorage('user');
    foreach ($users as $uid => $user_data) {
      /** @var \Drupal\user\UserInterface $user */
      $user = $user_storage->load($uid);

      // Set the button's own attributes first, then apply the HTMX ones on
      // top — Htmx::applyTo() merges into an existing #attributes array
      // rather than replacing it, but only if #attributes is already there
      // to merge into.
      $revoke_button = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Revoke'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--danger'],
        ],
      ];
      // Compute the CSRF token directly via the csrf_token service rather
      // than relying on a '_csrf_token' route requirement: Htmx::post()
      // always generates its URL with cacheable-metadata collection turned
      // on, which makes Drupal's route CSRF processor emit a lazy-render
      // placeholder instead of a real token — and Htmx's own cache metadata
      // object can't carry that placeholder through to substitution, so the
      // literal placeholder hash ends up baked into the link and 403s.
      $revoke_token_value = 'assetkiwi_connect_oauth_revoke:' . $uid;
      $revoke_url = Url::fromRoute('assetkiwi_connect.oauth_users.revoke', ['uid' => $uid], [
        'query' => ['token' => $this->csrfToken->get($revoke_token_value)],
      ]);
      (new Htmx())
        ->post($revoke_url)
        ->confirm((string) $this->t('Are you sure you want to revoke this user?'))
        ->select('#assetkiwi-connect-oauth-users-wrapper')
        ->target('#assetkiwi-connect-oauth-users-wrapper')
        ->swap('outerHTML')
        // The outerHTML swap destroys this button (it's inside the row being
        // replaced) while it may still hold browser focus. Inside the
        // modal's jQuery UI dialog, that leaves the dialog's focus/tabbing
        // logic (dialog.jquery-ui.js) chasing a reference to an element that
        // no longer exists. Blurring before the request fires avoids that.
        ->on('click', 'this.blur()')
        ->applyTo($revoke_button);

      $form['oauth_users']['#rows'][] = [
        'data' => [
          'revoke' => ['data' => $revoke_button],
          'user' => $user->getDisplayName(),
          'scopes' => str_replace(' ', ', ', $user_data['oauth_scope']),
          'expires_at' => is_numeric($user_data['oauth_token_expires']) ? $this->dateFormatter->format($user_data['oauth_token_expires']) : $this->t('Undefined'),
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
