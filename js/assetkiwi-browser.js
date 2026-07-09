/**
 * @file
 * AssetKiwi browser behavior (works both standalone and inside modal dialog).
 */
(function (Drupal, $, once) {
  'use strict';

  Drupal.behaviors.assetkiwiBrowser = {
    attach: function (context) {
      $(once('assetkiwi-select', '.js-assetkiwi-select', context)).on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var uuid = $btn.attr('data-uuid');
        var name = $btn.attr('data-name');
        var mime = $btn.attr('data-mime');
        var url = $btn.attr('data-url');

        var thumbUrl = '';
        var $img = $btn.closest('.assetkiwi-browser__card').find('.assetkiwi-browser__thumbnail');
        if ($img.length) {
          thumbUrl = $img.attr('src');
        }

        if (typeof Drupal.assetkiwiSelect === 'function') {
          Drupal.assetkiwiSelect(uuid, name, thumbUrl || url, mime);
        }
      });

      // Find the dialog context: it may have been created after page load.
      var $dialog = $(context).closest('#assetkiwi-browser-dialog');
      if (!$dialog.length) {
        $dialog = $(context).find('#assetkiwi-browser-dialog');
      }
      if (!$dialog.length) {
        $dialog = $('#assetkiwi-browser-dialog');
      }

      if ($dialog.length) {
        $(once('assetkiwi-pager', '.assetkiwi-browser__pager a', context)).on('click', function (e) {
          e.preventDefault();
          var href = $(this).attr('href');
          // Append modal=1 so the response omits the full page chrome.
          var separator = href.indexOf('?') !== -1 ? '&' : '?';
          href += separator + 'modal=1';
          $.ajax({
            url: href,
            success: function (html) {
              $dialog.html(html);
              Drupal.attachBehaviors($dialog[0]);
            }
          });
        });

        $(once('assetkiwi-filter-form', '.assetkiwi-browser__filters form', context)).on('submit', function (e) {
          e.preventDefault();
          var formData = $(this).serialize();
          $.ajax({
            url: '/admin/assetkiwi/browse?' + formData + '&modal=1',
            success: function (html) {
              $dialog.html(html);
              Drupal.attachBehaviors($dialog[0]);
            }
          });
        });

        $(once('assetkiwi-reset', '.assetkiwi-browser__filters a.button', context)).on('click', function (e) {
          e.preventDefault();
          $.ajax({
            url: '/admin/assetkiwi/browse?modal=1',
            success: function (html) {
              $dialog.html(html);
              Drupal.attachBehaviors($dialog[0]);
            }
          });
        });

        // Multi-select checkbox handling.
        $(once('assetkiwi-select-multi', '.js-assetkiwi-select-multi', context)).on('change', function () {
          var $toolbar = $dialog.find('.assetkiwi-browser__multiselect-toolbar');
          var $checked = $dialog.find('.js-assetkiwi-select-multi:checked');
          var count = $checked.length;

          $toolbar.find('.assetkiwi-browser__selected-count').text(count);

          if (count > 0) {
            $toolbar.show();
          } else {
            $toolbar.hide();
          }

          // Toggle visual selection state on cards.
          $dialog.find('.assetkiwi-browser__card').removeClass('is-selecting');
          $checked.each(function () {
            $(this).closest('.assetkiwi-browser__card').addClass('is-selecting');
          });
        });

        // Import selected button.
        $(once('assetkiwi-import-selected', '.js-assetkiwi-import-selected', context)).on('click', function () {
          var $checked = $dialog.find('.js-assetkiwi-select-multi:checked');
          var uuids = [];
          var names = [];

          $checked.each(function () {
            uuids.push($(this).attr('data-uuid'));
            names.push($(this).attr('data-name'));
          });

          if (uuids.length === 0) {
            return;
          }

          // jQuery serialises { uuids: [...] } as uuids[]=a&uuids[]=b by default,
          // which PHP's form parser converts to $_POST['uuids'] = ['a', 'b'].
          $.ajax({
            url: '/admin/assetkiwi/select-multiple',
            method: 'POST',
            data: { uuids: uuids },
            success: function (response) {
              if (response.assets) {
                response.assets.forEach(function (asset) {
                  var uuid = asset.uuid || asset.id;
                  var name = asset.original_name || asset.name || 'Untitled';
                  var mime = asset.mime_type || asset.mimeType || '';
                  var thumbUrl = '';
                  if (asset.variants) {
                    asset.variants.forEach(function (v) {
                      if (v.variant_name === 'thumb') {
                        thumbUrl = v.url;
                      }
                    });
                  }

                  // Call the selection callback for each asset.
                  if (typeof Drupal.assetkiwiSelect === 'function') {
                    Drupal.assetkiwiSelect(uuid, name, thumbUrl || asset.url || '', mime);
                  }
                });

                // Close the dialog after import.
                var $dlg = $('#assetkiwi-browser-dialog');
                if ($dlg.length) {
                  try {
                    $dlg.dialog('close');
                  } catch (e) {
                    $dlg.remove();
                  }
                }
              }
            },
            error: function (xhr) {
              var msg = Drupal.t('Failed to import assets. Server returned @status', {
                '@status': xhr.status + ' ' + xhr.statusText
              });
              var $toolbar = $dialog.find('.assetkiwi-browser__multiselect-toolbar');
              $toolbar.after('<div class="assetkiwi-messages messages--error"assetkiwi- role="assetkiwi-alert"assetkiwi->' + msg + '</div>');
            }
          });
        });

        // Per-page selector.
        $(once('assetkiwi-per-page', '.assetkiwi-browser__per-page', context)).on('change', function () {
          var perPage = $(this).val();
          var params = new URLSearchParams(window.location.search);
          params.set('per_page', perPage);
          params.set('modal', '1');

          $.ajax({
            url: '/admin/assetkiwi/browse?' + params.toString(),
            success: function (html) {
              $dialog.html(html);
              Drupal.attachBehaviors($dialog[0]);
            }
          });
        });

        // Search mode selector – auto-submit on change.
        $(once('assetkiwi-mode', '#edit-mode', context)).on('change', function () {
          var $form = $(this).closest('form');
          $form.submit();
        });
      }
    }
  };

})(Drupal, jQuery, once);
