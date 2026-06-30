/* global rl, ko, OC */

/*
 * Souvera Mail — "Sicherheit & Geräte" Settings Tab
 *
 * Registers a native Snappymail Settings ViewModel via
 * `rl.addSettingsViewModel(...)`. Inserts a new tab at
 * `#/settings/souvera-account` in the Snappymail Settings screen,
 * shown next to "Allgemein", "Kontakte", "Filter", "Sicherheit",
 * "Ordner".
 *
 * The tab consolidates three controls that used to live on the
 * Nextcloud-chrome page at `/index.php/apps/souvera_mail/settings`:
 *
 *   - Dashboard widget mode (radio: `unread` / `all`)
 *   - App Passwords for legacy mail clients (Stalwart JMAP)
 *   - Connected Devices (Nextcloud session tokens + sign-out-others)
 *
 * Reads all URLs from `rl.settings.get('Nextcloud')` (set by
 * app/plugins/nextcloud/index.php :: FilterAppData). All HTTP calls
 * carry the Nextcloud CSRF requesttoken so the existing
 * `#[NoCSRFRequired]`-less endpoints accept them.
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

	function csrfHeaders(contentType) {
		var token = (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '';
		var h = { 'Accept': 'application/json', 'requesttoken': token };
		if (contentType) {
			h['Content-Type'] = contentType;
		}
		return h;
	}

	function fmtDate(iso) {
		if (!iso) { return ''; }
		try {
			var d = new Date(iso);
			if (isNaN(d.getTime())) { return iso; }
			return d.toLocaleString();
		} catch (e) {
			return iso;
		}
	}

	function fmtRelative(epochSeconds) {
		if (!epochSeconds) { return ''; }
		var now = Math.floor(Date.now() / 1000);
		var diff = now - Number(epochSeconds);
		if (!isFinite(diff)) { return ''; }
		if (diff < 60) { return 'just now'; }
		if (diff < 3600) { return Math.floor(diff / 60) + ' min ago'; }
		if (diff < 86400) { return Math.floor(diff / 3600) + ' h ago'; }
		return Math.floor(diff / 86400) + ' d ago';
	}

	function SouveraAccountSettings() {
		// Dashboard widget mode
		this.dashboardMode = ko.observable(cfg.SmailDashboardMode || 'unread');
		this.dashboardModeStatus = ko.observable('');

		// App passwords
		this.appPasswordsAvailable = !!cfg.SmailAppPasswordsAvailable;
		this.newAppPasswordDescription = ko.observable('');
		this.appPasswords = ko.observableArray([]);
		this.appPasswordsLoading = ko.observable(false);
		this.appPasswordError = ko.observable('');
		this.appPasswordsCreating = ko.observable(false);
		// Plaintext secret shown ONCE after creation
		this.justCreatedSecret = ko.observable('');
		this.justCreatedDescription = ko.observable('');
		this.justCreatedUsername = ko.observable('');

		// Connected devices
		this.devicesLoading = ko.observable(false);
		this.devices = ko.observableArray([]);
		this.devicesError = ko.observable('');
		this.signOutOthersBusy = ko.observable(false);

		// Derived
		var self = this;
		this.hasAppPasswords = ko.computed(function () { return self.appPasswords().length > 0; });
		this.hasDevices = ko.computed(function () { return self.devices().length > 0; });
	}

	SouveraAccountSettings.prototype = {

		// ---------------- Dashboard widget mode ----------------
		saveDashboardMode: function (mode) {
			if (mode !== 'unread' && mode !== 'all') { return; }
			this.dashboardMode(mode);
			this.dashboardModeStatus('saving');
			var url = cfg.SmailDashboardModeUrl;
			if (!url) { this.dashboardModeStatus('error'); return; }
			var body = 'mode=' + encodeURIComponent(mode);
			var self = this;
			fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: csrfHeaders('application/x-www-form-urlencoded'),
				body: body
			})
				.then(function (resp) { return resp.ok ? resp.json() : null; })
				.then(function (b) {
					self.dashboardModeStatus(b && b.status === 'ok' ? 'saved' : 'error');
					setTimeout(function () { self.dashboardModeStatus(''); }, 1500);
				})
				.catch(function () {
					self.dashboardModeStatus('error');
				});
		},
		pickModeUnread: function () { this.saveDashboardMode('unread'); },
		pickModeAll: function () { this.saveDashboardMode('all'); },

		// ---------------- App passwords ----------------
		loadAppPasswords: function () {
			if (!this.appPasswordsAvailable || !cfg.SmailAppPasswordsListUrl) { return; }
			this.appPasswordsLoading(true);
			this.appPasswordError('');
			var self = this;
			fetch(cfg.SmailAppPasswordsListUrl, {
				method: 'GET',
				credentials: 'same-origin',
				headers: csrfHeaders()
			})
				.then(function (resp) { return resp.ok ? resp.json() : null; })
				.then(function (body) {
					if (!body || body.status !== 'ok') {
						self.appPasswordError('Failed to load app passwords');
					} else {
						self.appPasswords((body.items || []).map(function (it) {
							return {
								id: String(it.id || ''),
								description: String(it.description || ''),
								createdAt: fmtDate(it.createdAt || '')
							};
						}));
					}
				})
				.catch(function () { self.appPasswordError('Network error'); })
				.then(function () { self.appPasswordsLoading(false); });
		},

		createAppPassword: function () {
			var desc = String(this.newAppPasswordDescription() || '').trim();
			if (!desc) { this.appPasswordError('Please enter a description'); return; }
			if (!cfg.SmailAppPasswordsCreateUrl) { return; }
			this.appPasswordsCreating(true);
			this.appPasswordError('');
			var self = this;
			fetch(cfg.SmailAppPasswordsCreateUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: csrfHeaders('application/x-www-form-urlencoded'),
				body: 'description=' + encodeURIComponent(desc)
			})
				.then(function (resp) {
					// Read body regardless of HTTP status so we can surface the
					// server's error message (the backend always returns JSON,
					// even on 4xx / 5xx).
					return resp.json().then(function (b) { return { ok: resp.ok, body: b }; });
				})
				.then(function (r) {
					var body = r.body || {};
					if (!r.ok || body.status !== 'ok') {
						self.appPasswordError(body.message || ('HTTP ' + (r.body ? '' : 'error')) || 'Creation failed');
						return;
					}
					// Backend returns { status:'ok', created:{ id, secret, description, username } }
					var created = body.created || {};
					self.justCreatedSecret(String(created.secret || ''));
					self.justCreatedDescription(String(created.description || desc));
					self.justCreatedUsername(String(created.username || ''));
					self.newAppPasswordDescription('');
					self.loadAppPasswords();
				})
				.catch(function (err) {
					self.appPasswordError('Network error: ' + (err && err.message ? err.message : 'unknown'));
				})
				.then(function () { self.appPasswordsCreating(false); });
		},

		dismissNewSecret: function () {
			this.justCreatedSecret('');
			this.justCreatedDescription('');
			this.justCreatedUsername('');
		},

		copySecret: function () {
			var s = this.justCreatedSecret();
			if (!s) { return; }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(s);
			}
		},

		copyUsername: function () {
			var u = this.justCreatedUsername();
			if (!u) { return; }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(u);
			}
		},

		revokeAppPassword: function (row) {
			if (!row || !row.id) { return; }
			if (!confirm('Revoke this app password?')) { return; }
			var tpl = cfg.SmailAppPasswordsDestroyUrlTemplate || '';
			var url = tpl.replace('__ID__', encodeURIComponent(row.id));
			if (!url) { return; }
			var self = this;
			fetch(url, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: csrfHeaders()
			})
				.then(function (resp) { return resp.ok ? resp.json() : null; })
				.then(function (body) {
					if (!body || body.status !== 'ok') {
						self.appPasswordError((body && body.message) || 'Revocation failed');
						return;
					}
					self.loadAppPasswords();
				})
				.catch(function () { self.appPasswordError('Network error'); });
		},

		// ---------------- Connected devices ----------------
		loadDevices: function () {
			if (!cfg.SmailConnectedDevicesListUrl) { return; }
			this.devicesLoading(true);
			this.devicesError('');
			var self = this;
			fetch(cfg.SmailConnectedDevicesListUrl, {
				method: 'GET',
				credentials: 'same-origin',
				headers: csrfHeaders()
			})
				.then(function (resp) {
					return resp.json().then(function (b) { return { ok: resp.ok, body: b }; })
						.catch(function () { return { ok: resp.ok, body: null }; });
				})
				.then(function (r) {
					var body = r.body || {};
					if (!r.ok || body.status !== 'ok') {
						self.devicesError(body.message || 'Failed to load devices');
						return;
					}
					self.devices((body.items || []).map(function (it) {
						return {
							id: String(it.id || ''),
							name: String(it.name || 'Session'),
							type: String(it.type || 'browser'),
							isCurrent: !!it.current,
							lastActivity: fmtRelative(it.lastActivity || 0)
						};
					}));
				})
				.catch(function (err) { self.devicesError('Network error: ' + (err && err.message ? err.message : 'unknown')); })
				.then(function () { self.devicesLoading(false); });
		},

		revokeDevice: function (row) {
			if (!row || !row.id || row.isCurrent) { return; }
			if (!confirm('Sign out this device? It will be logged out immediately.')) { return; }
			var tpl = cfg.SmailConnectedDevicesDestroyUrlTemplate || '';
			var url = tpl.replace('__ID__', encodeURIComponent(row.id));
			if (!url) { return; }
			var self = this;
			fetch(url, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: csrfHeaders()
			})
				.then(function (resp) { return resp.ok ? resp.json() : null; })
				.then(function (body) {
					if (!body || body.status !== 'ok') {
						self.devicesError((body && body.message) || 'Revocation failed');
						return;
					}
					self.loadDevices();
				})
				.catch(function () { self.devicesError('Network error'); });
		},

		signOutOthers: function () {
			if (!cfg.SmailConnectedDevicesSignOutOthersUrl) { return; }
			if (!confirm('Sign out all OTHER devices? You will stay logged in here.')) { return; }
			this.signOutOthersBusy(true);
			var self = this;
			fetch(cfg.SmailConnectedDevicesSignOutOthersUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: csrfHeaders()
			})
				.then(function (resp) { return resp.ok ? resp.json() : null; })
				.then(function (body) {
					if (!body || body.status !== 'ok') {
						self.devicesError((body && body.message) || 'Sign-out failed');
						return;
					}
					self.loadDevices();
				})
				.catch(function () { self.devicesError('Network error'); })
				.then(function () { self.signOutOthersBusy(false); });
		},

		// Hooks called by Snappymail's settings router
		onBuild: function () { /* template binds via Knockout */ },
		beforeShow: function () { /* nothing */ },
		onShow: function () {
			this.loadAppPasswords();
			this.loadDevices();
		}
	};

	rl.addSettingsViewModel(
		SouveraAccountSettings,
		'SettingsSouveraAccount',      // template name (file basename without .html)
		'Sicherheit & Geräte',         // sidebar label (literal — no i18n bundle yet)
		'souvera-account'              // hash route -> #/settings/souvera-account
	);

})();
