(function () {
	'use strict';

	var cfg = window.rmmigrateMcp || {};
	var appPassword = '';

	function $(id) {
		return document.getElementById(id);
	}

	function i18n(key) {
		return (cfg.i18n && cfg.i18n[key]) || key;
	}

	function confirmDialog(message, onConfirm) {
		var ui = window.rmmigrateProAdminUI || window.rmmigrateAdminUI;
		if (ui && typeof ui.confirm === 'function') {
			ui.confirm(message, onConfirm);
			return;
		}
		var overlay = document.createElement('div');
		overlay.className = 'mm-confirm-overlay';
		var modal = document.createElement('div');
		modal.className = 'mm-confirm-modal';
		modal.setAttribute('role', 'alertdialog');
		modal.setAttribute('aria-modal', 'true');

		var p = document.createElement('p');
		p.textContent = message;
		modal.appendChild(p);

		var actions = document.createElement('div');
		actions.className = 'mm-confirm-actions';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'button';
		cancelBtn.textContent = (cfg.i18n && cfg.i18n.cancel) || 'Cancel';
		cancelBtn.onclick = function () {
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
		};

		var okBtn = document.createElement('button');
		okBtn.type = 'button';
		okBtn.className = 'button button-primary mm-btn-teal';
		okBtn.textContent = (cfg.i18n && cfg.i18n.confirm) || 'Confirm';
		okBtn.onclick = function () {
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
			if (typeof onConfirm === 'function') {
				onConfirm();
			}
		};

		actions.appendChild(cancelBtn);
		actions.appendChild(okBtn);
		modal.appendChild(actions);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
	}

	function setStepState(step, state, label) {
		var el = document.querySelector('.mm-mcp-step-state[data-step="' + step + '"]');
		if (!el) {
			return;
		}
		var status =
			state === 'done' ? 'ok' : state === 'blocked' ? 'error' : 'warn';
		el.className = 'mm-mcp-step-state mm-status-pill mm-status-' + status;
		el.textContent = label;

		var row = el.closest('.mm-mcp-step');
		if (row) {
			row.classList.remove('is-done', 'is-todo', 'is-blocked');
			row.classList.add('is-' + state);
		}
	}

	function rest(path, method, body) {
		var opts = {
			method: method || 'GET',
			headers: {
				'X-WP-Nonce': cfg.restNonce || '',
				Accept: 'application/json',
			},
			credentials: 'same-origin',
		};
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch((cfg.restBase || '') + path, opts).then(function (r) {
			return r.json().then(function (data) {
				return { ok: r.ok, status: r.status, data: data };
			});
		});
	}

	function stdioSnippet(password) {
		var pw = password || '{application-password}';
		return JSON.stringify(
			{
				mcpServers: {
					'multisite-migrate-site': {
						command: 'npx',
						args: ['-y', '@automattic/mcp-wordpress-remote'],
						env: {
							WP_API_URL: cfg.mcpEndpoint,
							WP_API_USERNAME: cfg.userLogin,
							WP_API_PASSWORD: pw,
						},
					},
				},
			},
			null,
			2
		);
	}

	function basicHeader(password) {
		try {
			var pw = (password || '').replace(/\s+/g, '');
			return btoa(cfg.userLogin + ':' + (pw || '{application-password}'));
		} catch (e) {
			return '{base64(user:app-password)}';
		}
	}

	function refreshSnippets() {
		var stdio = stdioSnippet(appPassword);
		var ids = ['mm-mcp-snip-stdio', 'mm-mcp-snip-cursor', 'mm-mcp-snip-vscode', 'mm-mcp-snip-gemini', 'mm-mcp-snip-windsurf'];
		ids.forEach(function (id) {
			var el = $(id);
			if (el) {
				el.textContent = stdio;
			}
		});
		var claude = $('mm-mcp-snip-claude-code');
		if (claude) {
			claude.textContent =
				'claude mcp add multisite-migrate-site \\\n' +
				'  --transport http "' +
				(cfg.mcpEndpoint || '') +
				'" \\\n' +
				'  --header "Authorization: Basic ' +
				basicHeader(appPassword) +
				'"';
		}
	}

	function updateStepper() {
		var done = 0;
		var total = 4;

		if (cfg.abilitiesApiAvailable) {
			setStepState(1, 'done', 'OK');
			done++;
			var d1 = $('mm-mcp-step-1-desc');
			if (d1) {
				d1.textContent =
					(cfg.registeredAbilities || []).length +
					' Multisite Migrate abilities registered.';
			}
		} else {
			setStepState(1, 'blocked', 'WP 6.9+');
			var d1b = $('mm-mcp-step-1-desc');
			if (d1b) {
				d1b.textContent = i18n('wp69');
			}
		}

		if (cfg.mcpAdapterActive) {
			setStepState(2, 'done', 'Active');
			done++;
		} else if (cfg.mcpAdapterInstalled) {
			setStepState(2, 'todo', 'Inactive');
		} else {
			setStepState(2, 'todo', 'Install');
		}

		var installBtn = $('mm-mcp-install-adapter');
		if (installBtn) {
			if (cfg.mcpAdapterActive) {
				installBtn.disabled = true;
				installBtn.textContent = i18n('adapterActive') || 'Active';
				installBtn.classList.add('button-disabled');
			} else if (cfg.mcpAdapterInstalled) {
				installBtn.disabled = false;
				installBtn.textContent = i18n('activateAdapter') || 'Activate MCP Adapter';
				installBtn.classList.remove('button-disabled');
			} else {
				installBtn.disabled = false;
				installBtn.textContent = i18n('installAdapter') || 'Install MCP Adapter';
				installBtn.classList.remove('button-disabled');
			}
		}

		var appDesc = $('mm-mcp-app-pw-desc');
		var appHelp = $('mm-mcp-app-pw-help');
		if (!cfg.applicationPasswordsAvailable) {
			setStepState(3, 'blocked', 'Disabled');
			if (appDesc) {
				if (!cfg.isSsl) {
					appDesc.textContent = i18n('appPwNoSsl');
				} else if (!cfg.applicationPasswordsSiteOk) {
					appDesc.textContent = i18n('appPwSiteOff');
				} else {
					appDesc.textContent = i18n('appPwUserOff');
				}
			}
			if (appHelp) {
				appHelp.hidden = false;
			}
			var genBtn = $('mm-mcp-generate-app-pw');
			if (genBtn) {
				genBtn.disabled = true;
			}
		} else if (appPassword || cfg.currentUserHasMcpAppPassword) {
			setStepState(3, 'done', appPassword ? 'Generated' : 'Exists');
			done++;
			if (appHelp) {
				appHelp.hidden = true;
			}
			if (appDesc) {
				appDesc.textContent = appPassword
					? 'Copy the password below into your client config.'
					: i18n('appPwExistsHint');
			}
		} else {
			setStepState(3, 'todo', 'Needed');
			if (appHelp) {
				appHelp.hidden = true;
			}
			if (appDesc) {
				appDesc.textContent =
					'Create an Application Password for this WordPress user, then paste it into your AI client snippet below.';
			}
		}

		var genBtnLabel = $('mm-mcp-generate-app-pw');
		if (genBtnLabel && cfg.applicationPasswordsAvailable) {
			genBtnLabel.textContent = cfg.currentUserHasMcpAppPassword
				? i18n('appPwGenerateAnother')
				: i18n('appPwGenerate');
		}

		renderAppPasswords(cfg.applicationPasswords || []);

		var testResult = $('mm-mcp-test-result');
		if (testResult && testResult.classList.contains('is-ok')) {
			setStepState(4, 'done', 'OK');
			done++;
		} else {
			setStepState(4, 'todo', 'Test');
		}

		var status = $('mm-mcp-stepper-status');
		if (status) {
			status.textContent = done + ' of ' + total + ' steps complete';
		}
	}

	function isProAbility(a) {
		if (a && a.tier === 'pro') {
			return true;
		}
		var ids = cfg.proAbilityIds || [];
		return ids.indexOf(a && a.id ? a.id : '') !== -1;
	}

	function appendAbilityRow(list, a) {
		var li = document.createElement('li');
		if (isProAbility(a)) {
			li.className = 'mm-mcp-ability--pro';
		}
		var main = document.createElement('div');
		main.className = 'mm-mcp-ability__main';
		var span = document.createElement('span');
		span.className = 'mm-mcp-ability__label';
		span.textContent = a.label || '';
		main.appendChild(span);
		if (isProAbility(a)) {
			var badge = document.createElement('span');
			badge.className = 'mm-mcp-ability-badge';
			badge.textContent = (cfg.i18n && cfg.i18n.proBadge) || 'Pro';
			main.appendChild(badge);
		}
		li.appendChild(main);
		if (a.description) {
			var desc = document.createElement('p');
			desc.className = 'mm-mcp-ability__desc description';
			desc.textContent = a.description;
			li.appendChild(desc);
		}
		list.appendChild(li);
	}

	function appendGroupHeading(list, label, count) {
		var li = document.createElement('li');
		li.className = 'mm-mcp-abilities__heading';
		li.setAttribute('role', 'presentation');
		var text = document.createElement('span');
		text.className = 'mm-mcp-abilities__heading-text';
		text.textContent = label;
		li.appendChild(text);
		var line = document.createElement('span');
		line.className = 'mm-mcp-abilities__heading-line';
		li.appendChild(line);
		if (typeof count === 'number') {
			var badge = document.createElement('span');
			badge.className = 'mm-mcp-abilities__heading-count';
			badge.textContent = String(count);
			li.appendChild(badge);
		}
		list.appendChild(li);
	}

	function renderAbilities() {
		var list = $('mm-mcp-abilities-list');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		var abilities = cfg.registeredAbilities || [];
		if (cfg.isPro) {
			var core = [];
			var pro = [];
			abilities.forEach(function (a) {
				(isProAbility(a) ? pro : core).push(a);
			});
			if (core.length) {
				appendGroupHeading(list, (cfg.i18n && cfg.i18n.coreHeading) || 'Core', core.length);
				core.forEach(function (a) {
					appendAbilityRow(list, a);
				});
			}
			if (pro.length) {
				appendGroupHeading(list, (cfg.i18n && cfg.i18n.proHeading) || 'Pro', pro.length);
				pro.forEach(function (a) {
					appendAbilityRow(list, a);
				});
			}
		} else {
			abilities.forEach(function (a) {
				appendAbilityRow(list, a);
			});
		}
		var upsell = $('mm-mcp-pro-upsell');
		if (upsell && cfg.proUpsellUrl) {
			upsell.href = cfg.proUpsellUrl;
			upsell.target = '_blank';
			upsell.rel = 'noopener noreferrer';
		} else if (upsell && !cfg.proUpsellUrl) {
			upsell.hidden = true;
		}
	}

	function bindTabs() {
		var tabs = document.querySelectorAll('#mm-mcp-app .mm-nav-tab[data-tab]');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var name = tab.getAttribute('data-tab');
				tabs.forEach(function (t) {
					var on = t === tab;
					t.classList.toggle('is-active', on);
					t.setAttribute('aria-selected', on ? 'true' : 'false');
				});
				document.querySelectorAll('.mm-mcp-tab-panel').forEach(function (panel) {
					var match = panel.getAttribute('data-panel') === name;
					panel.classList.toggle('is-active', match);
					panel.hidden = !match;
				});
			});
		});
	}

	function bindCopy() {
		document.querySelectorAll('.mm-mcp-copy').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-copy');
				var el = $(id);
				if (!el) {
					return;
				}
				navigator.clipboard.writeText(el.textContent || '').then(function () {
					btn.textContent = i18n('copied');
					setTimeout(function () {
						btn.textContent = i18n('copy');
					}, 1500);
				});
			});
		});
	}

	function bindActions() {
		var installBtn = $('mm-mcp-install-adapter');
		if (installBtn) {
			installBtn.addEventListener('click', function () {
				if (cfg.mcpAdapterActive || installBtn.disabled) {
					return;
				}
				var err = $('mm-mcp-install-error');
				if (err) {
					err.textContent = '';
					err.hidden = true;
				}
				installBtn.disabled = true;
				installBtn.textContent = i18n('installing');
				rest('/ai-agents/install-mcp-adapter', 'POST')
					.then(function (res) {
						if (res.ok && res.data && res.data.success) {
							cfg.mcpAdapterActive = true;
							cfg.mcpAdapterInstalled = true;
							updateStepper();
							return;
						}
						installBtn.disabled = false;
						installBtn.textContent = cfg.mcpAdapterInstalled
							? i18n('activateAdapter') || 'Activate MCP Adapter'
							: i18n('installAdapter') || 'Install MCP Adapter';
						if (err) {
							err.hidden = false;
							err.textContent =
								(res.data && res.data.message) || i18n('testFail') || 'Failed';
						}
					})
					.catch(function () {
						installBtn.disabled = false;
						installBtn.textContent = i18n('installAdapter') || 'Install MCP Adapter';
						if (err) {
							err.hidden = false;
							err.textContent = i18n('testFail') || 'Failed';
						}
					});
			});
		}

		var genPw = $('mm-mcp-generate-app-pw');
		if (genPw) {
			genPw.addEventListener('click', function () {
				function executeGenerate() {
					genPw.disabled = true;
					genPw.textContent = i18n('generating');
					rest('/ai-agents/generate-app-password', 'POST')
						.then(function (res) {
							if (!res.ok || !res.data || !res.data.success) {
								var msg = (res.data && res.data.message) || 'Failed';
								var displayErr = $('mm-mcp-app-pw-desc');
								if (displayErr) {
									displayErr.textContent = msg;
								}
								return;
							}
							appPassword = res.data.password || '';
							if (res.data.username) {
								cfg.userLogin = res.data.username;
							}
							cfg.currentUserHasMcpAppPassword = true;
							if (Array.isArray(res.data.passwords)) {
								cfg.applicationPasswords = res.data.passwords;
							}
							var display = $('mm-mcp-app-pw-display');
							if (display) {
								display.hidden = false;
								display.textContent = appPassword;
							}
							refreshSnippets();
							updateStepper();
						})
						.finally(function () {
							genPw.disabled = false;
							genPw.textContent = cfg.currentUserHasMcpAppPassword
								? i18n('appPwGenerateAnother')
								: i18n('appPwGenerate');
						});
				}

				if (cfg.currentUserHasMcpAppPassword) {
					confirmDialog(i18n('appPwGenerateConfirm'), executeGenerate);
				} else {
					executeGenerate();
				}
			});
		}

		var testBtn = $('mm-mcp-test-connection');
		if (testBtn) {
			testBtn.addEventListener('click', function () {
				var result = $('mm-mcp-test-result');
				if (result) {
					result.className = 'mm-mcp-test-result';
					result.textContent = i18n('testing');
				}
				testBtn.disabled = true;

				var useBasic = !!appPassword;
				var fetchOpts = {
					method: 'GET',
					headers: { Accept: 'application/json' },
				};
				if (useBasic) {
					fetchOpts.credentials = 'omit';
					fetchOpts.headers.Authorization = 'Basic ' + basicHeader(appPassword);
				} else {
					fetchOpts.credentials = 'same-origin';
					fetchOpts.headers['X-WP-Nonce'] = cfg.restNonce || '';
				}

				fetch(cfg.abilitiesEndpoint || '', fetchOpts)
					.then(function (r) {
						return r.json().then(
							function (data) {
								return { ok: r.ok, status: r.status, data: data };
							},
							function () {
								return { ok: false, status: r.status, data: null };
							}
						);
					})
					.then(function (res) {
						if (!res.ok) {
							if (result) {
								result.className = 'mm-mcp-test-result is-fail';
								var msg = i18n('testFail');
								if (res.status === 401 || res.status === 403) {
									msg += ' — ' + (useBasic ? i18n('testAuthFail') : i18n('testPermFail'));
								} else if (res.status === 404) {
									msg += ' — ' + i18n('testNotFound');
								} else if (res.data && res.data.message) {
									msg += ' — ' + res.data.message;
								}
								result.textContent = msg;
							}
							return;
						}
						var items = [];
						if (Array.isArray(res.data)) {
							items = res.data;
						} else if (res.data && typeof res.data === 'object') {
							var raw = res.data.abilities || res.data.data || res.data.items || res.data;
							if (Array.isArray(raw)) {
								items = raw;
							} else if (raw && typeof raw === 'object') {
								Object.keys(raw).forEach(function (key) {
									var val = raw[key];
									if (val && typeof val === 'object') {
										if (!val.name && !val.id) {
											val.id = key;
										}
										items.push(val);
									} else {
										items.push({ id: key, value: val });
									}
								});
							}
						}
						var mm = items.filter(function (item) {
							if (!item) return false;
							if (typeof item === 'string') {
								return item.indexOf('multisite-migrate') === 0;
							}
							var name = String(item.name || item.id || item.ability || item.slug || '');
							var category = String(item.category || '');
							if (name.indexOf('multisite-migrate') === 0 || category.indexOf('multisite-migrate') === 0) {
								return true;
							}
							try {
								return JSON.stringify(item).indexOf('multisite-migrate') !== -1;
							} catch (e) {
								return false;
							}
						});
						var count = mm.length;
						if (count === 0 && (cfg.registeredAbilities || []).length > 0) {
							count = cfg.registeredAbilities.length;
						}
						if (result) {
							if (count > 0) {
								result.className = 'mm-mcp-test-result is-ok';
								result.textContent =
									i18n('testOk') + ' — ' + count + ' abilities.';
								if (!useBasic) {
									result.textContent += ' ' + i18n('testCookieHint');
								}
							} else {
								result.className = 'mm-mcp-test-result is-fail';
								result.textContent = i18n('testNoAbilities');
							}
						}
						updateStepper();
					})
					.catch(function () {
						if (result) {
							result.className = 'mm-mcp-test-result is-fail';
							result.textContent = i18n('testFail') + ' — ' + i18n('testNetworkFail');
						}
					})
					.finally(function () {
						testBtn.disabled = false;
					});
			});
		}

		var oauthBtn = $('mm-mcp-generate-oauth');
		if (oauthBtn) {
			oauthBtn.addEventListener('click', function () {
				oauthBtn.disabled = true;
				oauthBtn.textContent = i18n('generating');
				rest('/ai-agents/oauth/generate-client', 'POST', { name: 'ChatGPT MCP' })
					.then(function (res) {
						var msg = $('mm-mcp-oauth-msg');
						if (!res.ok || !res.data || !res.data.success) {
							if (msg) {
								msg.textContent = (res.data && res.data.message) || 'Failed';
							}
							return;
						}
						var fields = $('mm-mcp-oauth-fields');
						if (fields) {
							fields.hidden = false;
						}
						function setVal(id, val) {
							var el = $(id);
							if (el) {
								el.value = val || '';
							}
						}
						setVal('mm-mcp-oauth-mcp-url', cfg.mcpEndpoint);
						setVal('mm-mcp-oauth-client-id', res.data.client_id);
						setVal('mm-mcp-oauth-client-secret', res.data.client_secret);
						setVal('mm-mcp-oauth-auth-url', res.data.authorization_endpoint || cfg.oauthAuthorizationEndpoint);
						setVal('mm-mcp-oauth-token-url', res.data.token_endpoint || cfg.oauthTokenEndpoint);
						setVal('mm-mcp-oauth-discovery', res.data.discovery_url || cfg.oauthDiscoveryUrl);
						if (msg) {
							msg.textContent = res.data.message || '';
						}
						loadOauthClients();
					})
					.finally(function () {
						oauthBtn.disabled = false;
						oauthBtn.textContent = 'Generate ChatGPT OAuth credentials';
					});
			});
		}

		var refreshClients = $('mm-mcp-refresh-oauth-clients');
		if (refreshClients) {
			refreshClients.addEventListener('click', loadOauthClients);
		}
	}

	function loadOauthClients() {
		var list = $('mm-mcp-oauth-client-list');
		if (!list) {
			return;
		}
		rest('/ai-agents/oauth/clients', 'GET').then(function (res) {
			list.innerHTML = '';
			var clients = (res.data && res.data.clients) || [];
			if (!clients.length) {
				var empty = document.createElement('li');
				empty.textContent = 'No OAuth clients yet.';
				list.appendChild(empty);
				return;
			}
			clients.forEach(function (c) {
				var li = document.createElement('li');
				li.className = 'mm-mcp-oauth-client';

				var meta = document.createElement('div');
				meta.className = 'mm-mcp-oauth-client__meta';
				var code = document.createElement('code');
				code.textContent = c.client_id || '';
				var name = document.createElement('span');
				name.className = 'mm-mcp-oauth-client__name';
				name.textContent = c.name || '';
				meta.appendChild(code);
				meta.appendChild(name);

				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'button button-small mm-mcp-revoke';
				btn.textContent = 'Revoke';
				btn.addEventListener('click', function () {
					confirmDialog(i18n('oauthRevokeConfirm') || 'Revoke this OAuth client and its tokens?', function () {
						rest('/ai-agents/oauth/clients/' + encodeURIComponent(c.client_id), 'DELETE').then(function () {
							loadOauthClients();
						});
					});
				});

				li.appendChild(meta);
				li.appendChild(btn);
				list.appendChild(li);
			});
		});
	}

	function renderAppPasswords(passwords) {
		var list = $('mm-mcp-app-pw-list');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		var items = Array.isArray(passwords) ? passwords : [];
		if (!items.length) {
			var empty = document.createElement('li');
			empty.className = 'mm-mcp-app-pw-empty';
			empty.textContent = i18n('appPwNone');
			list.appendChild(empty);
			return;
		}
		items.forEach(function (p) {
			var li = document.createElement('li');
			li.className = 'mm-mcp-app-pw' + (p.is_mcp ? ' is-mcp' : '');

			var meta = document.createElement('div');
			meta.className = 'mm-mcp-app-pw__meta';

			var title = document.createElement('div');
			title.className = 'mm-mcp-app-pw__title';
			var name = document.createElement('span');
			name.className = 'mm-mcp-app-pw__name';
			name.textContent = p.name || p.uuid || '';
			title.appendChild(name);
			if (p.is_mcp) {
				var badge = document.createElement('span');
				badge.className = 'mm-mcp-app-pw__badge';
				badge.textContent = i18n('appPwMcpBadge');
				title.appendChild(badge);
			}
			meta.appendChild(title);

			var dates = document.createElement('div');
			dates.className = 'mm-mcp-app-pw__dates';
			dates.textContent =
				i18n('appPwCreated') +
				': ' +
				(p.created || '—') +
				' · ' +
				i18n('appPwLastUsed') +
				': ' +
				(p.last_used || i18n('appPwNever'));
			meta.appendChild(dates);

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button button-small mm-mcp-revoke';
			btn.textContent = i18n('appPwRevoke');
			btn.addEventListener('click', function () {
				confirmDialog(i18n('appPwRevokeConfirm'), function () {
					rest('/ai-agents/app-passwords/' + encodeURIComponent(p.uuid), 'DELETE').then(function (res) {
						if (res.data && Array.isArray(res.data.passwords)) {
							cfg.applicationPasswords = res.data.passwords;
						} else {
							cfg.applicationPasswords = (cfg.applicationPasswords || []).filter(function (row) {
								return row.uuid !== p.uuid;
							});
						}
						cfg.currentUserHasMcpAppPassword = (cfg.applicationPasswords || []).some(function (row) {
							return !!row.is_mcp;
						});
						updateStepper();
					});
				});
			});

			li.appendChild(meta);
			li.appendChild(btn);
			list.appendChild(li);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		refreshSnippets();
		renderAbilities();
		updateStepper();
		bindTabs();
		bindCopy();
		bindActions();
		loadOauthClients();
	});
})();
