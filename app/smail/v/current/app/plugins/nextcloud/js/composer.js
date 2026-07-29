(rl => {
//	if (rl.settings.get('Nextcloud'))

	addEventListener('x2m-view-model.create', e => {
		if ('PopupsCompose' === e.detail.viewModelTemplateID) {
			let view = e.detail;

			// Patch Squire editor after it initializes: fix cursor jump on Enter.
			if (view.oEditor && view.oEditor.onReady) {
				view.oEditor.onReady(() => {
					try {
						const squireUI = view.oEditor.editor;
						const squire = squireUI?.squire;
						if (!squire) return;

						// Squire intercepts Enter/Shift+Enter via `_beforeInput`.
						// Override the handler to let the browser manage the cursor
						// natively — Squire's manual splitBlock causes the cursor
						// to jump back to the previous line on the next keystroke.
						const origHandler = squire._beforeInput.bind(squire);
						squire._beforeInput = function(event) {
							if (event.inputType === 'insertLineBreak'
								|| event.inputType === 'insertParagraph') {
								return; // let the browser handle Enter natively
							}
							return origHandler(event);
						};
					} catch (e) { /* silent */ }
				});
			}

			view.nextcloudAttach = () => {
				rl.nextcloud.selectFiles().then(files => {
					let urls = [];
					files && files.forEach(file => {
						if (file.name) {
							let attachment = view.addAttachmentHelper(
									Jua?.randomId(),
									file.name.replace(/^.*\/([^/]+)$/, '$1'),
									file.size
								);

							rl.pluginRemoteRequest(
								(iError, data) => {
									attachment.uploading(false).complete(true);
									if (iError) {
										attachment.error(data?.Result?.error || 'failed');
									} else {
										attachment.tempName(data.Result.tempName);
									}
								},
								'NextcloudAttachFile',
								{
									'file': file.name
								}
							);
						} else if (file.url) {
							urls.push(file.url);
						}
					});
					if (urls.length) {
						// TODO: other editors and text/plain
						view.oEditor.editor.squire.insertHTML(urls.join("<br>"));
					}
				});
			};
		}
	});

	let template = document.getElementById('PopupsCompose');
	const uploadBtn = template.content.querySelector('#composeUploadButton');
	if (uploadBtn) {
		uploadBtn.after(Element.fromHTML(`<a class="btn fontastic" data-bind="click: nextcloudAttach"
			data-i18n="[title]NEXTCLOUD/ATTACH_FILES">◦◯◦</a>`));
	}

/**
	https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html
	POST /ocs/v2.php/apps/files_sharing/api/v1/shares
	JSON {
		"path":"/Nextcloud intro.mp4",
		"shareType":3, // public link
		"shareWith":"user@example.com",
//		"publicUpload":false,
//		"password":null,
//		"permissions":1, // default
//		"expireDate":"YYYY-MM-DD",
//		"note":"Especially for you"
	}
*/

})(window.rl);
