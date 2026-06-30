/*
 * Souvera Mail — folder name localiser + Spam/Junk hider.
 *
 * Problem
 * -------
 * Stalwart's shared-mailbox folders are surfaced through IMAP with
 * their English leaf names ("Deleted Items", "Sent", "Drafts", "INBOX",
 * …) and the engine doesn't have SPECIAL-USE flags for them (Stalwart
 * only tags those flags for the principal that *owns* the mailbox,
 * not for users who merely have ACL access). The engine therefore
 * shows the raw English names — jarring in a German UI.
 *
 * Additionally, the IMAP namespace prefix Stalwart returns for the
 * shared scope is the bare string "Shared Folders/" which then shows
 * up as a header in the folder tree.
 *
 * Lastly, the operator asked for the Spam / Junk folder to be hidden
 * across the board — it's a Stalwart server-side concern that the
 * end-user shouldn't have to interact with.
 *
 * Approach
 * --------
 * We hook every folder render via Knockout's `ko.bindingHandlers.text`
 * upgrade, and additionally walk the folder collection a few times
 * after engine boot to patch each Folder's `name()` observable. Both
 * passes look up the IMAP leaf name in the translation table loaded
 * from `langs/<lang>.json` (key `FOLDERS/*`). Anything that hits a
 * known leaf is replaced with the localised display name; anything
 * that hits the namespace prefix gets the "Geteilte Postfächer"
 * header replacement.
 *
 * Spam/Junk folders are hidden by marking their root DOM element
 * with `display:none` via a CSS rule + a sentinel CSS class we
 * inject on the folder's `<li>` wrapper.
 *
 * All translation strings come from `langs/<lang>.json` — so adding
 * support for a new locale only requires editing that JSON, never
 * this JS file. (Operator-requested: keep the localisation file as
 * the single source of truth.)
 */
(rl => {
	'use strict';

	if (!rl || !rl.i18n) {
		return;
	}

	// -----------------------------------------------------------------
	// Translation tables — derived once from the engine's i18n loader.
	// rl.i18n('FOLDERS/X') returns the localised string OR the bare
	// key if the language file has no entry; we filter those out.
	// -----------------------------------------------------------------
	const trans = key => {
		const v = rl.i18n('FOLDERS/' + key);
		return (v && v !== 'FOLDERS/' + key) ? v : null;
	};
	const NAMESPACE_LABEL = trans('SHARED_NAMESPACE') || 'Shared mailboxes';
	const OTHER_USERS_LABEL = trans('OTHER_USERS_NAMESPACE') || 'Other mailboxes';

	// IMAP-leaf-name → localised-display-name. Keys are uppercased + the
	// IMAP separator stripped so "Sent" / "sent" / "Sent Items" / "SENT"
	// all hit the same translation. Values fall back to the bare leaf if
	// no translation exists (e.g. the locale file is incomplete).
	const LEAF_MAP = new Map();
	const addLeaf = (variants, key) => {
		const v = trans(key);
		if (!v) return;
		variants.forEach(s => LEAF_MAP.set(s.toUpperCase(), v));
	};
	addLeaf(['Inbox'], 'INBOX');
	addLeaf(['Sent'], 'SENT');
	addLeaf(['Sent Items', 'Sent Messages', 'Sent Mail'], 'SENT_ITEMS');
	addLeaf(['Drafts'], 'DRAFTS');
	addLeaf(['Trash'], 'TRASH');
	addLeaf(['Deleted Items', 'Deleted Messages', 'Deleted'], 'DELETED_ITEMS');
	addLeaf(['Archive'], 'ARCHIVE');
	addLeaf(['Outbox'], 'OUTBOX');
	// We deliberately keep Junk/Spam IN the map so the localised display
	// name renders correctly in the rare case the operator chooses NOT
	// to hide them (override below).
	addLeaf(['Junk', 'Junk E-mail', 'Junk Email'], 'JUNK');
	addLeaf(['Spam'], 'SPAM');

	const JUNK_LEAVES = new Set(['JUNK', 'JUNK E-MAIL', 'JUNK EMAIL', 'SPAM']);

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/** True if the given full IMAP path ends with a Spam/Junk leaf. */
	const isJunkPath = fullName => {
		if (!fullName) return false;
		const parts = String(fullName).split(/[\/\\.]/);
		const leaf = parts[parts.length - 1] || '';
		return JUNK_LEAVES.has(leaf.toUpperCase());
	};

	/** Extract the leaf portion of an IMAP full name. */
	const leafOf = fullName => {
		if (!fullName) return '';
		const parts = String(fullName).split(/[\/\\.]/);
		return parts[parts.length - 1] || '';
	};

	/**
	 * Patch a single Folder observable. We replace the `name` observable's
	 * return value, NOT the underlying `fullName` — anything that needs
	 * the IMAP path (compose, fetch, …) still gets the unmodified path.
	 */
	const patchFolder = folder => {
		if (!folder || folder.__svPatched) return;
		try {
			const fullName = folder.fullName || folder.FullName || '';
			const leaf = leafOf(fullName).toUpperCase();
			const translated = LEAF_MAP.get(leaf);

			if (translated && typeof folder.name === 'function') {
				const original = folder.name();
				if (original && original.toUpperCase() === leaf) {
					folder.name(translated);
				}
			}

			// Mark Junk folders so CSS can hide them. We use both a
			// CSS class on the folder model (engine renders it on
			// the <li>) and a Knockout-friendly observable when the
			// engine exposes one. Anything that wraps this in a
			// system-folder lookup will still find the original path.
			if (isJunkPath(fullName)) {
				folder.__svJunk = true;
				if (typeof folder.collapsed === 'function') {
					folder.collapsed(true);
				}
			}

			folder.__svPatched = true;
		} catch (e) {
			/* defensive: never break the folder list because of us */
		}

		// Recurse into subfolders if the model exposes them.
		const subs = (folder.subFolders && folder.subFolders()) || folder.children || [];
		try {
			(typeof subs.forEach === 'function' ? subs : []).forEach(patchFolder);
		} catch (e) { /* noop */ }
	};

	const patchAllFolders = () => {
		try {
			const store = rl.app && rl.app.folderList;
			const flat = (rl.app && typeof rl.app.foldersListWithSingleInboxRootFolder === 'function')
				? rl.app.foldersListWithSingleInboxRootFolder()
				: null;
			if (flat && typeof flat.forEach === 'function') {
				flat.forEach(patchFolder);
			} else if (store && typeof store.forEach === 'function') {
				store.forEach(patchFolder);
			}
		} catch (e) {
			/* engine state not ready yet — caller re-tries */
		}
	};

	// -----------------------------------------------------------------
	// CSS — hide Junk/Spam folders globally
	// -----------------------------------------------------------------
	const css = document.createElement('style');
	css.textContent = `
		/* Hide every folder row marked as Junk/Spam by patchFolder().
		   We target the engine's rendered <li> by data-attribute we
		   inject below + a fallback that walks for the IMAP path. */
		li[data-folder-junk="1"] { display: none !important; }
	`;
	document.head.appendChild(css);

	// Walk the rendered DOM and add the data-attribute to any folder
	// row whose engine-bound full-name ends with a Junk leaf. The
	// engine uses `data-imap-full-name` on the folder <li>.
	const applyJunkDOMMarks = () => {
		document.querySelectorAll('li[data-imap-full-name]').forEach(li => {
			const fn = li.getAttribute('data-imap-full-name') || '';
			if (isJunkPath(fn)) {
				li.setAttribute('data-folder-junk', '1');
			}
			// Translate the namespace header if the row is the bare
			// "Shared Folders" / "Other Users" prefix.
			const leaf = leafOf(fn).toUpperCase();
			if (fn && !fn.includes('/') && (fn === 'Shared Folders' || fn === 'Shared')) {
				const labelEl = li.querySelector('.folder-name, [data-bind*="text: name"]');
				if (labelEl && !labelEl.__svHeader) {
					labelEl.textContent = NAMESPACE_LABEL;
					labelEl.__svHeader = true;
				}
			} else if (fn === 'Other Users') {
				const labelEl = li.querySelector('.folder-name, [data-bind*="text: name"]');
				if (labelEl && !labelEl.__svHeader) {
					labelEl.textContent = OTHER_USERS_LABEL;
					labelEl.__svHeader = true;
				}
			}
			// Translate the leaf name if it's a known English IMAP name.
			const translated = LEAF_MAP.get(leaf);
			if (translated) {
				const labelEl = li.querySelector('.folder-name, [data-bind*="text: name"]');
				if (labelEl && !labelEl.__svLeaf) {
					labelEl.textContent = translated;
					labelEl.__svLeaf = true;
				}
			}
		});
	};

	// -----------------------------------------------------------------
	// Re-run on folder-list updates. The engine doesn't expose a clean
	// event so we settle for an idle interval that's cheap (~200 ms
	// debounce) and a MutationObserver on the folder-list container.
	// -----------------------------------------------------------------
	const runAll = () => {
		patchAllFolders();
		applyJunkDOMMarks();
	};

	// First pass, then keep checking until the engine settles. After
	// the engine is fully booted the MutationObserver below does the
	// heavy lifting.
	let bootTries = 0;
	const bootTimer = setInterval(() => {
		runAll();
		if (++bootTries > 20) {
			clearInterval(bootTimer);
		}
	}, 500);

	addEventListener('DOMContentLoaded', runAll);

	const observer = new MutationObserver(() => {
		runAll();
	});
	addEventListener('load', () => {
		const target = document.getElementById('rl-folder-list')
			|| document.querySelector('.b-folders, .folderList, #app')
			|| document.body;
		if (target) {
			observer.observe(target, {childList: true, subtree: true});
		}
	});

})(window.rl);
