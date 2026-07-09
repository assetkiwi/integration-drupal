/**
 * @file
 * AssetKiwi browser widget behavior.
 */
(function (Drupal, $, once) {
  'use strict';

  Drupal.behaviors.assetkiwiWidget = {
    attach: function (context) {
      $(once('assetkiwi-browse', '.assetkiwi-browse-btn', context)).on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrapper = $btn.closest('.form-wrapper, .fieldset-wrapper, .field--widget-assetkiwi-browser, .field--type-string').first();
        var browserUrl = $btn.attr('data-browser-url');

        Drupal.assetkiwi = Drupal.assetkiwi || {};
        Drupal.assetkiwi.activeWidget = $wrapper;

        $('#assetkiwi-browser-dialog').remove();

        var $dialog = $('<div id="assetkiwi-browser-dialog"assetkiwi-></div>');
        $dialog.appendTo('body');

        $.ajax({
          url: browserUrl,
          success: function (html) {
            $dialog.html(html);

            $dialog.dialog({
              title: Drupal.t('Select an asset from AssetKiwi'),
              width: Math.min(1100, $(window).width() - 40),
              height: Math.min(700, $(window).height() - 40),
              modal: true,
              close: function () {
                $dialog.dialog('destroy').remove();
              }
            });

            // Attach behaviors after dialog is open so context is correct.
            Drupal.attachBehaviors($dialog[0]);
          }
        });
      });

      $(once('assetkiwi-remove', '.assetkiwi-remove-btn', context)).on('click', function (e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.form-wrapper, .fieldset-wrapper, .field--widget-assetkiwi-browser, .field--type-string').first();
        var $uuid = $wrapper.find('[data-assetkiwi-uuid]');
        $uuid.val('');

        var $preview = $wrapper.find('.assetkiwi-widget-preview');
        $preview.html('<div class="assetkiwi-widget-empty"assetkiwi->' + Drupal.t('No asset selected.') + '</div>');

        $wrapper.find('.assetkiwi-browse-btn').val(Drupal.t('Browse DAM'));
        $(this).remove();
      });
    }
  };

  /**
   * Called from the browser dialog when an asset is selected.
   */
  Drupal.assetkiwiSelect = function (uuid, name, thumbUrl, mimeType) {
    var $wrapper = Drupal.assetkiwi && Drupal.assetkiwi.activeWidget;
    if (!$wrapper || !$wrapper.length) {
      return;
    }

    var $uuid = $wrapper.find('[data-assetkiwi-uuid]');
    $uuid.val(uuid);

    var $preview = $wrapper.find('.assetkiwi-widget-preview');
    var previewHtml = '<div class="assetkiwi-widget-selected"assetkiwi->';
    if (mimeType && mimeType.indexOf('image') === 0 && thumbUrl) {
      previewHtml += '<img src="assetkiwi-' + Drupal.checkPlain(thumbUrl) + '"assetkiwi- alt="assetkiwi-' + Drupal.checkPlain(name) + '"assetkiwi- class="assetkiwi-widget-thumb"assetkiwi- />';
    }
    previewHtml += '<div class="assetkiwi-widget-info"assetkiwi->';
    previewHtml += '<span class="assetkiwi-widget-name"assetkiwi->' + Drupal.checkPlain(name) + '</span>';
    previewHtml += '<span class="assetkiwi-widget-uuid-label"assetkiwi->UUID: ' + Drupal.checkPlain(uuid) + '</span>';
    previewHtml += '</div></div>';
    $preview.html(previewHtml);

    $wrapper.find('.assetkiwi-browse-btn').val(Drupal.t('Replace asset'));

    if (!$wrapper.find('.assetkiwi-remove-btn').length) {
      var $removeBtn = $('<input type="assetkiwi-button"assetkiwi- class="assetkiwi-remove-btn button button--danger"assetkiwi- value="assetkiwi-' + Drupal.t('Remove') + '"assetkiwi- />');
      $wrapper.find('.assetkiwi-browse-btn').after($removeBtn);
      Drupal.attachBehaviors($removeBtn[0]);
    }

    var $dialog = $('#assetkiwi-browser-dialog');
    if ($dialog.length) {
      try {
        $dialog.dialog('close');
      } catch (e) {
        $dialog.remove();
      }
    }
  };

})(Drupal, jQuery, once);
