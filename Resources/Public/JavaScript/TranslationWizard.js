/*
 * ESET Translator – translation wizards.
 *
 * Two actions, each with its own button (page module doc header, page tree
 * context menu):
 *   - "request":  automated translation - the core MultiStepWizard:
 *                 languages, content, references & summary, processing
 *   - "exchange": modal with tabs Export (same steps, file for an agency) and
 *                 Import (upload a translated file)
 *
 * The "References" step lists "Insert records" elements whose records live
 * outside the job pages (see ReferenceService).
 */
define([
  'jquery',
  'TYPO3/CMS/Backend/Modal',
  'TYPO3/CMS/Backend/MultiStepWizard',
  'TYPO3/CMS/Backend/Notification',
  'TYPO3/CMS/Backend/Severity',
  'TYPO3/CMS/Core/Ajax/AjaxRequest'
], function ($, Modal, MultiStepWizard, Notification, Severity, AjaxRequest) {
  'use strict';

  var TranslationWizard = {};

  var STYLE = ''
    + '.eset-wizard .nav-tabs{margin-bottom:15px}'
    + '.eset-steps{display:flex;list-style:none;padding:0;margin:0 0 15px;counter-reset:step}'
    + '.eset-steps li{flex:1;padding:6px 8px;border-bottom:3px solid #ddd;color:#888;counter-increment:step}'
    + '.eset-steps li:before{content:counter(step) ". "}'
    + '.eset-steps li.active{border-color:#ff8700;color:#333;font-weight:bold}'
    + '.eset-steps li.done{border-color:#79a548;color:#333}'
    + '.eset-steps li.disabled{opacity:.4}'
    + '.eset-ref-group{margin-bottom:12px}'
    + '.eset-ref-group .table{margin-bottom:0}'
    + '.eset-ref-group td{vertical-align:middle!important}'
    + '.eset-ref-group select{min-width:220px}'
    + '.eset-final{border-top:1px solid #ddd;padding-top:12px;margin-top:12px}'
    + '.eset-summary dt{float:left;clear:left;width:40%;font-weight:normal;color:#777}'
    + '.eset-summary dd{margin-left:42%;margin-bottom:4px}';

  // --- helpers ----------------------------------------------------------------

  /**
   * URL of an AJAX route of this extension. TYPO3 10 caches the backend routes
   * independently of extension updates: after deploying a version with new
   * routes, they are missing until the caches are flushed. Without this check
   * the request would go to ".../undefined" and fail silently.
   */
  TranslationWizard.ajaxUrl = function (route) {
    var urls = (TYPO3.settings && TYPO3.settings.ajaxUrls) || {};
    if (!urls[route]) {
      throw new Error(
        'AJAX route "' + route + '" is not registered. Flush all caches '
        + '(Install Tool → Maintenance → Flush TYPO3 and PHP Cache) after updating the extension.'
      );
    }
    return urls[route];
  };

  /**
   * JSON request to an AJAX route. Always returns a promise - errors (missing
   * route, HTTP error, no JSON) reject it instead of being thrown.
   */
  TranslationWizard.call = function (route, method, data) {
    return Promise.resolve().then(function () {
      var request = new AjaxRequest(TranslationWizard.ajaxUrl(route));
      return method === 'post' ? request.post(data) : request.withQueryArguments(data || {}).get();
    }).then(function (response) {
      return response.resolve();
    });
  };

  /**
   * Human readable message for a failed request.
   */
  TranslationWizard.errorMessage = function (error) {
    if (error && error.response && typeof error.response.status === 'number') {
      // AjaxRequest rejects non-2xx responses with the AjaxResponse
      return TranslationWizard.lang('eset_translator.requestFailed') + ' (HTTP ' + error.response.status + ')';
    }
    if (error instanceof SyntaxError) {
      return TranslationWizard.lang('eset_translator.noJson',
        'The server did not answer with JSON - the backend session may have expired (log in again) or the caches need to be flushed.');
    }
    if (error && error.message) {
      return error.message;
    }
    return TranslationWizard.lang('eset_translator.requestFailed');
  };

  TranslationWizard.loadOptions = function (pageUid) {
    return TranslationWizard.call('eset_translator_options', 'get', { page: pageUid });
  };

  TranslationWizard.analyze = function (data) {
    return TranslationWizard.call('eset_translator_analyze', 'get', {
      page: data.page, source: data.source, target: data.target, depth: data.depth || 0
    });
  };

  TranslationWizard.escape = function (value) {
    return $('<div>').text(value === null || value === undefined ? '' : value).html();
  };

  TranslationWizard.lang = function (key, fallback) {
    return (TYPO3.lang && TYPO3.lang[key]) || fallback || key;
  };

  TranslationWizard.analysisKey = function (data) {
    return [data.source, data.target, data.depth].join('|');
  };

  /**
   * Renders a (site, language) picker grouped by site. Each option carries its
   * language code in data-lang so the wizard can block source == target.
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

  // --- step markup (shared by both flows) ---------------------------------------

  TranslationWizard.stepLanguagesHtml = function (options) {
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

    return '<div class="form-group">'
      + '<label class="form-label">' + TranslationWizard.lang('eset_translator.source') + '</label>'
      + sourceField
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-target">' + TranslationWizard.lang('eset_translator.target') + '</label>'
      + '<select class="form-control" id="eset-translator-target" name="target" required>'
      + TranslationWizard.renderTargetOptions(options.targets)
      + '</select>'
      + '</div>'
      + '<div class="alert alert-warning eset-translator-lang-warning" hidden>'
      + TranslationWizard.lang('eset_translator.sameLanguage',
        'Source and target are the same language. Pick the language this page is actually written in.')
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-depth">' + TranslationWizard.lang('eset_translator.depth') + '</label>'
      + '<input type="number" class="form-control" id="eset-translator-depth" name="depth" value="0" min="0" max="'
      + (options.maxDepth || 0) + '">'
      + '</div>';
  };

  TranslationWizard.stepContentHtml = function () {
    return '<div class="checkbox"><label>'
      + '<input type="checkbox" name="onlyUntranslated" value="1" checked> '
      + TranslationWizard.lang('eset_translator.onlyUntranslated')
      + '</label></div>'
      + '<div class="eset-ctypes"></div>'
      + '<div class="eset-ref-summary"></div>';
  };

  TranslationWizard.formatFieldHtml = function (options) {
    var formatOptions = Object.keys(options.formats || {}).map(function (key) {
      var selected = key === options.defaultFormat ? ' selected' : '';
      return '<option value="' + TranslationWizard.escape(key) + '"' + selected + '>'
        + TranslationWizard.escape(options.formats[key]) + '</option>';
    }).join('');

    return '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-format">' + TranslationWizard.lang('eset_translator.format') + '</label>'
      + '<select class="form-control" id="eset-translator-format" name="format">' + formatOptions + '</select>'
      + '</div>';
  };

  TranslationWizard.renderContentTypes = function ($root, contentTypes) {
    var $container = $root.find('.eset-ctypes').empty();
    if (!contentTypes || !contentTypes.length) {
      return;
    }
    var rows = contentTypes.map(function (ct) {
      return '<div class="checkbox"><label>'
        + '<input type="checkbox" class="eset-translator-ctype" value="' + TranslationWizard.escape(ct.cType) + '"'
        + (ct.excludedByDefault ? '' : ' checked') + '> '
        + TranslationWizard.escape(ct.label)
        + ' <span class="text-muted">(' + ct.count + ')</span>'
        + '</label></div>';
    }).join('');
    $container.append(
      '<div class="form-group">'
      + '<label class="form-label">' + TranslationWizard.lang('eset_translator.contentTypes', 'Content types to translate') + '</label>'
      + rows
      + '</div>'
    );
  };

  TranslationWizard.scopeLabel = function (entry) {
    switch (entry.scope) {
      case 'inJob':
        return '<span class="label label-success">' + TranslationWizard.lang('eset_translator.ref.scope.inJob', 'Translated with the page') + '</span>';
      case 'targetSite':
        return '<span class="label label-info">' + TranslationWizard.lang('eset_translator.ref.scope.targetSite', 'Shared content of this site') + '</span>';
      case 'foreign':
        return '<span class="label label-warning">' + TranslationWizard.lang('eset_translator.ref.scope.foreign', 'Content of another site') + '</span>'
          + (entry.refSite ? ' <span class="text-muted">' + TranslationWizard.escape(entry.refSite) + '</span>' : '');
      default:
        return '<span class="label label-danger">' + TranslationWizard.lang('eset_translator.ref.scope.missing', 'Record no longer exists') + '</span>';
    }
  };

  TranslationWizard.actionLabel = function (action) {
    if (action.value.indexOf('relink:') === 0) {
      return TranslationWizard.lang('eset_translator.ref.action.relink', 'Link to existing copy')
        + ' #' + action.uid
        + (action.title ? ' "' + action.title + '"' : '')
        + (action.pageTitle ? ' (' + action.pageTitle + ')' : '')
        + (action.hidden ? ' [' + TranslationWizard.lang('eset_translator.ref.hidden', 'hidden') + ']' : '');
    }
    switch (action.value) {
      case 'translate':
        return TranslationWizard.lang('eset_translator.ref.action.translate', 'Translate the original record');
      case 'copy':
        return TranslationWizard.lang('eset_translator.ref.action.copy', 'Copy onto this page and translate the copy');
      default:
        return TranslationWizard.lang('eset_translator.ref.action.skip', 'Leave as is (not translated)');
    }
  };

  TranslationWizard.isActionable = function (entry) {
    return entry.actions && entry.actions.length > 1;
  };

  TranslationWizard.hasActionableReferences = function (analysis) {
    return !!(analysis && (analysis.references || []).some(TranslationWizard.isActionable));
  };

  TranslationWizard.renderReferences = function ($root, analysis) {
    var references = analysis.references || [];
    var actionable = references.filter(TranslationWizard.isActionable);
    var $summary = $root.find('.eset-ref-summary').empty();
    var $container = $root.find('.eset-references').empty();

    if (actionable.length) {
      $summary.append(
        '<div class="alert alert-info">'
        + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.found',
          '"Insert records" elements on this page reference content stored elsewhere. Decide how to handle it in the next step.'))
        + ' <strong>(' + actionable.length + ')</strong></div>'
      );
    }
    if (!references.length) {
      return;
    }

    $container.append(
      '<p>' + TranslationWizard.escape(analysis.inPlace
        ? TranslationWizard.lang('eset_translator.ref.introInPlace',
          'The target is written in place. Content of another site must never be overwritten - link to a translated copy in this site, or copy it onto the page.')
        : TranslationWizard.lang('eset_translator.ref.intro',
          'Referenced content of another site cannot be translated where it is (its language ids mean different languages). Copy it onto the page to translate it.'))
      + '</p>'
      + '<p class="text-muted">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.copyNote',
        'Copies and links are created when the job is started. When anything is copied, the "Insert records" element is deleted (restorable from the recycler) and replaced, in the same order, by the copies and by new "Insert records" elements for the rest.'))
      + '</p>'
    );

    if (actionable.length > 1) {
      $container.append(
        '<div class="form-group form-inline">'
        + '<label class="form-label" for="eset-ref-bulk">' + TranslationWizard.lang('eset_translator.ref.bulk', 'Set all to') + '</label> '
        + '<select class="form-control" id="eset-ref-bulk">'
        + '<option value="">–</option>'
        + '<option value="relink">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.action.relink', 'Link to existing copy')) + '</option>'
        + '<option value="translate">' + TranslationWizard.escape(TranslationWizard.actionLabel({ value: 'translate' })) + '</option>'
        + '<option value="copy">' + TranslationWizard.escape(TranslationWizard.actionLabel({ value: 'copy' })) + '</option>'
        + '<option value="skip">' + TranslationWizard.escape(TranslationWizard.actionLabel({ value: 'skip' })) + '</option>'
        + '</select></div>'
      );
    }

    // Group by shortcut element, keeping the analysis order.
    var groups = [];
    var byShortcut = {};
    references.forEach(function (entry) {
      if (!byShortcut[entry.shortcutUid]) {
        byShortcut[entry.shortcutUid] = { entry: entry, items: [] };
        groups.push(byShortcut[entry.shortcutUid]);
      }
      byShortcut[entry.shortcutUid].items.push(entry);
    });

    groups.forEach(function (group) {
      var head = group.entry;
      var rows = group.items.map(function (entry) {
        var control;
        if (TranslationWizard.isActionable(entry)) {
          control = '<select class="form-control input-sm eset-ref-action" data-key="' + TranslationWizard.escape(entry.key) + '">'
            + entry.actions.map(function (action) {
              return '<option value="' + TranslationWizard.escape(action.value) + '"'
                + (action.value === entry['default'] ? ' selected' : '') + '>'
                + TranslationWizard.escape(TranslationWizard.actionLabel(action)) + '</option>';
            }).join('')
            + '</select>';
        } else {
          control = '<span class="text-muted">–</span>';
        }

        return '<tr>'
          + '<td><strong>' + TranslationWizard.escape(entry.refTitle || '[' + TranslationWizard.lang('eset_translator.ref.noTitle', 'no title') + ']') + '</strong>'
          + ' <span class="text-muted">#' + entry.refUid + (entry.refCType ? ', ' + TranslationWizard.escape(entry.refCType) : '') + '</span><br>'
          + TranslationWizard.scopeLabel(entry)
          + (entry.via && entry.via.length ? ' <span class="text-muted">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.via', 'via "Insert records"'))
            + ' #' + entry.via.join(' → #') + '</span>' : '')
          + (entry.refPageTitle ? ' <span class="text-muted">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.onPage', 'on page')) + ' "'
            + TranslationWizard.escape(entry.refPageTitle) + '" [' + entry.refPid + ']</span>' : '')
          + '</td>'
          + '<td class="text-right">' + control + '</td>'
          + '</tr>';
      }).join('');

      $container.append(
        '<div class="panel panel-default eset-ref-group">'
        + '<div class="panel-heading">'
        + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.shortcut', 'Insert records'))
        + ' <strong>' + TranslationWizard.escape(head.shortcutTitle || '') + '</strong>'
        + ' <span class="text-muted">#' + head.shortcutUid + ' – "' + TranslationWizard.escape(head.shortcutPageTitle) + '" [' + head.shortcutPid + ']</span>'
        + '</div>'
        + '<table class="table table-condensed">' + rows + '</table>'
        + '</div>'
      );
    });
  };

  /**
   * "Set all to": picks the matching action wherever it is offered.
   */
  TranslationWizard.applyBulk = function ($root, value) {
    if (!value) {
      return;
    }
    $root.find('.eset-ref-action').each(function () {
      var $select = $(this);
      $select.find('option').each(function () {
        if (this.value === value || (value === 'relink' && this.value.indexOf('relink:') === 0)) {
          $select.val(this.value);
          return false;
        }
      });
    });
  };

  /**
   * True while the chosen source and target describe the same language
   * (translating en -> en is a no-op).
   */
  TranslationWizard.hasLanguageClash = function ($root) {
    var $source = $root.find('[name="source"]');
    var $target = $root.find('[name="target"]');
    var sourceLang = $source.is('select') ? $source.find('option:selected').data('lang') : $source.data('lang');
    var targetLang = $target.find('option:selected').data('lang');

    return !!(sourceLang && targetLang && String(sourceLang).toLowerCase() === String(targetLang).toLowerCase());
  };

  /**
   * Request parameters from all wizard fields below $root.
   */
  TranslationWizard.collect = function ($root, pageUid) {
    var data = { page: pageUid };
    $root.find(':input[name]').not('[type="file"]').serializeArray().forEach(function (field) {
      data[field.name] = field.value;
    });
    if (!data.onlyUntranslated) {
      data.onlyUntranslated = '0';
    }

    var skip = [];
    $root.find('.eset-translator-ctype').each(function () {
      if (!this.checked) {
        skip.push(this.value);
      }
    });
    data.skipCTypes = skip.join(',');

    var references = {};
    $root.find('.eset-ref-action').each(function () {
      references[$(this).data('key')] = this.value;
    });
    data.references = JSON.stringify(references);

    return data;
  };

  TranslationWizard.submitJob = function (data, mode) {
    data.mode = mode;

    return TranslationWizard.call('eset_translator_create_job', 'post', data);
  };

  TranslationWizard.upload = function ($root, pageUid) {
    var input = $root.find('#eset-translator-file')[0];
    if (!input || !input.files.length) {
      return null;
    }
    var formData = new FormData();
    formData.append('file', input.files[0]);
    formData.append('page', String(pageUid));

    // Native fetch: TYPO3 v10.4 AjaxRequest cannot transport a multipart body
    // (InputTransformer rebuilds it from Object.keys() and drops the File).
    return Promise.resolve().then(function () {
      return fetch(TranslationWizard.ajaxUrl('eset_translator_import'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
    }).then(function (response) {
      if (!response.ok) {
        throw new Error(TranslationWizard.lang('eset_translator.requestFailed') + ' (HTTP ' + response.status + ')');
      }
      return response.json();
    });
  };

  TranslationWizard.notify = function (result) {
    if (result && result.success) {
      var message = result.message || '';
      if (result.referenceReport && result.referenceReport.length) {
        message += ' ' + result.referenceReport.join(' ');
      }
      Notification.success(TranslationWizard.lang('eset_translator.title'), message);
    } else {
      Notification.error(TranslationWizard.lang('eset_translator.title'), (result && result.message) || '');
    }
  };

  TranslationWizard.refreshContent = function () {
    if (top.TYPO3 && top.TYPO3.Backend && top.TYPO3.Backend.ContentContainer) {
      top.TYPO3.Backend.ContentContainer.refresh();
    }
  };

  TranslationWizard.requestFailed = function (error) {
    if (window.console && error) {
      window.console.error('[ESET Translator]', error);
    }
    Notification.error(TranslationWizard.lang('eset_translator.title'), TranslationWizard.errorMessage(error));
  };

  // --- "request": core MultiStepWizard ----------------------------------------

  /**
   * Provider <option>s usable for the selected target; empty when none
   * supports the language pair.
   */
  TranslationWizard.providerOptionsHtml = function (options, targetKey) {
    var allowed = (options.providersPerTarget || {})[targetKey] || [];

    return (options.providers || []).filter(function (provider) {
      return allowed.indexOf(provider.id) !== -1;
    }).map(function (provider) {
      return '<option value="' + TranslationWizard.escape(provider.id) + '">'
        + TranslationWizard.escape(provider.title) + '</option>';
    }).join('');
  };

  TranslationWizard.renderSummary = function ($root, options, analysis) {
    var $source = $root.find('[name="source"]');
    var sourceLabel = $source.is('select') ? $source.find('option:selected').text() : options.source.label;
    var skipped = $root.find('.eset-translator-ctype:not(:checked)').map(function () {
      return $(this).parent().text().trim();
    }).get();
    var actionable = (analysis.references || []).filter(TranslationWizard.isActionable).length;

    var rows = [
      [TranslationWizard.lang('eset_translator.summary.source', 'Source'), sourceLabel],
      [TranslationWizard.lang('eset_translator.summary.target', 'Target'), $root.find('[name="target"] option:selected').text()],
      [TranslationWizard.lang('eset_translator.summary.depth', 'Subpage levels'), $root.find('[name="depth"]').val() || '0'],
      [TranslationWizard.lang('eset_translator.summary.onlyUntranslated', 'Only untranslated fields'),
        $root.find('[name="onlyUntranslated"]').prop('checked')
          ? TranslationWizard.lang('eset_translator.yes', 'yes')
          : TranslationWizard.lang('eset_translator.no', 'no')],
      [TranslationWizard.lang('eset_translator.summary.skipped', 'Skipped content types'), skipped.length ? skipped.join(', ') : '–'],
      [TranslationWizard.lang('eset_translator.summary.references', 'Referenced content'), actionable
        ? String(actionable)
        : TranslationWizard.lang('eset_translator.summary.noReferences', 'none')]
    ];

    $root.find('.eset-summary').html(
      '<dl>' + rows.map(function (row) {
        return '<dt>' + TranslationWizard.escape(row[0]) + '</dt><dd>' + TranslationWizard.escape(row[1]) + '</dd>';
      }).join('') + '</dl>'
    );
  };

  TranslationWizard.openRequestWizard = function (options, pageUid) {
    var title = TranslationWizard.lang('eset_translator.requestTranslation') + ': ' + options.page.title;
    var state = { analysis: null, analysisKey: '', bound: false };

    // MultiStepWizard locks "next" right after a slide callback ran, so
    // decisions about "next" are deferred to the next tick.
    var defer = function (fn) {
      window.setTimeout(fn, 0);
    };
    var modalOf = function ($slide) {
      return $($slide).closest('.modal');
    };
    var validateLanguages = function ($modal) {
      var clash = TranslationWizard.hasLanguageClash($modal);
      $modal.find('.eset-translator-lang-warning').prop('hidden', !clash);
      if (clash) {
        MultiStepWizard.lockNextStep();
      } else {
        MultiStepWizard.unlockNextStep();
      }
    };

    MultiStepWizard.addSlide(
      'eset-languages',
      title,
      '<style>' + STYLE + '</style><div class="eset-wizard">' + TranslationWizard.stepLanguagesHtml(options) + '</div>',
      Severity.notice,
      TranslationWizard.lang('eset_translator.step.languages', 'Languages'),
      function ($slide) {
        var $modal = modalOf($slide);
        if (!state.bound) {
          state.bound = true;
          // Slide content is rendered from HTML strings - delegate on the modal.
          $modal.on('change', '[name="source"], [name="target"]', function () {
            if (MultiStepWizard.setup.$carousel.data('currentIndex') === 0) {
              validateLanguages($modal);
            }
          });
          $modal.on('change', '#eset-ref-bulk', function () {
            TranslationWizard.applyBulk($modal, this.value);
            this.value = '';
          });
        }
        defer(function () {
          // "Back" on the first slide would wrap the carousel around.
          MultiStepWizard.lockPrevStep();
          validateLanguages($modal);
        });
      }
    );

    MultiStepWizard.addSlide(
      'eset-content',
      title,
      '<div class="eset-wizard">'
      + '<div class="eset-step-status"></div>'
      + TranslationWizard.stepContentHtml()
      + '</div>',
      Severity.notice,
      TranslationWizard.lang('eset_translator.step.content', 'Content'),
      function ($slide) {
        var $modal = modalOf($slide);
        var $status = $modal.find('.eset-step-status');
        var data = TranslationWizard.collect($modal, pageUid);
        var key = TranslationWizard.analysisKey(data);
        // Errors are shown in the slide itself (and as notification) - the
        // step must never end up locked without a message.
        var fail = function (message, error) {
          state.analysis = null;
          state.analysisKey = '';
          $status.html('<div class="alert alert-danger">' + TranslationWizard.escape(message) + '</div>');
          MultiStepWizard.lockNextStep();
          MultiStepWizard.unlockPrevStep();
          if (error !== undefined) {
            TranslationWizard.requestFailed(error);
          } else {
            Notification.error(TranslationWizard.lang('eset_translator.title'), message);
          }
        };

        defer(function () {
          MultiStepWizard.unlockPrevStep();
          if (state.analysis !== null && key === state.analysisKey) {
            MultiStepWizard.unlockNextStep();
            return;
          }
          MultiStepWizard.lockNextStep();
          MultiStepWizard.lockPrevStep();
          $status.html(
            '<div class="alert alert-info">'
            + TranslationWizard.escape(TranslationWizard.lang('eset_translator.analyzing', 'Analyzing page content …'))
            + '</div>'
          );
          TranslationWizard.analyze(data).then(function (analysis) {
            if (!analysis || !analysis.success) {
              fail((analysis && analysis.message) || TranslationWizard.lang('eset_translator.requestFailed'));
              return;
            }
            state.analysis = analysis;
            state.analysisKey = key;
            $status.empty();
            TranslationWizard.renderContentTypes($modal, analysis.contentTypes);
            TranslationWizard.renderReferences($modal, analysis);
            MultiStepWizard.unlockPrevStep();
            MultiStepWizard.unlockNextStep();
          }).catch(function (error) {
            fail(TranslationWizard.errorMessage(error), error);
          });
        });
      }
    );

    MultiStepWizard.addSlide(
      'eset-references',
      title,
      '<div class="eset-wizard">'
      + '<div class="eset-references"></div>'
      + '<div class="eset-final">'
      + '<h4>' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.step.summary', 'Summary')) + '</h4>'
      + '<div class="eset-summary"></div>'
      + '<div class="form-group">'
      + '<label class="form-label" for="eset-translator-provider">' + TranslationWizard.lang('eset_translator.provider') + '</label>'
      + '<select class="form-control" id="eset-translator-provider" name="provider"></select>'
      + '</div>'
      + '<div class="alert alert-warning eset-no-provider" hidden>'
      + TranslationWizard.escape(TranslationWizard.lang('eset_translator.noProviderForPair',
        'No configured translation provider supports this language pair. Use Export / import instead.'))
      + '</div>'
      + '</div></div>',
      Severity.notice,
      TranslationWizard.lang('eset_translator.step.references', 'References'),
      function ($slide) {
        var $modal = modalOf($slide);
        var targetKey = $modal.find('[name="target"]').val();
        var $provider = $modal.find('[name="provider"]');
        var current = $provider.val();
        var providerOptions = TranslationWizard.providerOptionsHtml(options, targetKey);

        $provider.html(providerOptions);
        if (current && $provider.find('option[value="' + current + '"]').length) {
          $provider.val(current);
        }
        $provider.closest('.form-group').prop('hidden', providerOptions === '');
        $modal.find('.eset-no-provider').prop('hidden', providerOptions !== '');
        if (!TranslationWizard.hasActionableReferences(state.analysis)) {
          $modal.find('.eset-references').html(
            '<p class="text-muted">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.ref.none',
              'No "Insert records" element references content outside this page.')) + '</p>'
          );
        }
        TranslationWizard.renderSummary($modal, options, state.analysis || {});

        defer(function () {
          // On the last step the core labels "next" with that slide's progress
          // title ("References") - name the real action instead.
          $modal.find('.modal-footer button[name="next"]').text(TranslationWizard.lang('eset_translator.startTranslation'));
          MultiStepWizard.unlockPrevStep();
          if (providerOptions === '') {
            MultiStepWizard.lockNextStep();
          } else {
            MultiStepWizard.unlockNextStep();
          }
        });
      }
    );

    MultiStepWizard.addFinalProcessingSlide(function ($slide) {
      var $modal = modalOf($slide);
      var data = TranslationWizard.collect($modal, pageUid);

      MultiStepWizard.lockPrevStep();
      MultiStepWizard.lockNextStep();
      TranslationWizard.submitJob(data, 'automated').then(function (result) {
        TranslationWizard.notify(result);
        if (!result.success) {
          // Back to the last step so the editor can change the settings.
          MultiStepWizard.unlockPrevStep();
          MultiStepWizard.triggerStepButton('prev');
          return;
        }
        MultiStepWizard.dismiss();
        if (result.referenceReport && result.referenceReport.length) {
          TranslationWizard.refreshContent();
        }
      }).catch(function (error) {
        TranslationWizard.requestFailed(error);
        MultiStepWizard.unlockPrevStep();
        MultiStepWizard.triggerStepButton('prev');
      });
    }).then(function () {
      MultiStepWizard.show();
    });
  };

  // --- "exchange": modal with Export / Import tabs ------------------------------

  TranslationWizard.buildExchangeContent = function (options) {
    var $root = $('<div>', { 'class': 'eset-wizard' });
    $root.append('<style>' + STYLE + '</style>');
    $root.append(
      '<ul class="nav nav-tabs">'
      + '<li data-eset-tab="export"><a href="#">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.tab.export', 'Export')) + '</a></li>'
      + '<li data-eset-tab="import"><a href="#">' + TranslationWizard.escape(TranslationWizard.lang('eset_translator.tab.import', 'Import')) + '</a></li>'
      + '</ul>'
    );

    var $form = $('<form>', { 'class': 'eset-translator-wizard', id: 'eset-translator-form' });
    $form.append($('<div>', { 'data-eset-step': '1' }).append(TranslationWizard.stepLanguagesHtml(options)));
    $form.append($('<div>', { 'data-eset-step': '2', hidden: true }).append(TranslationWizard.stepContentHtml()));
    $form.append($('<div>', { 'data-eset-step': '3', hidden: true }).append('<div class="eset-references"></div>'));
    $form.append($('<div>', { 'class': 'eset-final', hidden: true }).append(TranslationWizard.formatFieldHtml(options)));

    $root.append(
      $('<div>', { 'class': 'eset-pane', 'data-eset-pane': 'export' })
        .append(
          '<ol class="eset-steps">'
          + '<li data-eset-step-indicator="1">' + TranslationWizard.lang('eset_translator.step.languages', 'Languages') + '</li>'
          + '<li data-eset-step-indicator="2">' + TranslationWizard.lang('eset_translator.step.content', 'Content') + '</li>'
          + '<li data-eset-step-indicator="3">' + TranslationWizard.lang('eset_translator.step.references', 'References') + '</li>'
          + '</ol>'
        )
        .append($form)
    );
    $root.append(
      $('<div>', { 'class': 'eset-pane', 'data-eset-pane': 'import', hidden: true }).append(
        '<div class="form-group">'
        + '<label class="form-label" for="eset-translator-file">' + TranslationWizard.lang('eset_translator.importFile') + '</label>'
        + '<input type="file" class="form-control" id="eset-translator-file" name="file" accept=".xlf,.xliff,.xml">'
        + '<p class="help-block">' + TranslationWizard.lang('eset_translator.importHelp') + '</p>'
        + '</div>'
      )
    );

    return $root;
  };

  /**
   * Export / import modal state.
   */
  TranslationWizard.ExchangeSession = function ($modal, pageUid) {
    this.$modal = $modal;
    this.pageUid = pageUid;
    this.tab = 'export';
    this.step = 1;
    this.analysis = null;
    this.analysisKey = '';
    this.busy = false;
  };

  TranslationWizard.ExchangeSession.prototype.lastStep = function () {
    return TranslationWizard.hasActionableReferences(this.analysis) ? 3 : 2;
  };

  TranslationWizard.ExchangeSession.prototype.button = function (name) {
    return this.$modal.find('.modal-footer [name="' + name + '"]');
  };

  TranslationWizard.ExchangeSession.prototype.render = function () {
    var self = this;
    var $modal = this.$modal;
    var isImport = this.tab === 'import';
    var isFinal = !isImport && this.step === this.lastStep();
    var clash = TranslationWizard.hasLanguageClash($modal);

    $modal.find('[data-eset-tab]').each(function () {
      $(this).toggleClass('active', $(this).data('eset-tab') === self.tab);
    });
    $modal.find('[data-eset-pane="export"]').prop('hidden', isImport);
    $modal.find('[data-eset-pane="import"]').prop('hidden', !isImport);

    $modal.find('[data-eset-step]').each(function () {
      $(this).prop('hidden', parseInt($(this).data('eset-step'), 10) !== self.step);
    });
    $modal.find('[data-eset-step-indicator]').each(function () {
      var step = parseInt($(this).data('eset-step-indicator'), 10);
      $(this)
        .toggleClass('active', step === self.step)
        .toggleClass('done', step < self.step)
        .toggleClass('disabled', step === 3 && self.analysis !== null && self.lastStep() < 3);
    });

    $modal.find('.eset-translator-lang-warning').prop('hidden', !clash);
    $modal.find('.eset-final').prop('hidden', !isFinal);

    this.button('eset-back').toggle(!isImport && this.step > 1);
    this.button('eset-next').toggle(!isImport && !isFinal).prop('disabled', this.busy || (this.step === 1 && clash));
    this.button('eset-download').toggle(isFinal).prop('disabled', this.busy);
    this.button('eset-import').toggle(isImport).prop('disabled', this.busy);
  };

  TranslationWizard.ExchangeSession.prototype.setBusy = function (busy) {
    this.busy = busy;
    this.render();
  };

  TranslationWizard.ExchangeSession.prototype.next = function () {
    var self = this;
    if (this.step === 1) {
      if (TranslationWizard.hasLanguageClash(this.$modal)) {
        return;
      }
      var data = TranslationWizard.collect(this.$modal, this.pageUid);
      var key = TranslationWizard.analysisKey(data);
      if (this.analysis !== null && key === this.analysisKey) {
        this.step = 2;
        this.render();
        return;
      }
      this.setBusy(true);
      TranslationWizard.analyze(data).then(function (analysis) {
        self.busy = false;
        if (!analysis.success) {
          self.render();
          Notification.error(TranslationWizard.lang('eset_translator.title'), analysis.message);
          return;
        }
        self.analysis = analysis;
        self.analysisKey = key;
        TranslationWizard.renderContentTypes(self.$modal, analysis.contentTypes);
        TranslationWizard.renderReferences(self.$modal, analysis);
        self.step = 2;
        self.render();
      }).catch(function (error) {
        self.setBusy(false);
        TranslationWizard.requestFailed(error);
      });
      return;
    }
    if (this.step < this.lastStep()) {
      this.step++;
      this.render();
    }
  };

  TranslationWizard.ExchangeSession.prototype.back = function () {
    if (this.step > 1) {
      this.step--;
      this.render();
    }
  };

  TranslationWizard.ExchangeSession.prototype.exportFile = function () {
    var self = this;
    var data = TranslationWizard.collect(this.$modal.find('[data-eset-pane="export"]'), this.pageUid);

    this.setBusy(true);
    TranslationWizard.submitJob(data, 'manual').then(function (result) {
      self.setBusy(false);
      TranslationWizard.notify(result);
      if (!result.success) {
        return;
      }
      Modal.dismiss();
      if (result.downloadUrl) {
        window.location.href = result.downloadUrl;
      }
      if (result.referenceReport && result.referenceReport.length) {
        TranslationWizard.refreshContent();
      }
    }).catch(function (error) {
      self.setBusy(false);
      TranslationWizard.requestFailed(error);
    });
  };

  TranslationWizard.ExchangeSession.prototype.importFile = function () {
    var self = this;
    var promise = TranslationWizard.upload(this.$modal, this.pageUid);
    if (promise === null) {
      Notification.warning(TranslationWizard.lang('eset_translator.title'), TranslationWizard.lang('eset_translator.noFile'));
      return;
    }
    this.setBusy(true);
    promise.then(function (result) {
      self.setBusy(false);
      Modal.dismiss();
      TranslationWizard.notify(result);
      if (result && result.success) {
        TranslationWizard.refreshContent();
      }
    }).catch(function (error) {
      self.setBusy(false);
      TranslationWizard.requestFailed(error);
    });
  };

  TranslationWizard.ExchangeSession.prototype.bind = function () {
    var self = this;
    var $modal = this.$modal;

    $modal.on('click', '[data-eset-tab] a', function (event) {
      event.preventDefault();
      self.tab = $(this).closest('[data-eset-tab]').data('eset-tab');
      self.render();
    });
    $modal.on('change', '[name="source"], [name="target"], [name="depth"]', function () {
      self.render();
    });
    $modal.on('change', '#eset-ref-bulk', function () {
      TranslationWizard.applyBulk($modal, this.value);
      this.value = '';
    });
    // Enter in a field must not submit the form (it would reload the frame).
    $modal.on('submit', '#eset-translator-form', function (event) {
      event.preventDefault();
    });
    this.render();
  };

  TranslationWizard.openExchangeModal = function (options, pageUid) {
    var session = null;
    var $modal = Modal.advanced({
      title: TranslationWizard.lang('eset_translator.exchange') + ': ' + options.page.title,
      content: TranslationWizard.buildExchangeContent(options),
      severity: Severity.notice,
      size: Modal.sizes.large,
      buttons: [
        {
          text: TranslationWizard.lang('eset_translator.cancel'),
          btnClass: 'btn-default',
          name: 'eset-cancel',
          trigger: function () { Modal.dismiss(); }
        },
        {
          text: TranslationWizard.lang('eset_translator.back', 'Back'),
          btnClass: 'btn-default',
          name: 'eset-back',
          trigger: function () { session.back(); }
        },
        {
          text: TranslationWizard.lang('eset_translator.next', 'Next'),
          btnClass: 'btn-primary',
          name: 'eset-next',
          trigger: function () { session.next(); }
        },
        {
          text: TranslationWizard.lang('eset_translator.createAndDownload', 'Create job and download file'),
          btnClass: 'btn-primary',
          name: 'eset-download',
          trigger: function () { session.exportFile(); }
        },
        {
          text: TranslationWizard.lang('eset_translator.import'),
          btnClass: 'btn-warning',
          name: 'eset-import',
          trigger: function () { session.importFile(); }
        }
      ]
    });

    // The modal markup exists as soon as advanced() returns; binding now keeps
    // the step buttons from flashing while the modal fades in.
    session = new TranslationWizard.ExchangeSession($modal, pageUid);
    session.bind();
  };

  // --- entry point ----------------------------------------------------------------

  /**
   * @param {number} pageUid
   * @param {string} mode "request" or "exchange" - the button that was used
   */
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

      if (mode === 'request') {
        if (!options.automatedAvailable || options.canRequest === false) {
          Notification.info(TranslationWizard.lang('eset_translator.title'), TranslationWizard.lang('eset_translator.noProvider'));
          return;
        }
        TranslationWizard.openRequestWizard(options, pageUid);
        return;
      }

      if (options.canExchange === false) {
        Notification.info(
          TranslationWizard.lang('eset_translator.title'),
          TranslationWizard.lang('eset_translator.noExchange', 'You have no permission to export or import translations.')
        );
        return;
      }
      TranslationWizard.openExchangeModal(options, pageUid);
    }).catch(function (error) {
      TranslationWizard.requestFailed(error);
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
