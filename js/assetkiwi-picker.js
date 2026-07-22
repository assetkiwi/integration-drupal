/**
 * @file
 * asset.kiwi picker widget.
 *
 * Mounts into a host container, drives search/filter/pagination via JSON proxy
 * endpoints so the API token never reaches the browser.
 *
 * Usage:
 *   var picker = Drupal.AssetKiwiPicker.mount(container, { mode: 'single'|'multi', ... });
 *   picker.destroy();
 */
(function (Drupal, once) {
  'use strict';

  var DEFAULT_TYPE_LABELS = {
    image: Drupal.t('Images'),
    video: Drupal.t('Video'),
    audio: Drupal.t('Audio'),
    document: Drupal.t('Documents'),
  };

  function debounce(fn, wait) {
    var timeout;
    return function () {
      var args = arguments;
      var context = this;
      clearTimeout(timeout);
      timeout = setTimeout(function () {
        fn.apply(context, args);
      }, wait);
    };
  }

  function formatBytes(bytes) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) {
      return '';
    }
    var units = ['B', 'KB', 'MB', 'GB'];
    var n = Number(bytes);
    var i = 0;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return (i > 0 ? n.toFixed(1) : Math.round(n)) + ' ' + units[i];
  }

  /**
   * One mounted picker instance. Not exported directly — use .mount().
   */
  function AssetKiwiPickerInstance(container, options) {
    this.container = container;
    this.options = Object.assign(
      {
        mode: 'single',
        allowedTypes: [],
        bundle: '',
        // 0 or negative means unlimited.
        maxSelections: 0,
        endpoints: {},
        onSelect: function () {},
        onCancel: function () {},
      },
      options
    );

    this.state = {
      filters: { search: '', type: '', collection: '', tag: '', mode: '', page: 1, per_page: 24 },
      facets: { collections: [], tags: [] },
      items: [],
      pager: { current_page: 1, last_page: 1, total: 0 },
      selected: new Map(),
      requestSeq: 0,
    };
    this._pendingFocusGrid = false;
    this._uid = 'ak-picker-' + Math.random().toString(36).slice(2, 9);

    this._buildSkeleton();
    this._loadFacets();
    this._fetchAssets();
  }

  AssetKiwiPickerInstance.prototype.destroy = function () {
    this.container.innerHTML = '';
  };

  AssetKiwiPickerInstance.prototype._id = function (suffix) {
    return this._uid + '-' + suffix;
  };

  AssetKiwiPickerInstance.prototype._buildSkeleton = function () {
    var multi = this.options.mode === 'multi';

    this.container.classList.add('assetkiwi-picker');
    this.container.innerHTML =
      '<div class="assetkiwi-picker__filters">' +
        '<div class="assetkiwi-picker__field">' +
          '<label for="' + this._id('search') + '">' + Drupal.t('Search') + '</label>' +
          '<input type="search" id="' + this._id('search') + '" class="assetkiwi-picker__search" placeholder="' + Drupal.t('Keywords…') + '" />' +
        '</div>' +
        '<div class="assetkiwi-picker__field" data-field="collection" hidden>' +
          '<label for="' + this._id('collection') + '">' + Drupal.t('Collection') + '</label>' +
          '<select id="' + this._id('collection') + '" class="assetkiwi-picker__collection"></select>' +
        '</div>' +
        '<div class="assetkiwi-picker__field" data-field="tag" hidden>' +
          '<label for="' + this._id('tag') + '">' + Drupal.t('Tag') + '</label>' +
          '<select id="' + this._id('tag') + '" class="assetkiwi-picker__tag"></select>' +
        '</div>' +
        '<div class="assetkiwi-picker__field" data-field="type" hidden>' +
          '<label for="' + this._id('type') + '">' + Drupal.t('Type') + '</label>' +
          '<select id="' + this._id('type') + '" class="assetkiwi-picker__type"></select>' +
        '</div>' +
        '<div class="assetkiwi-picker__field">' +
          '<label for="' + this._id('mode') + '">' + Drupal.t('Search mode') + '</label>' +
          '<select id="' + this._id('mode') + '" class="assetkiwi-picker__mode">' +
            '<option value="">' + Drupal.t('Keyword') + '</option>' +
            '<option value="semantic">' + Drupal.t('Semantic') + '</option>' +
            '<option value="hybrid">' + Drupal.t('Hybrid') + '</option>' +
          '</select>' +
        '</div>' +
      '</div>' +
      '<div class="assetkiwi-picker__body">' +
        '<ul class="assetkiwi-picker__grid" role="listbox" tabindex="-1" aria-multiselectable="' + (multi ? 'true' : 'false') + '" aria-label="' + Drupal.t('Assets') + '"></ul>' +
      '</div>' +
      '<div class="assetkiwi-picker__pager"></div>' +
      (multi
        ? '<div class="assetkiwi-picker__toolbar" hidden>' +
            '<span class="assetkiwi-picker__count">0</span> ' + Drupal.t('selected') +
            '<button type="button" class="button assetkiwi-picker__clear">' + Drupal.t('Clear') + '</button>' +
            '<button type="button" class="button button--primary assetkiwi-picker__confirm">' + Drupal.t('Use selected') + '</button>' +
          '</div>'
        : '');

    this._els = {
      search: this.container.querySelector('.assetkiwi-picker__search'),
      collectionField: this.container.querySelector('[data-field="collection"]'),
      collection: this.container.querySelector('.assetkiwi-picker__collection'),
      tagField: this.container.querySelector('[data-field="tag"]'),
      tag: this.container.querySelector('.assetkiwi-picker__tag'),
      typeField: this.container.querySelector('[data-field="type"]'),
      type: this.container.querySelector('.assetkiwi-picker__type'),
      mode: this.container.querySelector('.assetkiwi-picker__mode'),
      grid: this.container.querySelector('.assetkiwi-picker__grid'),
      pager: this.container.querySelector('.assetkiwi-picker__pager'),
      toolbar: this.container.querySelector('.assetkiwi-picker__toolbar'),
      count: this.container.querySelector('.assetkiwi-picker__count'),
      confirm: this.container.querySelector('.assetkiwi-picker__confirm'),
      clear: this.container.querySelector('.assetkiwi-picker__clear'),
    };

    this._populateTypeOptions();

    var self = this;

    this._els.search.addEventListener(
      'input',
      debounce(function () {
        self.state.filters.search = self._els.search.value;
        self.state.filters.page = 1;
        self._fetchAssets();
      }, 300)
    );

    this._els.collection.addEventListener('change', function () {
      self.state.filters.collection = self._els.collection.value;
      self.state.filters.page = 1;
      self._fetchAssets();
    });
    this._els.tag.addEventListener('change', function () {
      self.state.filters.tag = self._els.tag.value;
      self.state.filters.page = 1;
      self._fetchAssets();
    });
    this._els.type.addEventListener('change', function () {
      self.state.filters.type = self._els.type.value;
      self.state.filters.page = 1;
      self._fetchAssets();
    });
    this._els.mode.addEventListener('change', function () {
      self.state.filters.mode = self._els.mode.value;
      self.state.filters.page = 1;
      self._fetchAssets();
    });

    if (this._els.confirm) {
      this._els.confirm.addEventListener('click', function () {
        self._confirmMulti();
      });
    }
    if (this._els.clear) {
      this._els.clear.addEventListener('click', function () {
        self._clearSelection();
      });
    }

    this._els.grid.addEventListener('keydown', function (e) {
      self._onGridKeydown(e);
    });
  };

  AssetKiwiPickerInstance.prototype._populateTypeOptions = function () {
    var allowed = this.options.allowedTypes || [];
    var showType = allowed.length !== 1;
    this._els.typeField.hidden = !showType;

    this._els.type.innerHTML = '';
    var anyOpt = document.createElement('option');
    anyOpt.value = '';
    anyOpt.textContent = Drupal.t('— Any type —');
    this._els.type.appendChild(anyOpt);

    var keys = allowed.length ? allowed : Object.keys(DEFAULT_TYPE_LABELS);
    keys.forEach(function (key) {
      if (!DEFAULT_TYPE_LABELS[key]) {
        return;
      }
      var opt = document.createElement('option');
      opt.value = key;
      opt.textContent = DEFAULT_TYPE_LABELS[key];
      this._els.type.appendChild(opt);
    }, this);

    if (allowed.length === 1) {
      this.state.filters.type = allowed[0];
    }
  };

  AssetKiwiPickerInstance.prototype._loadFacets = function () {
    var self = this;
    if (!this.options.endpoints.facets) {
      return;
    }
    fetch(this.options.endpoints.facets, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return {};
          })
          .then(function (body) {
            return { ok: res.ok, body: body };
          });
      })
      .then(function (result) {
        if (!result) {
          return;
        }
        if (result.body.error === 'oauth_required') {
          self.state.oauthRequired = true;
          self.state.oauthAuthorizeUrl = result.body.authorize_url;
          self.state.oauthMessage = result.body.message || Drupal.t('Your asset.kiwi account is not connected.');
          self._render();
          return;
        }
        if (!result.ok) {
          return;
        }
        self.state.facets.collections = result.body.collections || [];
        self.state.facets.tags = result.body.tags || [];
        self._renderFacetOptions();
      })
      .catch(function () {
        // Non-fatal: collection/tag filters simply stay hidden.
      });
  };

  AssetKiwiPickerInstance.prototype._renderFacetOptions = function () {
    var collections = this.state.facets.collections;
    var tags = this.state.facets.tags;

    this._els.collectionField.hidden = collections.length === 0;
    this._els.tagField.hidden = tags.length === 0;

    this._els.collection.innerHTML = '';
    var anyCol = document.createElement('option');
    anyCol.value = '';
    anyCol.textContent = Drupal.t('— Any collection —');
    this._els.collection.appendChild(anyCol);
    collections.forEach(function (c) {
      var opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      this._els.collection.appendChild(opt);
    }, this);

    this._els.tag.innerHTML = '';
    var anyTag = document.createElement('option');
    anyTag.value = '';
    anyTag.textContent = Drupal.t('— Any tag —');
    this._els.tag.appendChild(anyTag);
    tags.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.name;
      this._els.tag.appendChild(opt);
    }, this);
  };

  AssetKiwiPickerInstance.prototype._fetchAssets = function () {
    var self = this;
    var seq = ++this.state.requestSeq;

    this._els.grid.setAttribute('aria-busy', 'true');
    this._els.grid.classList.add('is-loading');

    var f = this.state.filters;
    var params = new URLSearchParams();
    if (f.search) params.set('search', f.search);
    if (f.type) params.set('type', f.type);
    if (f.collection) params.set('collection', f.collection);
    if (f.tag) params.set('tag', f.tag);
    if (f.mode) params.set('mode', f.mode);
    params.set('page', f.page);
    params.set('per_page', f.per_page);
    if (this.options.allowedTypes && this.options.allowedTypes.length) {
      params.set('allowed_types', this.options.allowedTypes.join(','));
    }
    if (this.options.bundle) {
      params.set('bundle', this.options.bundle);
    }

    fetch(this.options.endpoints.assets + '?' + params.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return {};
          })
          .then(function (body) {
            return { ok: res.ok, body: body };
          });
      })
      .then(function (result) {
        if (seq !== self.state.requestSeq) {
          return; // A newer request superseded this one.
        }
        if (!result.ok || result.body.error) {
          if (result.body.error === 'oauth_required' && result.body.authorize_url) {
            self.state.oauthRequired = true;
            self.state.oauthAuthorizeUrl = result.body.authorize_url;
            self.state.oauthMessage = result.body.message || Drupal.t('Your asset.kiwi account is not connected.');
          }
          else {
            self.state.error = result.body.error || Drupal.t('Could not load assets from asset.kiwi.');
          }
          self.state.items = [];
          self.state.pager = { current_page: 1, last_page: 1, total: 0 };
        }
        else {
          self.state.oauthRequired = false;
          self.state.error = null;
          self.state.items = result.body.data || [];
          self.state.pager = result.body.meta || { current_page: 1, last_page: 1, total: self.state.items.length };
        }
        self._render();
      })
      .catch(function () {
        if (seq !== self.state.requestSeq) {
          return;
        }
        self.state.error = Drupal.t('Could not load assets from asset.kiwi.');
        self.state.items = [];
        self._render();
      });
  };

  AssetKiwiPickerInstance.prototype._render = function () {
    var items = this.state.items;
    var error = this.state.error;
    var pager = this.state.pager;

    this._els.grid.removeAttribute('aria-busy');
    this._els.grid.classList.remove('is-loading');
    this._els.grid.innerHTML = '';

    if (this.state.oauthRequired) {
      var connectDiv = document.createElement('div');
      connectDiv.className = 'assetkiwi-picker__oauth-connect';

      var icon = document.createElement('div');
      icon.className = 'assetkiwi-picker__oauth-icon';
      icon.innerHTML = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>';
      connectDiv.appendChild(icon);

      var message = document.createElement('p');
      message.className = 'assetkiwi-picker__oauth-message';
      message.textContent = this.state.oauthMessage || Drupal.t('Your asset.kiwi account is not connected.');
      connectDiv.appendChild(message);

      var button = document.createElement('a');
      button.href = this.state.oauthAuthorizeUrl;
      button.className = 'assetkiwi-picker__oauth-button button button--primary';
      button.textContent = Drupal.t('Connect to asset.kiwi');
      connectDiv.appendChild(button);

      this._els.grid.appendChild(connectDiv);

      // Hide search bar and filter controls since they won't work without auth.
      if (this._els.search) { this._els.search.parentElement.style.display = 'none'; }
      if (this._els.mode) { this._els.mode.parentElement.style.display = 'none'; }
      if (this._els.collectionField) { this._els.collectionField.style.display = 'none'; }
      if (this._els.tagField) { this._els.tagField.style.display = 'none'; }
      if (this._els.typeField) { this._els.typeField.style.display = 'none'; }

      this._els.pager.innerHTML = '';
      this._updateToolbar();
      return;
    }

    if (error) {
      var errLi = document.createElement('li');
      errLi.className = 'assetkiwi-picker__error';
      errLi.setAttribute('role', 'presentation');
      errLi.textContent = error;
      this._els.grid.appendChild(errLi);
      Drupal.announce(error, 'assertive');
      this._els.pager.innerHTML = '';
      this._updateToolbar();
      return;
    }

    if (!items.length) {
      var emptyLi = document.createElement('li');
      emptyLi.className = 'assetkiwi-picker__empty';
      emptyLi.setAttribute('role', 'presentation');
      emptyLi.textContent = Drupal.t('No assets found. Try adjusting your filters.');
      this._els.grid.appendChild(emptyLi);
      Drupal.announce(Drupal.t('No assets found.'));
    }
    else {
      items.forEach(function (asset) {
        this._els.grid.appendChild(this._renderCard(asset));
      }, this);

      var options = this._els.grid.querySelectorAll('[role="option"]');
      options.forEach(function (el, i) {
        el.setAttribute('tabindex', i === 0 ? '0' : '-1');
      });

      Drupal.announce(Drupal.formatPlural(pager.total, '1 asset found.', '@count assets found.'));
    }

    this._renderPager();
    this._updateToolbar();
    this._updateSelectionLimitState();

    if (this._pendingFocusGrid) {
      this._pendingFocusGrid = false;
      var first = this._els.grid.querySelector('[role="option"]');
      if (first) {
        first.focus();
      }
      else {
        this._els.grid.focus();
      }
    }
  };

  AssetKiwiPickerInstance.prototype._renderCard = function (asset) {
    var self = this;
    var li = document.createElement('li');
    li.className = 'assetkiwi-picker__card';
    li.setAttribute('role', 'option');
    li.setAttribute('tabindex', '-1');
    li.dataset.uuid = asset.uuid;
    var selected = this.state.selected.has(asset.uuid);
    li.setAttribute('aria-selected', selected ? 'true' : 'false');
    if (selected) {
      li.classList.add('is-selected');
    }

    var preview = document.createElement('div');
    preview.className = 'assetkiwi-picker__card-preview';
    if (asset.mime && asset.mime.indexOf('image') === 0 && asset.thumbUrl) {
      var img = document.createElement('img');
      img.src = asset.thumbUrl;
      img.alt = '';
      img.loading = 'lazy';
      img.className = 'assetkiwi-picker__thumbnail';
      preview.appendChild(img);
    }
    else {
      var icon = document.createElement('div');
      icon.className = 'assetkiwi-picker__file-icon';
      icon.textContent = (asset.mime || 'file').split('/').pop();
      preview.appendChild(icon);
    }
    li.appendChild(preview);

    if (asset.existingMediaId) {
      var badge = document.createElement('span');
      badge.className = 'assetkiwi-picker__badge';
      badge.textContent = Drupal.t('Already added');
      li.appendChild(badge);
    }

    var body = document.createElement('div');
    body.className = 'assetkiwi-picker__card-body';
    var name = document.createElement('p');
    name.className = 'assetkiwi-picker__asset-name';
    name.title = asset.name || '';
    name.textContent = asset.name || Drupal.t('Untitled');
    body.appendChild(name);
    if (asset.size) {
      var meta = document.createElement('p');
      meta.className = 'assetkiwi-picker__asset-meta';
      meta.textContent = formatBytes(asset.size);
      body.appendChild(meta);
    }
    li.appendChild(body);

    if (this.options.mode === 'multi') {
      var checkWrap = document.createElement('div');
      checkWrap.className = 'assetkiwi-picker__card-select';
      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'assetkiwi-picker__checkbox';
      checkbox.checked = selected;
      checkbox.setAttribute('aria-label', Drupal.t('Select @name', { '@name': asset.name || Drupal.t('Untitled') }));
      checkbox.addEventListener('click', function (e) {
        e.stopPropagation();
      });
      checkbox.addEventListener('change', function () {
        self._toggleSelection(asset, li);
      });
      checkWrap.appendChild(checkbox);
      li.appendChild(checkWrap);
    }

    li.addEventListener('click', function () {
      self._activateCard(asset, li);
    });

    return li;
  };

  AssetKiwiPickerInstance.prototype._activateCard = function (asset, li) {
    if (this.options.mode === 'multi') {
      this._toggleSelection(asset, li);
    }
    else {
      this.options.onSelect([asset]);
    }
  };

  AssetKiwiPickerInstance.prototype._toggleSelection = function (asset, li) {
    var cb = li.querySelector('.assetkiwi-picker__checkbox');
    var max = this.options.maxSelections;

    if (this.state.selected.has(asset.uuid)) {
      this.state.selected.delete(asset.uuid);
      li.setAttribute('aria-selected', 'false');
      li.classList.remove('is-selected');
      if (cb) cb.checked = false;
    }
    else {
      if (max > 0 && this.state.selected.size >= max) {
        if (cb) cb.checked = false;
        Drupal.announce(
          Drupal.formatPlural(max, 'You can only select 1 item for this field.', 'You can only select @count items for this field.'),
          'assertive'
        );
        return;
      }
      this.state.selected.set(asset.uuid, asset);
      li.setAttribute('aria-selected', 'true');
      li.classList.add('is-selected');
      if (cb) cb.checked = true;
    }
    this._updateToolbar();
    this._updateSelectionLimitState();
  };

  AssetKiwiPickerInstance.prototype._updateToolbar = function () {
    if (!this._els.toolbar) {
      return;
    }
    var count = this.state.selected.size;
    var max = this.options.maxSelections;
    this._els.toolbar.hidden = count === 0;
    if (this._els.count) {
      this._els.count.textContent = max > 0 ? count + ' / ' + max : String(count);
    }
  };

  /**
   * Disables not-yet-selected checkboxes once maxSelections is reached, so
   * the user can't check more than the field's cardinality allows.
   */
  AssetKiwiPickerInstance.prototype._updateSelectionLimitState = function () {
    var max = this.options.maxSelections;
    if (max <= 0) {
      return;
    }
    var atLimit = this.state.selected.size >= max;
    var options = this._els.grid.querySelectorAll('[role="option"]');
    options.forEach(function (li) {
      var cb = li.querySelector('.assetkiwi-picker__checkbox');
      if (!cb) {
        return;
      }
      var isSelected = li.getAttribute('aria-selected') === 'true';
      cb.disabled = atLimit && !isSelected;
      li.classList.toggle('is-disabled', atLimit && !isSelected);
    });
  };

  AssetKiwiPickerInstance.prototype._confirmMulti = function () {
    var assets = Array.from(this.state.selected.values());
    if (!assets.length) {
      return;
    }
    this.options.onSelect(assets);
  };

  AssetKiwiPickerInstance.prototype._clearSelection = function () {
    this.state.selected.clear();
    var options = this._els.grid.querySelectorAll('[role="option"]');
    options.forEach(function (li) {
      li.setAttribute('aria-selected', 'false');
      li.classList.remove('is-selected');
      var cb = li.querySelector('.assetkiwi-picker__checkbox');
      if (cb) cb.checked = false;
    });
    this._updateToolbar();
  };

  AssetKiwiPickerInstance.prototype._renderPager = function () {
    var current = this.state.pager.current_page || 1;
    var last = this.state.pager.last_page || 1;
    var total = this.state.pager.total || 0;
    var self = this;

    this._els.pager.innerHTML = '';
    if (last <= 1) {
      return;
    }

    var prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'button assetkiwi-picker__pager-prev';
    prev.textContent = Drupal.t('‹ Previous');
    prev.disabled = current <= 1;
    prev.addEventListener('click', function () {
      self.state.filters.page = Math.max(1, current - 1);
      self._pendingFocusGrid = true;
      self._fetchAssets();
    });

    var info = document.createElement('span');
    info.className = 'assetkiwi-picker__pager-info';
    info.textContent = Drupal.t('Page @current of @last (@total total)', {
      '@current': current,
      '@last': last,
      '@total': total,
    });

    var next = document.createElement('button');
    next.type = 'button';
    next.className = 'button assetkiwi-picker__pager-next';
    next.textContent = Drupal.t('Next ›');
    next.disabled = current >= last;
    next.addEventListener('click', function () {
      self.state.filters.page = current + 1;
      self._pendingFocusGrid = true;
      self._fetchAssets();
    });

    this._els.pager.appendChild(prev);
    this._els.pager.appendChild(info);
    this._els.pager.appendChild(next);
  };

  AssetKiwiPickerInstance.prototype._onGridKeydown = function (e) {
    var options = Array.prototype.slice.call(this._els.grid.querySelectorAll('[role="option"]'));
    if (!options.length) {
      return;
    }
    var currentIndex = options.indexOf(document.activeElement);
    var nextIndex = null;

    switch (e.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        nextIndex = currentIndex < 0 ? 0 : Math.min(options.length - 1, currentIndex + 1);
        break;

      case 'ArrowLeft':
      case 'ArrowUp':
        nextIndex = currentIndex < 0 ? 0 : Math.max(0, currentIndex - 1);
        break;

      case 'Home':
        nextIndex = 0;
        break;

      case 'End':
        nextIndex = options.length - 1;
        break;

      case 'Enter':
      case ' ':
        e.preventDefault();
        if (currentIndex >= 0) {
          var uuid = options[currentIndex].dataset.uuid;
          var asset = this.state.items.filter(function (a) {
            return a.uuid === uuid;
          })[0];
          if (asset) {
            this._activateCard(asset, options[currentIndex]);
          }
        }
        return;

      default:
        return;
    }

    if (nextIndex !== null) {
      e.preventDefault();
      options.forEach(function (el) {
        el.setAttribute('tabindex', '-1');
      });
      options[nextIndex].setAttribute('tabindex', '0');
      options[nextIndex].focus();
    }
  };

  Drupal.AssetKiwiPicker = Drupal.AssetKiwiPicker || {};

  /**
   * Mounts a new picker instance into the given container element.
   *
   * @param {HTMLElement} container
   *   An empty container element to render into.
   * @param {Object} options
   *   mode: 'single'|'multi', allowedTypes: string[],
   *   endpoints: {assets, facets}, onSelect: function(assets[]).
   *
   * @return {Object}
   *   An instance with a .destroy() method.
   */
  Drupal.AssetKiwiPicker.mount = function (container, options) {
    return new AssetKiwiPickerInstance(container, options);
  };

  /** Submit to Drupal's ajax.js via jQuery or native fallback. */
  function fireAjaxTrigger(el) {
    if (window.jQuery) {
      window.jQuery(el).trigger('mousedown').trigger('mouseup').trigger('click');
      return;
    }
    ['mousedown', 'mouseup', 'click'].forEach(function (type) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, view: window }));
    });
  }

  /** Writes UUIDs into the hidden field and fires the AJAX submit. */
  function submitToMediaLibraryAddForm(containerEl, assets) {
    var form = containerEl.closest('form');
    if (!form) {
      return;
    }
    var hidden = form.querySelector('.assetkiwi-ml-selected-uuids');
    var trigger = form.querySelector('.assetkiwi-ml-bulk-import-trigger');
    if (!hidden || !trigger) {
      return;
    }
    hidden.value = assets
      .map(function (asset) {
        return asset.uuid;
      })
      .join(',');
    fireAjaxTrigger(trigger);
  }

  // Auto-mount any container declared via data attributes (used by the
  // Media Library add form, which renders its container server-side).
  Drupal.behaviors.assetKiwiPickerAutoMount = {
    attach: function (context) {
      once('assetkiwi-picker-automount', '[data-assetkiwi-picker]', context).forEach(function (el) {
        var config = JSON.parse(el.getAttribute('data-assetkiwi-picker') || '{}');
        var isMediaLibrary = el.hasAttribute('data-assetkiwi-medialibrary');

        Drupal.AssetKiwiPicker.mount(el, {
          mode: config.mode || 'single',
          allowedTypes: config.allowedTypes || [],
          bundle: config.bundle || '',
          maxSelections: config.maxSelections || 0,
          endpoints: config.endpoints || {},
          onSelect: function (assets) {
            if (isMediaLibrary) {
              submitToMediaLibraryAddForm(el, assets);
            }
          },
        });
      });
    },
  };

})(Drupal, once);
