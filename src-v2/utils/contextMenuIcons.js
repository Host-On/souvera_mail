/**
 * Stroke-SVG-Icons für die Kontextmenüs (16px, currentColor via CSS).
 * Gleicher Stil wie die bisherigen Inline-Icons in MailHomeView.
 */

const svg = (paths) => `<svg viewBox="0 0 24 24">${paths}</svg>`

export const CTX_ICONS = {
	eye: svg('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>'),
	mail: svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'),
	alert: svg('<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>'),
	folder: svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>'),
	folderOpen: svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v2"/><path d="M3 19V7"/><path d="m3 19 3.5-6h15L18 19H3Z"/>'),
	trash: svg('<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="m6 7 1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M10 11v6M14 11v6"/>'),
	star: svg('<path d="m12 3 2.7 5.6 6.1.8-4.5 4.3 1.1 6.1L12 16.9 6.6 19.8l1.1-6.1L3.2 9.4l6.1-.8L12 3Z"/>'),
	reply: svg('<path d="M9 10 4 15l5 5"/><path d="M4 15h10a6 6 0 0 0 6-6V6"/>'),
	replyAll: svg('<path d="m6 10-5 5 5 5"/><path d="M1 15h9a6 6 0 0 0 6-6V6"/><path d="m10 10-5 5 5 5"/>'),
	forward: svg('<path d="m15 10 5 5-5 5"/><path d="M20 15H10a6 6 0 0 1-6-6V6"/>'),
	download: svg('<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 19h16"/>'),
	save: svg('<path d="M5 3h11l3 3v15H5V3Z"/><path d="M8 3v5h7V3"/><path d="M8 13h8v8H8v-8Z"/>'),
	openInFolder: svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/><circle cx="12" cy="13" r="2.4"/><path d="m13.8 14.8 2.7 2.7"/>'),
	check: svg('<path d="m4 12.5 5 5L20 6.5"/>'),
	refresh: svg('<path d="M20 11a8 8 0 1 0-2.3 6.3"/><path d="M20 5v6h-6"/>'),
	pencil: svg('<path d="m4 20 1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19l-4 1Z"/>'),
	broom: svg('<path d="M19 3 12.5 9.5"/><path d="M11 8 4 15c-1.5 1.5-1.5 4 0 5s3.5 1.5 5 0l7-7-5-5Z"/><path d="M8 12l4 4"/>'),
	info: svg('<circle cx="12" cy="12" r="9"/><path d="M12 8h.01"/><path d="M12 11v5"/>'),
}
