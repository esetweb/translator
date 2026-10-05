/*
 * ESET Translator – jobs module actions.
 *
 * "Send to provider now" starts the job via AJAX in the background. Like core
 * actions, the button shows a spinner instead of its icon while the job runs;
 * when it is finished the view is reloaded once to show the result.
 *
 * Also loads the core Modal, which turns the ".t3js-modal-trigger" delete links
 * into native TYPO3 confirm dialogs.
 */
define([
  'jquery',
  'TYPO3/CMS/Backend/Modal',
  'TYPO3/CMS/Backend/Icons',
  'TYPO3/CMS/Backend/Notification',
  'TYPO3/CMS/EsetTranslator/TranslationWizard'
], function ($, Modal, Icons, Notification, TranslationWizard) {
  'use strict';

  var POLL_INTERVAL = 3000;
  // Status polls in a row that may fail before the error is shown.
  var MAX_STATUS_FAILURES = 3;
  var TITLE = 'ESET Translator';

  var JobActions = {};

  JobActions.setBusy = function ($button, busy) {
    var $icon = $button.find('.t3js-icon').first();
    if (busy) {
      $button.addClass('disabled').attr('aria-busy', 'true').data('eset-icon', $icon.prop('outerHTML'));
      Icons.getIcon('spinner-circle-dark', Icons.sizes.small).then(function (markup) {
        $icon.replaceWith(markup);
      });
      return;
    }
    if ($button.data('eset-icon')) {
      $button.find('.t3js-icon').first().replaceWith($button.data('eset-icon'));
    }
    $button.removeClass('disabled').removeAttr('aria-busy');
  };

  JobActions.status = function (uid) {
    return TranslationWizard.call('eset_translator_job_status', 'get', { jobs: String(uid) });
  };

  /**
   * Waits until the job is no longer queued / running, then reloads the view.
   * Transient failures are retried a few times; then the error is shown.
   */
  JobActions.waitForResult = function (uid, $button, failures) {
    failures = failures || 0;
    window.setTimeout(function () {
      JobActions.status(uid).then(function (result) {
        var job = result && result.jobs ? result.jobs[String(uid)] : null;
        if (job && job.active) {
          JobActions.waitForResult(uid, $button, 0);
          return;
        }
        if (job && job.status === 'failed') {
          Notification.error(TITLE, job.errorMessage || job.statusLabel);
        } else if (job) {
          Notification.success(TITLE, job.statusLabel);
        }
        window.location.reload();
      }).catch(function (error) {
        if (failures < MAX_STATUS_FAILURES) {
          JobActions.waitForResult(uid, $button, failures + 1);
          return;
        }
        JobActions.setBusy($button, false);
        Notification.error(TITLE, TranslationWizard.errorMessage(error));
      });
    }, POLL_INTERVAL);
  };

  JobActions.run = function ($button) {
    var uid = parseInt($button.data('eset-run-job'), 10);
    JobActions.setBusy($button, true);

    TranslationWizard.call('eset_translator_job_run', 'post', { job: uid })
      .then(function (result) {
        if (!result.success) {
          JobActions.setBusy($button, false);
          Notification.error(TITLE, result.message);
          return;
        }
        if (!result.background) {
          // Queued for the scheduler task - nothing to wait for here.
          Notification.info(TITLE, result.message);
          window.location.reload();
          return;
        }
        JobActions.waitForResult(uid, $button, 0);
      })
      .catch(function (error) {
        JobActions.setBusy($button, false);
        TranslationWizard.requestFailed(error);
      });
  };

  $(document).on('click', '[data-eset-run-job]', function (event) {
    event.preventDefault();
    var $button = $(this);
    if (!$button.hasClass('disabled')) {
      JobActions.run($button);
    }
  });

  return JobActions;
});
