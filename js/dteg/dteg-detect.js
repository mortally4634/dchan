/*
 * DTEG  --  dteg-detect.js
 * ------------------------
 * Extra detection collectors registered onto window.DTEG (defined by
 * dteg-collect.js, which loads first). Covers:
 *   - headless / automation browser battery
 *   - permissions-API coherence (a headless-Chrome tell)
 *   - a short CPU-speed benchmark (hardware signal)
 *   - window + navigator property enumeration (extensions/tools leak as globals)
 *   - WebGPU adapter info
 *   - WebRTC IP discovery (host/local IPs always; the srflx candidate exposes the
 *     real public IP behind a VPN/proxy — this makes ONE outbound STUN request to
 *     stun.l.google.com, the only non-silent probe in DTEG)
 *   - best-effort installed-extension detection (loads a few known extension
 *     resources; a resolvable load means that extension is present)
 *
 * It never reads page/form content; only device/environment signals.
 */
(function () {
	'use strict';
	var D = window.DTEG;
	if (!D) { return; }
	var probe = D.probe, lie = D.lie, shortHash = D.shortHash;

	function headlessSignals() {
		var o = {}, n = navigator, tells = [];
		probe(o, 'webdriver', function () { return n.webdriver === true; });
		probe(o, 'languagesEmpty', function () { return !n.languages || n.languages.length === 0; });
		probe(o, 'pluginsEmpty', function () { return !n.plugins || n.plugins.length === 0; });
		probe(o, 'hasChromeObj', function () { return 'chrome' in window; });
		probe(o, 'outerZero', function () { return window.outerWidth === 0 && window.outerHeight === 0; });
		probe(o, 'connectionRtt', function () { return n.connection ? n.connection.rtt : null; });
		probe(o, 'automationGlobals', function () {
			var keys = ['_phantom', '__nightmare', 'callPhantom', 'callSelenium', '_Selenium_IDE_Recorder',
				'__webdriver_evaluate', '__selenium_evaluate', '__webdriver_script_fn', '__driver_evaluate',
				'__fxdriver_evaluate', '__webdriver_unwrapped', '__selenium_unwrapped', 'domAutomation',
				'domAutomationController', '__puppeteer__', 'spawn', 'emit', 'Buffer'];
			var found = [];
			for (var i = 0; i < keys.length; i++) { try { if (keys[i] in window) { found.push(keys[i]); } } catch (e) {} }
			try { for (var k in document) { if (/^\$(cdc|chrome)_/.test(k) || /cdc_[a-z0-9]+_/.test(k)) { found.push(k); } } } catch (e) {}
			return found.join(',');
		});
		probe(o, 'uaHeadless', function () {
			var ua = (n.userAgent || '').toLowerCase();
			return /headless|phantomjs|electron|puppeteer|playwright|slimerjs|nightmare|selenium/.test(ua);
		});
		probe(o, 'chromeCoherence', function () {
			var ua = (n.userAgent || '').toLowerCase();
			return (ua.indexOf('chrome') > -1 && !('chrome' in window)) ? 'chrome-ua-no-chrome-obj' : 'ok';
		});
		if (o.webdriver) { tells.push('navigator.webdriver'); }
		if (o.uaHeadless) { tells.push('headless UA token'); }
		if (o.automationGlobals) { tells.push('automation global: ' + o.automationGlobals); }
		if (o.chromeCoherence !== 'ok') { tells.push(o.chromeCoherence); }
		var ua2 = (n.userAgent || '').toLowerCase();
		if (ua2.indexOf('chrome') > -1 && o.pluginsEmpty && !o.hasChromeObj) { tells.push('Chrome UA, no plugins, no chrome obj'); }
		for (var i = 0; i < tells.length; i++) { lie('headless: ' + tells[i]); }
		o.tells = tells.join(' | ');
		o.score = tells.length; // 0 = looks human
		return o;
	}

	function permissionCoherenceSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				o.notification = (typeof Notification !== 'undefined') ? Notification.permission : 'none';
				if (!navigator.permissions || !navigator.permissions.query) { return resolve(o); }
				navigator.permissions.query({ name: 'notifications' }).then(function (r) {
					o.queried = r.state;
					if (o.notification === 'denied' && r.state === 'prompt') { lie('headless: permission mismatch'); o.mismatch = true; }
					resolve(o);
				})['catch'](function () { resolve(o); });
			} catch (e) { resolve(o); }
		});
	}

	// Rough CPU-speed signal: fixed compute loops, bounded to a few ms.
	function cpuBenchmark() {
		var o = {};
		probe(o, 'mathMs', function () {
			var t = performance.now(), x = 0;
			for (var i = 0; i < 1000000; i++) { x += Math.sqrt(i) * Math.sin(i); }
			o._sink = x | 0;
			return Math.round((performance.now() - t) * 100) / 100;
		});
		probe(o, 'hashMs', function () {
			var t = performance.now(), h = 5381, s = 'dteg-cpu-benchmark-string-abcdefghijklmnop';
			for (var i = 0; i < 200000; i++) { for (var j = 0; j < s.length; j++) { h = ((h << 5) + h + s.charCodeAt(j)) & 0xffffffff; } }
			o._h = h;
			return Math.round((performance.now() - t) * 100) / 100;
		});
		probe(o, 'cores', function () { return navigator.hardwareConcurrency || null; });
		delete o._sink; delete o._h;
		return o;
	}

	// Enumerate window + navigator property NAMES only (never values). Stable per browser build +
	// installed extensions; injected extensions/tools leak as extra globals and <html> attributes.
	function propDumpSignals() {
		var o = {};
		function keysOf(obj) { var a = []; try { for (var k in obj) { a.push(k); } } catch (e) {} return a.sort(); }
		var wk = keysOf(window), nk = keysOf(navigator);
		o.windowKeyCount = wk.length;
		o.navigatorKeyCount = nk.length;
		o.windowKeyHash = shortHash(wk.join(','));
		o.navigatorKeyHash = shortHash(nk.join(','));
		var known = ['ethereum', 'web3', 'solana', 'phantom', 'BinanceChain', 'tronWeb', 'keplr',
			'__darkreader', 'darkreaderMeta', '__REACT_DEVTOOLS_GLOBAL_HOOK__', '__VUE_DEVTOOLS_GLOBAL_HOOK__',
			'Grammarly', 'grammarly', '__grammarly', 'honey', 'Honey', '__honey', 'MetaMask',
			'__lastpass', 'lpcurruser', 'bitwarden', 'adsbygoogle', 'ublock', 'AdBlock', 'adblock',
			'__sharethis__', 'Tampermonkey', '__tampermonkey', 'GM_info', 'GM', 'unsafeWindow', 'wappalyzer'];
		var present = [];
		for (var i = 0; i < known.length; i++) { try { if (known[i] in window) { present.push(known[i]); } } catch (e) {} }
		o.extensionGlobals = present.join(',');
		probe(o, 'htmlAttrs', function () {
			var el = document.documentElement, names = [];
			for (var i = 0; i < el.attributes.length; i++) { names.push(el.attributes[i].name); }
			return names.join(',');
		});
		return o;
	}

	function webgpuSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!navigator.gpu || !navigator.gpu.requestAdapter) { o.supported = false; return resolve(o); }
				o.supported = true;
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 2000);
				navigator.gpu.requestAdapter().then(function (adapter) {
					if (!adapter) { o.adapter = null; if (!done) { done = true; clearTimeout(timer); resolve(o); } return; }
					try { o.features = []; adapter.features.forEach(function (f) { o.features.push(f); }); o.features.sort(); } catch (e) {}
					try {
						var lim = {}, L = adapter.limits;
						['maxTextureDimension2D', 'maxBufferSize', 'maxComputeWorkgroupStorageSize',
							'maxComputeInvocationsPerWorkgroup', 'maxBindGroups', 'maxVertexBuffers'].forEach(function (k) {
							try { lim[k] = L[k]; } catch (e) {}
						});
						o.limits = lim;
					} catch (e) {}
					var finish = function () { o.hash = shortHash(JSON.stringify([o.info, o.features, o.limits])); if (!done) { done = true; clearTimeout(timer); resolve(o); } };
					if (adapter.requestAdapterInfo) {
						adapter.requestAdapterInfo().then(function (info) {
							o.info = { vendor: info.vendor, architecture: info.architecture, device: info.device, description: info.description };
							finish();
						})['catch'](function () { o.info = adapter.info || null; finish(); });
					} else { o.info = adapter.info || null; finish(); }
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	function webrtcSignals() {
		return new Promise(function (resolve) {
			var o = { host: [], srflx: [], mdns: false };
			var RTC = window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection;
			if (!RTC) { o.supported = false; return resolve(o); }
			o.supported = true;
			var pc, done = false;
			function uniq(v, i, a) { return a.indexOf(v) === i; }
			var finish = function () {
				if (done) { return; }
				done = true;
				try { pc.close(); } catch (e) {}
				o.host = o.host.filter(uniq); o.srflx = o.srflx.filter(uniq);
				if (o.srflx.length) { o.publicIp = o.srflx[0]; }
				resolve(o);
			};
			try {
				pc = new RTC({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
				pc.createDataChannel('d');
				pc.onicecandidate = function (e) {
					if (!e || !e.candidate || !e.candidate.candidate) { finish(); return; }
					var c = e.candidate.candidate;
					var m = c.match(/([0-9a-fA-F:.]+\.local|(?:\d{1,3}\.){3}\d{1,3}|[0-9a-fA-F:]{2,}:[0-9a-fA-F:]+)/);
					if (!m) { return; }
					var ip = m[1];
					if (/\.local$/.test(ip)) { o.mdns = true; return; }
					if (c.indexOf('typ host') > -1) { o.host.push(ip); }
					else if (c.indexOf('typ srflx') > -1) { o.srflx.push(ip); }
				};
				pc.createOffer().then(function (d) { return pc.setLocalDescription(d); })['catch'](function () { finish(); });
				setTimeout(finish, 2500);
			} catch (e) { o.supported = false; resolve(o); }
		});
	}

	// Best-effort installed-extension detection: the browser exposes no list, so probe a few
	// popular extensions' web-accessible resources (a load that resolves = installed).
	function extensionSignals() {
		return new Promise(function (resolve) {
			var o = { detected: [] };
			var probes = [
				['uBlock Origin', 'chrome-extension://cjpalhdlnbpafiamejdnhcphjbkeiagm/img/icon_128.png'],
				['AdBlock', 'chrome-extension://gighmmpiobklfepjocnamgkkbiglidom/img/icon24.png'],
				['MetaMask', 'chrome-extension://nkbihfbeogaeaoehlefnkodbefgpgknn/images/icon-128.png'],
				['Dark Reader', 'chrome-extension://eimadpbcbfnmbkopoojfekhnkhdbieeh/icon-128.png'],
				['Grammarly', 'chrome-extension://kbfnbcaeplbcioakkpcpgfkobkghlhen/manifest.json']
			];
			var pending = probes.length, settled = false;
			var done = function () { if (!settled) { settled = true; o.detected.sort(); resolve(o); } };
			var timer = setTimeout(done, 1200);
			probes.forEach(function (p) {
				var img = new Image();
				var fin = function (ok) { if (ok) { o.detected.push(p[0]); } if (--pending <= 0) { clearTimeout(timer); done(); } };
				img.onload = function () { fin(true); };
				img.onerror = function () { fin(false); };
				try { img.src = p[1]; } catch (e) { fin(false); }
			});
		});
	}

	// High-entropy User-Agent Client Hints: the detailed platform / CPU / model /
	// full browser-version data the UA string hides. Silent (no prompt); Chromium
	// only. dteg-collect deliberately took only the LOW-entropy hints to stay quiet
	// — this adds the full detail on purpose ("rawest data").
	function clientHintsSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				var d = navigator.userAgentData;
				if (!d || !d.getHighEntropyValues) { o.supported = false; return resolve(o); }
				o.supported = true;
				var want = ['architecture', 'bitness', 'model', 'platform', 'platformVersion',
					'uaFullVersion', 'fullVersionList', 'wow64', 'formFactor'];
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1500);
				d.getHighEntropyValues(want).then(function (hv) {
					if (done) { return; } done = true; clearTimeout(timer);
					o.architecture = hv.architecture; o.bitness = hv.bitness; o.model = hv.model;
					o.platform = hv.platform; o.platformVersion = hv.platformVersion;
					o.uaFullVersion = hv.uaFullVersion; o.wow64 = hv.wow64; o.formFactor = hv.formFactor;
					try { o.fullVersionList = (hv.fullVersionList || []).map(function (b) { return b.brand + ';' + b.version; }).join(','); } catch (e) {}
					resolve(o);
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	// Battery status: charge level, charging state and charge/discharge times.
	// Silent; a strong short-term correlation signal where still exposed (Chromium).
	function batterySignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!navigator.getBattery) { o.supported = false; return resolve(o); }
				o.supported = true;
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1200);
				navigator.getBattery().then(function (b) {
					if (done) { return; } done = true; clearTimeout(timer);
					o.level = b.level; o.charging = b.charging;
					o.chargingTime = b.chargingTime; o.dischargingTime = b.dischargingTime;
					resolve(o);
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	// Media devices: how many cameras / microphones / speakers and how many device
	// groups, plus labels IF a permission is already granted (otherwise blank — that
	// blankness is itself a signal). enumerateDevices needs no prompt.
	function mediaDeviceSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) { o.supported = false; return resolve(o); }
				o.supported = true;
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1200);
				navigator.mediaDevices.enumerateDevices().then(function (list) {
					if (done) { return; } done = true; clearTimeout(timer);
					var kinds = { audioinput: 0, audiooutput: 0, videoinput: 0 }, groups = {}, labels = [];
					for (var i = 0; i < list.length; i++) {
						var dv = list[i];
						if (kinds[dv.kind] !== undefined) { kinds[dv.kind]++; }
						if (dv.groupId) { groups[dv.groupId] = true; }
						if (dv.label) { labels.push(dv.kind + ':' + dv.label); }
					}
					o.audioIn = kinds.audioinput; o.audioOut = kinds.audiooutput; o.videoIn = kinds.videoinput;
					o.total = list.length; o.groups = Object.keys(groups).length;
					o.labels = labels.join(' | ');
					o.hash = shortHash(kinds.audioinput + '/' + kinds.audiooutput + '/' + kinds.videoinput + '/' + o.groups);
					resolve(o);
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	// Physical keyboard layout (navigator.keyboard.getLayoutMap): the real layout
	// (QWERTY / AZERTY / QWERTZ / …) and locale, independent of the language headers. Silent.
	function keyboardLayoutSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!navigator.keyboard || !navigator.keyboard.getLayoutMap) { o.supported = false; return resolve(o); }
				o.supported = true;
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1000);
				navigator.keyboard.getLayoutMap().then(function (map) {
					if (done) { return; } done = true; clearTimeout(timer);
					var sample = [];
					['KeyQ', 'KeyW', 'KeyE', 'KeyA', 'KeyZ', 'KeyY', 'Semicolon', 'BracketLeft', 'Quote', 'Backquote'].forEach(function (code) {
						try { sample.push(code + '=' + (map.get(code) || '')); } catch (e) {}
					});
					o.size = (typeof map.size === 'number') ? map.size : null;
					o.sample = sample.join(',');
					o.hash = shortHash(o.sample);
					resolve(o);
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	// Network Information: connection class, speed estimate, round-trip, data-saver flag.
	function networkSignals() {
		var o = {};
		try {
			var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
			if (!c) { o.supported = false; return o; }
			o.supported = true;
			o.effectiveType = c.effectiveType; o.type = c.type;
			o.downlink = c.downlink; o.downlinkMax = c.downlinkMax;
			o.rtt = c.rtt; o.saveData = c.saveData;
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	// Storage estimate: total quota + current usage (device/profile dependent) and
	// whether storage is persisted. Keeps the raw figures (the incognito probe only
	// used the quota for its guess).
	function storageEstimateSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!navigator.storage || !navigator.storage.estimate) { o.supported = false; return resolve(o); }
				o.supported = true;
				var done = false, timer = setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1000);
				try { if (navigator.storage.persisted) { navigator.storage.persisted().then(function (p) { o.persisted = p; })['catch'](function () {}); } } catch (e) {}
				navigator.storage.estimate().then(function (est) {
					if (done) { return; } done = true; clearTimeout(timer);
					o.quota = est && est.quota; o.usage = est && est.usage;
					try { if (est && est.usageDetails) { o.usageDetails = JSON.stringify(est.usageDetails); } } catch (e) {}
					resolve(o);
				})['catch'](function () { if (!done) { done = true; clearTimeout(timer); resolve(o); } });
			} catch (e) { resolve(o); }
		});
	}

	D.register('headless', headlessSignals);
	D.register('permissions', permissionCoherenceSignals);
	D.register('cpu', cpuBenchmark);
	D.register('props', propDumpSignals);
	D.register('webgpu', webgpuSignals);
	D.register('webrtc', webrtcSignals);
	D.register('extensions', extensionSignals);
	D.register('clientHints', clientHintsSignals);
	D.register('battery', batterySignals);
	D.register('mediaDevices', mediaDeviceSignals);
	D.register('keyboard', keyboardLayoutSignals);
	D.register('network', networkSignals);
	D.register('storage', storageEstimateSignals);
})();
