/* global rl, ko */

/*
 * Souvera Mail — "Hilfe & Anleitung" Settings Tab
 *
 * Read-only companion to the "Sicherheit & Geräte" tab. Registers a
 * native Snappymail Settings ViewModel at `#/settings/souvera-help`
 * that surfaces every piece of configuration a user needs to hook a
 * third-party mail client (IMAP/POP3/SMTP), a calendar / contacts
 * client (CalDAV / CardDAV) or access the Souvera Shield spam
 * quarantine. Also lists the mail-mode keyboard shortcuts + mobile
 * app recommendations.
 *
 * All configuration values are read from `rl.settings.get('Nextcloud')`
 * which is populated by the FilterAppData hook in
 * app/plugins/nextcloud/index.php. If a value is missing the row
 * gracefully falls back to a friendly placeholder — the tab NEVER
 * crashes the settings screen, even on a freshly installed cluster
 * with no domain config yet.
 */
(function () {
        'use strict';

        if (!window.rl || typeof rl.addSettingsViewModel !== 'function') {
                return;
        }

        var cfg = rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
        if (!cfg) {
                return;
        }

        function orDash(v) {
                var s = (v === undefined || v === null) ? '' : String(v);
                return s === '' ? '—' : s;
        }

        function copy(text) {
                if (!text) { return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(String(text));
                }
        }

        function SouveraHelpSettings() {
                // IMAP
                this.imapHost = ko.observable(orDash(cfg.SmailHelpImapHost));
                this.imapPort = ko.observable(orDash(cfg.SmailHelpImapPort));
                this.imapSsl = ko.observable(orDash(cfg.SmailHelpImapSsl));

                // POP3
                this.pop3Host = ko.observable(orDash(cfg.SmailHelpPop3Host));
                this.pop3Port = ko.observable(orDash(cfg.SmailHelpPop3Port));
                this.pop3Ssl = ko.observable(orDash(cfg.SmailHelpPop3Ssl));

                // SMTP
                this.smtpHost = ko.observable(orDash(cfg.SmailHelpSmtpHost));
                this.smtpPort = ko.observable(orDash(cfg.SmailHelpSmtpPort));
                this.smtpSsl = ko.observable(orDash(cfg.SmailHelpSmtpSsl));

                // Sieve
                this.sieveHost = ko.observable(orDash(cfg.SmailHelpSieveHost));
                this.sievePort = ko.observable(orDash(cfg.SmailHelpSievePort));
                this.sieveSsl = ko.observable(orDash(cfg.SmailHelpSieveSsl));

                // CalDAV / CardDAV
                this.calDavUrl = ko.observable(orDash(cfg.SmailHelpCalDavUrl));
                this.cardDavUrl = ko.observable(orDash(cfg.SmailHelpCardDavUrl));

                // Shield / Quarantine
                this.shieldUrl = ko.observable(String(cfg.SmailHelpShieldUrl || ''));
                this.shieldAvailable = ko.computed(function () { return !!this.shieldUrl(); }, this);

                // Identity
                this.userEmail = ko.observable(orDash(cfg.SmailHelpEmail));
                this.userDomain = ko.observable(orDash(cfg.SmailHelpDomain));
        }

        SouveraHelpSettings.prototype = {
                copyImap: function () { copy(this.imapHost() + ':' + this.imapPort()); },
                copyPop3: function () { copy(this.pop3Host() + ':' + this.pop3Port()); },
                copySmtp: function () { copy(this.smtpHost() + ':' + this.smtpPort()); },
                copySieve: function () { copy(this.sieveHost() + ':' + this.sievePort()); },
                copyCalDav: function () { copy(this.calDavUrl()); },
                copyCardDav: function () { copy(this.cardDavUrl()); },
                copyEmail: function () { copy(this.userEmail()); },

                onBuild: function () { /* template binds via Knockout */ },
                beforeShow: function () { /* nothing */ },
                onShow: function () { /* read-only tab, no fetches */ }
        };

        rl.addSettingsViewModel(
                SouveraHelpSettings,
                'SettingsSouveraHelp',   // template basename → templates/SettingsSouveraHelp.html
                'Hilfe & Anleitung',     // sidebar label
                'souvera-help'           // hash route → #/settings/souvera-help
        );

})();
