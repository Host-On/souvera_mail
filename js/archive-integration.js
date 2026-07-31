/**
 * Souvera Mail — Archive Integration
 *
 * Fügt einen "Archiv"-Button in die SnappyMail-Toolbar ein.
 * Der Button öffnet die Souvera-Archive-Suche in einem neuen Tab.
 *
 * @see ARCHIVE_PLAN §2.3a
 */
document.addEventListener('DOMContentLoaded', function () {
	var archiveUrl = document.querySelector('meta[name="archive-url"]')?.content
	if (!archiveUrl) return

	var btn = document.createElement('a')
	btn.className = 'btn btn-outline-secondary'
	btn.textContent = 'Archiv'
	btn.href = archiveUrl
	btn.target = '_blank'
	btn.style.marginLeft = '8px'

	var toolbar = document.querySelector('.b-toolbar .btn-group')
	if (toolbar) {
		toolbar.appendChild(btn)
	}
})
