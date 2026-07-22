/**
 * @file
 * Opens the asset.kiwi picker in a dialog and writes selected UUIDs to the hidden field.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.assetkiwiWidget = {
    attach: function (context) {
      once('assetkiwi-browse', '.assetkiwi-browse-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          openPicker(btn);
        });
      });

      once('assetkiwi-remove', '.assetkiwi-remove-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          removeSelection(btn);
        });
      });
    },
  };

  function getWrapper(el) {
    return (
      el.closest('.form-wrapper, .fieldset-wrapper, .field--widget-assetkiwi-browser, .field--type-string') ||
      el.closest('.js-form-item') ||
      el.parentElement
    );
  }

  function openPicker(btn) {
    var wrapper = getWrapper(btn);
    var config = JSON.parse(btn.getAttribute('data-assetkiwi-picker') || '{}');

    // If OAuth is required and the user hasn't connected, redirect to
    // the authorization URL instead of opening the broken picker.
    var widgetEl = wrapper.closest('[data-assetkiwi-oauth-required]');
    if (widgetEl) {
      var oauthRequired = widgetEl.getAttribute('data-assetkiwi-oauth-required') === 'true';
      var oauthUrl = widgetEl.getAttribute('data-assetkiwi-oauth-url');
      if (oauthRequired && oauthUrl) {
        window.location.href = oauthUrl;
        return;
      }
    }

    var container = document.createElement('div');
    var dialog = Drupal.dialog(container, {
      title: Drupal.t('Select an asset from asset.kiwi'),
      width: Math.min(1100, window.innerWidth - 40),
      height: Math.min(700, window.innerHeight - 40),
      dialogClass: 'assetkiwi-picker-dialog',
      close: function () {
        container.remove();
      },
    });

    Drupal.AssetKiwiPicker.mount(container, {
      mode: 'single',
      allowedTypes: config.allowedTypes || [],
      endpoints: config.endpoints || {},
      onSelect: function (assets) {
        var asset = assets[0];
        if (asset) {
          applySelection(wrapper, asset);
        }
        dialog.close();
      },
    });

    dialog.showModal();
  }

  function applySelection(wrapper, asset) {
    var hidden = wrapper.querySelector('[data-assetkiwi-uuid]');
    if (hidden) {
      hidden.value = asset.uuid;
    }

    var preview = wrapper.querySelector('.assetkiwi-widget-preview');
    if (preview) {
      preview.innerHTML = '';

      var selected = document.createElement('div');
      selected.className = 'assetkiwi-widget-selected';

      if (asset.mime && asset.mime.indexOf('image') === 0 && asset.thumbUrl) {
        var img = document.createElement('img');
        img.src = asset.thumbUrl;
        img.alt = asset.name || '';
        img.className = 'assetkiwi-widget-thumb';
        selected.appendChild(img);
      }

      var info = document.createElement('div');
      info.className = 'assetkiwi-widget-info';

      var name = document.createElement('span');
      name.className = 'assetkiwi-widget-name';
      name.textContent = asset.name || '';
      info.appendChild(name);

      var uuidLabel = document.createElement('span');
      uuidLabel.className = 'assetkiwi-widget-uuid-label';
      uuidLabel.textContent = 'UUID: ' + asset.uuid;
      info.appendChild(uuidLabel);

      selected.appendChild(info);
      preview.appendChild(selected);
    }

    var browseBtn = wrapper.querySelector('.assetkiwi-browse-btn');
    if (browseBtn) {
      browseBtn.value = Drupal.t('Replace asset');

      if (!wrapper.querySelector('.assetkiwi-remove-btn')) {
        var removeBtn = document.createElement('input');
        removeBtn.type = 'button';
        removeBtn.className = 'assetkiwi-remove-btn button button--danger';
        removeBtn.value = Drupal.t('Remove');
        // Fresh element — bind directly without once().
        removeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          removeSelection(removeBtn);
        });
        browseBtn.insertAdjacentElement('afterend', removeBtn);
      }
    }
  }

  function removeSelection(btn) {
    var wrapper = getWrapper(btn);

    var hidden = wrapper.querySelector('[data-assetkiwi-uuid]');
    if (hidden) {
      hidden.value = '';
    }

    var preview = wrapper.querySelector('.assetkiwi-widget-preview');
    if (preview) {
      preview.innerHTML = '';
      var empty = document.createElement('div');
      empty.className = 'assetkiwi-widget-empty';
      empty.textContent = Drupal.t('No asset selected.');
      preview.appendChild(empty);
    }

    var browseBtn = wrapper.querySelector('.assetkiwi-browse-btn');
    if (browseBtn) {
      browseBtn.value = Drupal.t('Browse DAM');
    }

    btn.remove();
  }

})(Drupal, once);
