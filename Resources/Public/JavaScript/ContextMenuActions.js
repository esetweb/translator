/*
 * Callback module for the "ESET" submenu in the page tree context menu.
 */
define([
  'TYPO3/CMS/EsetTranslator/TranslationWizard'
], function (TranslationWizard) {
  'use strict';

  var ContextMenuActions = {};

  /**
   * @param {string} table
   * @param {number} uid
   */
  ContextMenuActions.requestTranslation = function (table, uid) {
    TranslationWizard.open(parseInt(uid, 10), 'request');
  };

  ContextMenuActions.exchangeTranslation = function (table, uid) {
    TranslationWizard.open(parseInt(uid, 10), 'exchange');
  };

  ContextMenuActions.openJobs = function (table, uid) {
    if (top.TYPO3.ModuleMenu && top.TYPO3.ModuleMenu.App) {
      top.TYPO3.ModuleMenu.App.showModule('eset_EsetTranslatorJobs', 'filter[pageUid]=' + parseInt(uid, 10));
    }
  };

  return ContextMenuActions;
});
