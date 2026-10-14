/*
 * fpp-wled-effects page script. FPP loads every file in the plugin's js/
 * directory on all of the plugin's pages, so this only does anything when the
 * main page (#wledApp) is present.
 */
(function () {
	'use strict';

	var API = 'api/plugin/fpp-wled-effects/';

	var state = {
		allDevices: 'All WLED Devices',
		commands: {}, // action -> FPP command name
		devices: [],
		clockSkew: 0, // browser time minus FPP time, in seconds
		details: {}, // device id -> cached lists
		pendingEntry: null // {command, args, label} for the playlist modal
	};

	function esc (s) {
		return String(s === null || s === undefined ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function toast (msg, ok) {
		if ($.jGrowl) {
			$.jGrowl(esc(msg), { themeState: ok === false ? 'danger' : 'success' });
		} else if (ok === false) {
			alert(msg);
		}
	}

	function api (method, path, body) {
		var opts = { method: method, headers: { Accept: 'application/json' } };
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(path.indexOf('api/') === 0 ? path : API + path, opts).then(function (r) {
			return r.text().then(function (text) {
				var data = null;
				try {
					data = text ? JSON.parse(text) : null;
				} catch (e) {
					data = null;
				}
				if (!r.ok) {
					var msg = data && data.message ? data.message : 'Request failed (HTTP ' + r.status + ')';
					var err = new Error(msg);
					err.status = r.status;
					throw err;
				}
				return data;
			});
		});
	}

	function busy (btn, on) {
		var $b = $(btn);
		if (on) {
			$b.prop('disabled', true).data('wledHtml', $b.html());
			$b.html("<span class='spinner-border spinner-border-sm' aria-hidden='true'></span> " + $b.text());
		} else {
			$b.prop('disabled', false);
			if ($b.data('wledHtml') !== undefined) $b.html($b.data('wledHtml'));
		}
	}

	function timeAgo (ts) {
		if (!ts) return 'never';
		var s = Math.max(0, Math.round(Date.now() / 1000 - state.clockSkew - ts));
		if (s < 60) return 'just now';
		if (s < 3600) return Math.round(s / 60) + ' min ago';
		if (s < 86400) return Math.round(s / 3600) + ' h ago';
		return Math.round(s / 86400) + ' d ago';
	}

	/* ---------------- Devices ---------------- */

	function applyDeviceData (data) {
		state.devices = data.devices || [];
		if (data.now) state.clockSkew = Date.now() / 1000 - data.now;
		state.allDevices = data.allDevices || state.allDevices;
		var cmds = data.commands || {};
		Object.keys(cmds).forEach(function (action) {
			state.commands[action] = cmds[action];
		});
		$('#wledAutoImport').prop('checked', !!data.autoImport);
		renderDevices();
		renderTargets();
		return loadDetails();
	}

	function renderDevices () {
		var $tb = $('#wledDeviceTable tbody').empty();
		var $empty = $('#wledEmpty');
		if (!state.devices.length) {
			$tb.append("<tr><td colspan='8' class='text-body-secondary'>No WLED devices yet.</td></tr>");
			$empty
				.html(
					"<i class='fas fa-info-circle'></i> No WLED devices are set up yet. Use <strong>Find WLED Devices</strong> to pick up the WLED controllers FPP already knows about, or <strong>Add Device</strong> to enter one by address."
				)
				.removeClass('d-none');
			$('#wledControl').addClass('d-none');
			return;
		}
		$empty.addClass('d-none');
		$('#wledControl').removeClass('d-none');

		state.devices.forEach(function (d) {
			var status;
			if (d.enabled === false) {
				status = "<span class='badge text-bg-secondary'>Disabled</span>";
			} else if (d.online === true) {
				status = "<span class='badge text-bg-success'>Online</span>";
				if (d.live) status += " <span class='badge text-bg-info' title='Receiving live pixel data'>Live data</span>";
				if (d.on === false) status += " <span class='badge text-bg-secondary'>Off</span>";
			} else if (d.online === false) {
				status = "<span class='badge text-bg-danger' title='" + esc(d.error || '') + "'>Offline</span>";
			} else {
				status = "<span class='badge text-bg-secondary'>Unknown</span>";
			}
			if (d.online === true && d.error) {
				status += " <i class='fas fa-exclamation-triangle text-warning' title='" + esc(d.error) + "'></i>";
			}
			var ver = d.info ? esc(d.info.ver) + (d.info.leds ? ' &middot; ' + d.info.leds + ' LEDs' : '') : '';
			var c = d.counts || {};
			var row =
				"<tr data-id='" + esc(d.id) + "'>" +
				"<td><span class='fw-semibold text-nowrap'>" + esc(d.name) + '</span>' +
				(d.source === 'fpp' ? " <span class='badge text-bg-light border' title='Added from FPP discovery'>FPP</span>" : '') +
				"<div class='d-sm-none small'><a href='http://" + esc(d.host) + "/' target='_blank' rel='noopener'>" + esc(d.host) + '</a></div>' +
				'</td>' +
				"<td class='d-none d-sm-table-cell'><a href='http://" + esc(d.host) + "/' target='_blank' rel='noopener' title='Open WLED UI'>" + esc(d.host) + " <i class='fas fa-external-link-alt small'></i></a></td>" +
				'<td>' + status + "<div class='small text-body-secondary'>checked " + esc(timeAgo(d.checked)) + '</div></td>' +
				"<td class='d-none d-lg-table-cell'>" + ver + '</td>' +
				"<td class='d-none d-md-table-cell'>" + (c.effects || 0) + '</td>' +
				"<td class='d-none d-md-table-cell'>" + (c.presets || 0) + '</td>' +
				"<td class='d-none d-md-table-cell'>" + (c.playlists || 0) + '</td>' +
				"<td class='text-end text-nowrap'>" +
				"<div class='btn-group btn-group-sm' role='group' aria-label='Device actions'>" +
				"<button type='button' class='btn btn-outline-secondary' data-act='refresh' title='Re-read effects and presets'><i class='fas fa-sync-alt'></i><span class='visually-hidden'>Refresh</span></button>" +
				"<button type='button' class='btn btn-outline-secondary' data-act='rename' title='Rename'><i class='fas fa-pen'></i><span class='visually-hidden'>Rename</span></button>" +
				"<button type='button' class='btn btn-outline-secondary' data-act='toggle' title='" + (d.enabled === false ? 'Enable' : 'Disable') + "'><i class='fas " + (d.enabled === false ? 'fa-toggle-off' : 'fa-toggle-on') + "'></i><span class='visually-hidden'>" + (d.enabled === false ? 'Enable' : 'Disable') + '</span></button>' +
				"<button type='button' class='btn btn-outline-danger' data-act='remove' title='Remove'><i class='fas fa-trash'></i><span class='visually-hidden'>Remove</span></button>" +
				'</div></td></tr>';
			$tb.append(row);
		});
	}

	function renderTargets () {
		var $sel = $('#wledTarget');
		var cur = $sel.val();
		$sel.empty();
		$sel.append($('<option>').val(state.allDevices).text(state.allDevices));
		state.devices.forEach(function (d) {
			if (d.enabled === false) return;
			$sel.append($('<option>').val(d.name).text(d.name));
		});
		if (cur && $sel.find('option').filter(function () { return this.value === cur; }).length) {
			$sel.val(cur);
		} else if (state.devices.length === 1) {
			$sel.val(state.devices[0].name);
		}
	}

	function loadDetails () {
		var jobs = state.devices.map(function (d) {
			return api('GET', 'devices/' + encodeURIComponent(d.id) + '/details')
				.then(function (r) {
					state.details[d.id] = r.cache || {};
				})
				.catch(function () {
					state.details[d.id] = {};
				});
		});
		return Promise.all(jobs).then(renderControls);
	}

	function findDevice (id) {
		for (var i = 0; i < state.devices.length; i++) {
			if (state.devices[i].id === id) return state.devices[i];
		}
		return null;
	}

	// Devices the Control card currently targets.
	function targetDevices () {
		var t = $('#wledTarget').val();
		return state.devices.filter(function (d) {
			if (d.enabled === false) return false;
			return t === state.allDevices || d.name === t;
		});
	}

	// {name: [device names]} for presets/playlists/effects/palettes.
	function collect (field) {
		var out = {};
		targetDevices().forEach(function (d) {
			var lists = state.details[d.id] || {};
			var items = lists[field] || {};
			Object.keys(items).forEach(function (k) {
				var name = items[k];
				(out[name] = out[name] || []).push(d.name);
			});
		});
		return out;
	}

	function sortNames (names, first) {
		names.sort(function (a, b) {
			return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
		});
		(first || []).slice().reverse().forEach(function (f) {
			var i = names.indexOf(f);
			if (i > 0) {
				names.splice(i, 1);
				names.unshift(f);
			}
		});
		return names;
	}

	/* ---------------- Control card ---------------- */

	function renderControls () {
		renderNamedList('presets', '#wledPresetList', '#wledPresetFilter', 'preset');
		renderNamedList('playlists', '#wledPlaylistList', '#wledPlaylistFilter', 'playlist');
		renderEffectOptions();
	}

	function renderNamedList (field, listSel, filterSel, action) {
		var $list = $(listSel).empty();
		var byName = collect(field);
		var names = sortNames(Object.keys(byName));
		var many = targetDevices().length > 1;
		var noun = action === 'playlist' ? 'playlists' : 'presets';
		if (!names.length) {
			$list.append(
				"<div class='list-group-item text-body-secondary'>No " + noun + ' found. Save ' + noun +
					' in the WLED UI, then use <strong>Refresh</strong>.</div>'
			);
			return;
		}
		var filter = ($(filterSel).val() || '').toLowerCase();
		names.forEach(function (name) {
			if (filter && name.toLowerCase().indexOf(filter) === -1) return;
			var devs = byName[name];
			var badges = many
				? devs.map(function (n) {
						return "<span class='badge text-bg-secondary me-1'>" + esc(n) + '</span>';
					}).join('')
				: '';
			$list.append(
				"<div class='list-group-item d-flex flex-wrap align-items-center gap-2' data-name='" + esc(name) + "'>" +
					"<div class='me-auto text-break'><div class='fw-semibold'>" + esc(name) + '</div>' + badges + '</div>' +
					"<div class='d-flex flex-wrap gap-2'>" +
					"<button type='button' class='buttons btn-success btn-sm' data-run='" + action + "'><i class='fas fa-play'></i> " +
					(action === 'playlist' ? 'Start' : 'Apply') + '</button>' +
					"<button type='button' class='buttons btn-outline-primary btn-sm' data-add='" + action + "'><i class='fas fa-list'></i> Add to FPP Playlist</button>" +
					'</div></div>'
			);
		});
	}

	function renderEffectOptions () {
		var $fx = $('#wledFx');
		var $pal = $('#wledPal');
		var curFx = $fx.val();
		var curPal = $pal.val();
		var fx = sortNames(Object.keys(collect('effects')), ['Solid']);
		var pal = sortNames(Object.keys(collect('palettes')), ['Default']);
		$fx.empty();
		fx.forEach(function (n) {
			$fx.append($('<option>').val(n).text(n));
		});
		$pal.empty().append($('<option>').val('').text('(keep current)'));
		pal.forEach(function (n) {
			$pal.append($('<option>').val(n).text(n));
		});
		if (curFx && fx.indexOf(curFx) !== -1) $fx.val(curFx);
		if (curPal !== undefined && (curPal === '' || pal.indexOf(curPal) !== -1)) $pal.val(curPal);
	}

	/* ---------------- Commands ---------------- */

	function commandName (action) {
		return state.commands[action];
	}

	// Positional args exactly as FPP passes them to the command scripts.
	function entryFor (action, value) {
		var dev = $('#wledTarget').val() || state.allDevices;
		switch (action) {
			case 'preset':
			case 'playlist':
				return { args: [dev, value, 'false'], label: (action === 'preset' ? 'Preset ' : 'Playlist ') + value };
			case 'effect':
				return {
					args: [
						dev,
						$('#wledFx').val() || '',
						$('#wledPal').val() || '',
						$('#wledCol1').val(),
						$('#wledCol2').val(),
						$('#wledCol3').val(),
						String($('#wledSx').val()),
						String($('#wledIx').val()),
						String($('#wledFxBri').val()),
						$('#wledSeg').val(),
						$('#wledFxTakeover').is(':checked') ? 'true' : 'false'
					],
					label: 'Effect ' + ($('#wledFx').val() || '')
				};
			case 'power':
				return { args: [dev, value], label: 'Power ' + value };
			case 'brightness':
				return { args: [dev, String($('#wledBri').val())], label: 'Brightness ' + $('#wledBri').val() };
			case 'override':
				return { args: [dev, $('#wledLor').val()], label: 'Live override: ' + $('#wledLor').val() };
		}
		return null;
	}

	function runNow (action, value, btn) {
		var e = entryFor(action, value);
		var cmd = commandName(action);
		if (!e || !cmd) return;
		busy(btn, true);
		api('POST', 'run', { command: cmd, args: e.args })
			.then(function (r) {
				var failed = (r.results || []).filter(function (x) { return !x.ok && !x.skipped; });
				if (!failed.length) {
					toast(e.label + ': ' + r.message, true);
				} else {
					toast(
						e.label + ': ' + failed.map(function (x) { return x.device + ' - ' + x.message; }).join('; '),
						r.ok
					);
				}
			})
			.catch(function (err) {
				toast(e.label + ': ' + err.message, false);
			})
			.then(function () {
				busy(btn, false);
			});
	}

	/* ---------------- Add to FPP playlist ---------------- */

	function openPlaylistModal (action, value) {
		var e = entryFor(action, value);
		var cmd = commandName(action);
		if (!e || !cmd) return;
		state.pendingEntry = { command: cmd, args: e.args, label: e.label };
		$('#wledPlEntrySummary').text(cmd + ' — ' + e.args.join(', '));
		$('#wledPlHold').val(action === 'power' || action === 'brightness' || action === 'override' ? 0 : $('#wledPlHold').val());

		var $sel = $('#wledPlName').empty();
		api('GET', 'api/playlists')
			.then(function (names) {
				(names || []).forEach(function (n) {
					$sel.append($('<option>').val(n).text(n));
				});
			})
			.catch(function () {})
			.then(function () {
				$sel.append($('<option>').val('__new__').text('New playlist…'));
				var last = window.localStorage ? localStorage.getItem('fppWledLastPlaylist') : null;
				if (last && $sel.find('option').filter(function () { return this.value === last; }).length) {
					$sel.val(last);
				}
				$sel.trigger('change');
				bootstrap.Modal.getOrCreateInstance(document.getElementById('wledPlaylistModal')).show();
			});
	}

	function playlistEntries (hold) {
		var p = state.pendingEntry;
		var entries = [
			{
				type: 'command',
				enabled: 1,
				playOnce: 0,
				command: p.command,
				args: p.args,
				multisyncCommand: false,
				multisyncHosts: ''
			}
		];
		if (hold > 0) {
			entries.push({ type: 'pause', enabled: 1, playOnce: 0, duration: hold });
		}
		return entries;
	}

	function addToPlaylist (ev) {
		ev.preventDefault();
		var sel = $('#wledPlName').val();
		var isNew = sel === '__new__';
		var name = isNew ? $.trim($('#wledPlNewName').val()) : sel;
		var section = $('#wledPlSection').val();
		var hold = Math.max(0, parseFloat($('#wledPlHold').val()) || 0);
		if (!name) {
			toast('Enter a playlist name', false);
			return;
		}
		if (isNew && typeof window.PlaylistNameOK === 'function' && !window.PlaylistNameOK(name)) {
			return;
		}
		if (isNew && $('#wledPlName option').filter(function () { return this.value === name; }).length) {
			toast('A playlist named "' + name + '" already exists', false);
			return;
		}
		var entries = playlistEntries(hold);
		var btn = $('#wledPlSubmit');
		busy(btn, true);

		var work;
		if (isNew) {
			var pl = {
				name: name,
				version: 3,
				repeat: 0,
				loopCount: 0,
				empty: false,
				desc: 'Created by the WLED Effects plugin',
				random: 0,
				leadIn: [],
				mainPlaylist: [],
				leadOut: [],
				playlistInfo: {
					total_duration: hold,
					total_items: entries.length
				}
			};
			pl[section] = entries;
			work = api('POST', 'api/playlists', pl);
		} else {
			// One entry at a time so they land in order.
			work = entries.reduce(function (p, entry) {
				return p.then(function () {
					return api(
						'POST',
						'api/playlist/' + encodeURIComponent(name) + '/' + encodeURIComponent(section) + '/item',
						entry
					).then(function (r) {
						if (r && r.Status && r.Status !== 'OK') throw new Error(r.Message || 'Could not update playlist');
					});
				});
			}, Promise.resolve());
		}

		work
			.then(function () {
				if (window.localStorage) localStorage.setItem('fppWledLastPlaylist', name);
				bootstrap.Modal.getOrCreateInstance(document.getElementById('wledPlaylistModal')).hide();
				toast('Added "' + state.pendingEntry.label + '" to playlist ' + name, true);
			})
			.catch(function (err) {
				toast('Could not add to playlist: ' + err.message, false);
			})
			.then(function () {
				busy(btn, false);
			});
	}

	/* ---------------- Discovery ---------------- */

	function discover (btn) {
		busy(btn, true);
		var $out = $('#wledDiscoverResults').removeClass('d-none').html(
			"<div class='text-body-secondary'><span class='spinner-border spinner-border-sm' aria-hidden='true'></span> Asking FPP for WLED controllers&hellip;</div>"
		);
		api('GET', 'discover')
			.then(function (r) {
				var list = (r.candidates || []).filter(function (c) { return !c.configured; });
				if (!list.length) {
					$out.html(
						"<div class='alert alert-secondary mb-0'>FPP doesn't know about any WLED controllers that aren't already listed. " +
							'FPP finds them through MultiSync discovery (Status/Control &gt; MultiSync) and the addresses in your channel outputs. ' +
							'You can also add one by address.</div>'
					);
					return;
				}
				var html =
					"<div class='border rounded p-2'><div class='d-flex flex-wrap align-items-center gap-2 mb-2'>" +
					"<strong class='me-auto'>Found " + list.length + ' WLED controller' + (list.length === 1 ? '' : 's') + '</strong>' +
					"<button type='button' class='buttons btn-success btn-sm' data-import='all'><i class='fas fa-plus'></i> Add All</button>" +
					"<button type='button' class='btn-close' aria-label='Close' data-dismiss-discover></button></div>" +
					"<ul class='list-group'>";
				list.forEach(function (c) {
					html +=
						"<li class='list-group-item d-flex flex-wrap align-items-center gap-2'>" +
						"<div class='me-auto'><div class='fw-semibold'>" + esc(c.name) + '</div>' +
						"<div class='small text-body-secondary'>" + esc(c.host) + ' &middot; ' + esc(c.source) +
						(c.info && c.info.ver ? ' &middot; WLED ' + esc(c.info.ver) : '') +
						(c.ignored ? ' &middot; previously removed' : '') +
						(c.existing ? ' &middot; new address for ' + esc(c.existing) : '') + '</div></div>' +
						"<button type='button' class='buttons btn-outline-success btn-sm' data-import='" + esc(c.host) + "' data-name='" + esc(c.name) + "'><i class='fas fa-plus'></i> " + (c.existing ? 'Update' : 'Add') + '</button>' +
						'</li>';
				});
				html += '</ul></div>';
				$out.html(html);
			})
			.catch(function (err) {
				$out.html("<div class='alert alert-danger mb-0'>" + esc(err.message) + '</div>');
			})
			.then(function () {
				busy(btn, false);
			});
	}

	function addDevice (host, name, force) {
		return api('POST', 'devices', { host: host, name: name || '', force: !!force }).then(applyDeviceData);
	}

	function importHosts (items, btn) {
		busy(btn, true);
		var added = 0;
		var chain = items.reduce(function (p, it) {
			return p.then(function () {
				return addDevice(it.host, it.name)
					.then(function () {
						added++;
						$("#wledDiscoverResults [data-import='" + it.host.replace(/'/g, '') + "']").closest('li').remove();
					})
					.catch(function (err) {
						toast(it.host + ': ' + err.message, false);
					});
			});
		}, Promise.resolve());
		chain.then(function () {
			busy(btn, false);
			if (added) toast('Added ' + added + ' WLED device' + (added === 1 ? '' : 's'), true);
			if (!$('#wledDiscoverResults li').length) $('#wledDiscoverResults').addClass('d-none').empty();
		});
	}

	/* ---------------- Wiring ---------------- */

	function reload (sync) {
		return api('GET', 'devices' + (sync ? '?sync=1' : ''))
			.then(function (r) {
				if (r.imported && r.imported.length) {
					toast('Added from FPP: ' + r.imported.join(', '), true);
				}
				return applyDeviceData(r);
			})
			.catch(function (err) {
				toast('Could not load WLED devices: ' + err.message, false);
			});
	}

	function bind () {
		$('#wledAddForm').on('submit', function (ev) {
			ev.preventDefault();
			var host = $.trim($('#wledAddHost').val());
			var name = $.trim($('#wledAddName').val());
			var btn = $('#wledAddBtn');
			busy(btn, true);
			addDevice(host, name, false)
				.catch(function (err) {
					if (err.status === 422 && confirm(err.message + '\n\nAdd it anyway? (It may be powered off.)')) {
						return addDevice(host, name, true);
					}
					throw err;
				})
				.then(function () {
					$('#wledAddHost, #wledAddName').val('');
					bootstrap.Collapse.getOrCreateInstance(document.getElementById('wledAddForm')).hide();
					toast('Added ' + host, true);
				})
				.catch(function (err) {
					toast(err.message, false);
				})
				.then(function () {
					busy(btn, false);
				});
		});

		$('#wledDiscoverBtn').on('click', function () {
			discover(this);
		});

		$('#wledDiscoverResults')
			.on('click', '[data-import]', function () {
				var which = $(this).attr('data-import');
				var items = [];
				if (which === 'all') {
					$('#wledDiscoverResults li [data-import]').each(function () {
						items.push({ host: $(this).attr('data-import'), name: $(this).attr('data-name') });
					});
				} else {
					items.push({ host: which, name: $(this).attr('data-name') });
				}
				importHosts(items, this);
			})
			.on('click', '[data-dismiss-discover]', function () {
				$('#wledDiscoverResults').addClass('d-none').empty();
			});

		$('#wledRefreshAllBtn').on('click', function () {
			var btn = this;
			busy(btn, true);
			api('POST', 'refresh', {})
				.then(applyDeviceData)
				.then(function () {
					toast('WLED devices refreshed', true);
				})
				.catch(function (err) {
					toast(err.message, false);
				})
				.then(function () {
					busy(btn, false);
				});
		});

		$('#wledAutoImport').on('change', function () {
			var on = $(this).is(':checked');
			api('POST', 'settings', { autoImport: on })
				.then(function () {
					return on ? reload(true) : null;
				})
				.catch(function (err) {
					toast(err.message, false);
				});
		});

		$('#wledDeviceTable').on('click', 'button[data-act]', function () {
			var btn = this;
			var d = findDevice($(btn).closest('tr').attr('data-id'));
			if (!d) return;
			var act = $(btn).attr('data-act');
			var p;
			if (act === 'refresh') {
				p = api('POST', 'refresh', { id: d.id });
			} else if (act === 'rename') {
				var n = prompt('Name for ' + d.host + '\n\nFPP Commands refer to devices by name, so update any playlist entries that use the old name.', d.name);
				if (n === null || $.trim(n) === '' || n === d.name) return;
				p = api('POST', 'devices/' + encodeURIComponent(d.id), { name: $.trim(n) });
			} else if (act === 'toggle') {
				p = api('POST', 'devices/' + encodeURIComponent(d.id), { enabled: d.enabled === false });
			} else if (act === 'remove') {
				if (!confirm('Remove ' + d.name + ' (' + d.host + ')?\n\nPlaylist entries that target it will stop working.')) return;
				p = api('DELETE', 'devices/' + encodeURIComponent(d.id));
			}
			busy(btn, true);
			p.then(applyDeviceData)
				.catch(function (err) {
					toast(err.message, false);
				})
				.then(function () {
					busy(btn, false);
				});
		});

		$('#wledTarget').on('change', renderControls);
		$('#wledPresetFilter').on('input', function () {
			renderNamedList('presets', '#wledPresetList', '#wledPresetFilter', 'preset');
		});
		$('#wledPlaylistFilter').on('input', function () {
			renderNamedList('playlists', '#wledPlaylistList', '#wledPlaylistFilter', 'playlist');
		});

		$('#wledPresetList, #wledPlaylistList')
			.on('click', '[data-run]', function () {
				runNow($(this).attr('data-run'), $(this).closest('[data-name]').attr('data-name'), this);
			})
			.on('click', '[data-add]', function () {
				openPlaylistModal($(this).attr('data-add'), $(this).closest('[data-name]').attr('data-name'));
			});

		$('#wledEffectForm').on('submit', function (ev) {
			ev.preventDefault();
			runNow('effect', null, $(this).find('[type=submit]'));
		});
		$('#wledFxAddBtn').on('click', function () {
			openPlaylistModal('effect', null);
		});

		function bindRange (input, out, zeroLabel) {
			$(input).on('input', function () {
				var v = $(this).val();
				$(out).text(zeroLabel && String(v) === '0' ? zeroLabel : v);
			});
		}
		bindRange('#wledSx', '#wledSxOut');
		bindRange('#wledIx', '#wledIxOut');
		bindRange('#wledFxBri', '#wledFxBriOut', 'unchanged');
		bindRange('#wledBri', '#wledBriOut', '0 (off)');

		$('[data-wled-power]').on('click', function () {
			runNow('power', $(this).attr('data-wled-power'), this);
		});
		$('[data-wled-power-add]').on('click', function () {
			openPlaylistModal('power', $(this).attr('data-wled-power-add'));
		});
		$('#wledBriBtn').on('click', function () {
			runNow('brightness', null, this);
		});
		$('#wledBriAddBtn').on('click', function () {
			openPlaylistModal('brightness', null);
		});
		$('#wledLorBtn').on('click', function () {
			runNow('override', null, this);
		});
		$('#wledLorAddBtn').on('click', function () {
			openPlaylistModal('override', null);
		});

		$('#wledPlName').on('change', function () {
			$('#wledPlNewWrap').toggleClass('d-none', $(this).val() !== '__new__');
		});
		$('#wledPlaylistForm').on('submit', addToPlaylist);
	}

	$(function () {
		if (!document.getElementById('wledApp')) return;
		bind();
		reload(true);
	});
})();
