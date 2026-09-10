/*
 * ESET Translator – translation wizard.
 *
 * Opens a modal for "Request translation" and "Export / import translation",
 * both from the page module doc header and from the page tree context menu.
 */
define([
  'jquery',
  'TYPO3/CMS/Backend/Modal',
  'TYPO3/CMS/Backend/Notification',
  'TYPO3/CMS/Backend/Severity',
  'TYPO3/CMS/Core/Ajax/AjaxRequest'
], function ($, Modal, Notification, Severity, AjaxRequest) {
  'use strict';

  var TranslationWizard = {};

  TranslationWizard.loadOptions = function (pageUid) {
    return new AjaxRequest(TYPO3.settings.ajaxUrls.eset_translator_options)
      .withQueryArguments({ page: pageUid })
      .get()
      .then(function (response) {
        return response.resolve();
      });
  };

  TranslationWizard.escape = function (value) {
    return $('<div>').text(value === null || value === undefined ? '' : value).html();
  };

  TranslationWizard.lang = function (key, fallback) {
    return (TYPO3.lang && TYPO3.lang[key]) || fallback || key;
  };

  /**
   * Renders a (site, language) picker grouped by site. Each option carries its
   * language code in data-lang so the modal can block source == target.
   */
  TranslationWizard.renderTargetOptions = function (targets, selectedKey) {
    var groups = {};
    targets.forEach(function (target) {
      if (!groups[target.site]) {
        groups[target.site] = { title: target.siteTitle, items: [] };
      }
      groups[target.site].items.push(target);
    });

    var html = '';
    Object.keys(groups).sort().forEach(function (site) {
      html += '<optgroup label="' + TranslationWizard.escape(groups[site].title + ' (' + site + ')') + '">';
      groups[site].items.forEach(function (target) {
        var inPlace = target.languageId === 0 ? ', in place' : '';
        html += '<option value="' + TranslationWizard.escape(target.key) + '"'
          + ' data-lang="' + TranslationWizard.escape(target.translationCode) + '"'
          + (target.key === selectedKey ? ' selected' : '') + '>'
          + TranslationWizard.escape(target.title + ' [' + target.translationCode + inPlace + ']')
          + '</option>';
      });
      html += '</optgroup>';
    });

    return html;
  };

  TranslationWizard.buildForm = function (options, mode) {
    var automated = mode === 'request' && options.automatedAvailable;
    var $form = $('<form>', { 'class': 'eset-translator-wizard', id: 'eset-translator-form' });

    var sourceList = options.sources && options.sources.length ? options.sources : [options.source];
    var sourceField;
    if (sourceList.length > 1) {
      sourceField = '<select class="form-control" name="source" required>'
        + TranslationWizard.renderTargetOptions(sourceList, options.source.key)
        + '</select>'
        + '<p class="help-block">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.sourceHelp', '')) + '</p>';
    } else {
      sourceField = '<div class="form-control-static"><strong>' + TranslationWizard.escape(options.source.label) + '</strong></div>'
        + '<input type="hidden" name="source" data-lang="' + TranslationWizard.escape(options.source.translationCode) + '"'
        + ' value="' + TranslationWizard.escape(options.source.key) + '">';
    }
    $form.append(
      '<div class="form-group">'
      + '<label class="form-label">' + TranslationWizard.lang('eset_translator.source') + '</label>'
      + sourceField
      + '</div>'
    );

    $form.append(
      '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-target">' + TranslationWizard.lang('eset_translator.target') + '</label>'
      + '<select class="form-control" id="eset-translator-target" name="target" required>'
      + TranslationWizard.renderTargetOptions(options.targets)
      + '</select>'
      + '</div>'
    );

    $form.append(
      '<div class="alert alert-warning eset-translator-lang-warning" hidden>'
      + TranslationWizard.lang('eset_translator.sameLanguage',
        'Source and target are the same language. Pick the language this page is actually written in.')
      + '</div>'
    );

    $form.append(
      '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-depth">' + TranslationWizard.lang('eset_translator.depth') + '</label>'
      + '<input type="number" class="form-control" id="eset-translator-depth" name="depth" value="0" min="0" max="'
      + (options.maxDepth || 0) + '">'
      + '</div>'
    );

    $form.append(
      '<div class="checkbox"><label>'
      + '<input type="checkbox" name="onlyUntranslated" value="1" checked> '
      + TranslationWizard.lang('eset_translator.onlyUntranslated')
      + '</label></div>'
    );

    if (options.contentTypes && options.contentTypes.length) {
      var ctypeRows = options.contentTypes.map(function (ct) {
        return '<div class="checkbox"><label>'
          + '<input type="checkbox" class="eset-translator-ctype" value="' + TranslationWizard.escape(ct.cType) + '"'
          + (ct.excludedByDefault ? '' : ' checked') + '> '
          + TranslationWizard.escape(ct.label)
          + ' <span class="text-muted">(' + ct.count + ')</span>'
          + '</label></div>';
      }).join('');
      $form.append(
        '<div class="form-group">'
        + '<label class="form-label">' + TranslationWizard.lang('eset_translator.contentTypes', 'Content types to translate') + '</label>'
        + ctypeRows
        + '</div>'
      );
    }

    if (automated) {
      var providerOptions = options.providers.map(function (provider) {
        return '<option value="' + TranslationWizard.escape(provider.id) + '">'
          + TranslationWizard.escape(provider.title) + '</option>';
      }).join('');

      $form.append(
        '<div class="form-group">'
        + '<label class="form-label" for="eset-translator-provider">' + TranslationWizard.lang('eset_translator.provider') + '</label>'
        + '<select class="form-control" id="eset-translator-provider" name="provider">' + providerOptions + '</select>'
        + '</div>'
      );
    } else if (mode === 'request') {
      $form.append('<div class="alert alert-info">' + TranslationWizard.lang('eset_translator.noProvider') + '</div>');
    }

    if (!automated || mode === 'exchange') {
      var formatOptions = Object.keys(options.formats).map(function (key) {
        var selected = key === options.defaultFormat ? ' selected' : '';
        return '<option value="' + TranslationWizard.escape(key) + '"' + selected + '>'
          + TranslationWizard.escape(options.formats[key]) + '</option>';
      }).join('');

      $form.append(
        '<div class="form-group">'
        + '<label class="form-label" for="eset-translator-format">' + TranslationWizard.lang('eset_translator.format') + '</label>'
        + '<select class="form-control" id="eset-translator-format" name="format">' + formatOptions + '</select>'
        + '</div>'
      );
    }

    if (mode === 'exchange') {
      $form.append(
        '<hr><div class="form-group">'
        + '<label class="form-label" for="eset-translator-file">' + TranslationWizard.lang('eset_translator.importFile') + '</label>'
        + '<input type="file" class="form-control" id="eset-translator-file" name="file" accept=".xlf,.xliff,.xml">'
        + '<p class="help-block">' + TranslationWizard.lang('eset_translator.importHelp') + '</p>'
        + '</div>'
      );
    }

    return $form;
  };

  /**
   * Disables the action buttons while the chosen source and target describe the
   * same language (translating en -> en is a no-op).
   */
  TranslationWizard.validate = function ($modal) {
    var $source = $modal.find('[name="source"]');
    var $target = $modal.find('[name="target"]');
    var sourceLang = $source.is('select') ? $source.find('option:selected').data('lang') : $source.data('lang');
    var targetLang = $target.find('option:selected').data('lang');
    var clash = sourceLang && targetLang
      && String(sourceLang).toLowerCase() === String(targetLang).toLowerCase();

    $modal.find('.eset-translator-lang-warning').prop('hidden', !clash);
    $modal.find('.modal-footer [name="eset-start"], .modal-footer [name="eset-download"], .modal-footer [name="eset-import"]')
      .prop('disabled', !!clash);
  };

  TranslationWizard.collect = function ($modal, pageUid) {
    var data = { page: pageUid };
    $modal.find('#eset-translator-form').serializeArray().forEach(function (field) {
      data[field.name] = field.value;
    });
    if (!data.onlyUntranslated) {
      data.onlyUntranslated = '0';
    }

    var skip = [];
    $modal.find('.eset-translator-ctype').each(function () {
      if (!this.checked) {
        skip.push(this.value);
      }
    });
    data.skipCTypes = skip.join(',');

    return data;
  };

  TranslationWizard.submitJob = function (data, mode) {
    data.mode = mode === 'request' ? 'automated' : 'manual';

    return new AjaxRequest(TYPO3.settings.ajaxUrls.eset_translator_create_job)
      .post(data)
      .then(function (response) {
        return response.resolve();
      });
  };

  TranslationWizard.download = function (data) {
    var params = Object.keys(data).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
    }).join('&');
    window.location.href = TYPO3.settings.ajaxUrls.eset_translator_export + '&' + params;
  };

  TranslationWizard.upload = function ($modal, pageUid) {
    var input = $modal.find('#eset-translator-file')[0];
    if (!input || !input.files.length) {
      return null;
    }
    var formData = new FormData();
    formData.append('file', input.files[0]);
    formData.append('page', String(pageUid));

    // Native fetch: TYPO3 v10.4 AjaxRequest cannot transport a multipart body
    // (InputTransformer rebuilds it from Object.keys() and drops the File).
    return fetch(TYPO3.settings.ajaxUrls.eset_translator_import, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    });
  };

  TranslationWizard.notify = function (result) {
    if (result && result.success) {
      Notification.success(TranslationWizard.lang('eset_translator.title'), result.message);
    } else {
      Notification.error(TranslationWizard.lang('eset_translator.title'), (result && result.message) || '');
    }
  };

  TranslationWizard.open = function (pageUid, mode) {
    TranslationWizard.loadOptions(pageUid).then(function (options) {
      if (!options.success) {
        Notification.info(TranslationWizard.lang('eset_translator.title'), options.message);
        return;
      }
      if (!options.targets.length) {
        Notification.warning(TranslationWizard.lang('eset_translator.title'), TranslationWizard.lang('eset_translator.noTargets'));
        return;
      }

      var buttons = [
        {
          text: TranslationWizard.lang('eset_translator.cancel'),
          btnClass: 'btn-default',
          trigger: function () { Modal.dismiss(); }
        }
      ];

      if (mode === 'request' && options.automatedAvailable) {
        buttons.push({
          text: TranslationWizard.lang('eset_translator.startTranslation'),
          btnClass: 'btn-primary',
          name: 'eset-start',
          trigger: function () {
            TranslationWizard.submitJob(TranslationWizard.collect(Modal.currentModal, pageUid), 'request')
              .then(function (result) {
                Modal.dismiss();
                TranslationWizard.notify(result);
              });
          }
        });
      }

      if (mode === 'exchange' || options.manualFallback) {
        buttons.push({
          text: TranslationWizard.lang('eset_translator.download'),
          btnClass: 'btn-default',
          name: 'eset-download',
          trigger: function () {
            TranslationWizard.download(TranslationWizard.collect(Modal.currentModal, pageUid));
            Modal.dismiss();
          }
        });
      }

      if (mode === 'exchange') {
        buttons.push({
          text: TranslationWizard.lang('eset_translator.import'),
          btnClass: 'btn-warning',
          name: 'eset-import',
          trigger: function () {
            var $modal = Modal.currentModal;
            var promise = TranslationWizard.upload($modal, pageUid);
            if (promise === null) {
              Notification.warning(TranslationWizard.lang('eset_translator.title'), TranslationWizard.lang('eset_translator.noFile'));
              return;
            }
            promise.then(function (result) {
              Modal.dismiss();
              TranslationWizard.notify(result);
              if (result && result.success && top.TYPO3.Backend && top.TYPO3.Backend.ContentContainer) {
                top.TYPO3.Backend.ContentContainer.refresh();
              }
            });
          }
        });
      }

      var $modal = Modal.advanced({
        title: (mode === 'request'
          ? TranslationWizard.lang('eset_translator.requestTranslation')
          : TranslationWizard.lang('eset_translator.exchange')) + ': ' + options.page.title,
        content: TranslationWizard.buildForm(options, mode),
        severity: Severity.notice,
        size: Modal.sizes.medium,
        buttons: buttons
      });

      $modal.on('shown.bs.modal', function () {
        $modal.find('#eset-translator-form').on('change', '[name="source"], [name="target"]', function () {
          TranslationWizard.validate($modal);
        });
        TranslationWizard.validate($modal);
      });
    }).catch(function () {
      Notification.error(TranslationWizard.lang('eset_translator.title'), TranslationWizard.lang('eset_translator.requestFailed'));
    });
  };

  $(document).on('click', '[data-eset-translator-action]', function (event) {
    event.preventDefault();
    var $button = $(this);
    TranslationWizard.open(
      parseInt($button.data('eset-translator-page'), 10),
      $button.data('eset-translator-action')
    );
  });

  return TranslationWizard;
});
