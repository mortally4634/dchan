/*
 * DTEG  --  Device Tracking & Entropy Gathering  (js/dteg/)
 * --------------------------------------------------------
 * dteg-collect.js is the core of DTEG: it defines the shared window.DTEG
 * namespace (helpers + a collector registry), gathers the passive
 * device/browser signals (canvas, WebGL, audio, fonts, screen, hardware,
 * timezone, maths/engine quirks, CSS/media, a best-effort incognito check,
 * etc.), detects spoofing tells ("lies") to derive a trust score, hashes the
 * signals into stable identifiers, and posts the raw values to /fp.php so staff
 * can read them un-hashed in the mod panel.
 *
 * The other DTEG files register extra collectors onto window.DTEG and are loaded
 * after this one:
 *   dteg-detect.js    headless/automation battery, CPU benchmark, WebRTC IP
 *                     discovery, WebGPU, window/navigator property dump,
 *                     best-effort extension detection.
 *   dteg-behavior.js  input cadence (keystroke/mouse TIMING only - never which
 *                     keys are pressed or any field value), for bot detection.
 *
 * This core stays silent and passive (no permission prompts / indicators); the
 * detect module adds a couple of active probes (an outbound STUN request for
 * WebRTC, short extension-resource loads) documented in that file.
 *
 * Runs on every public page load. A `stable` hash is stored in the `imgboard_fp`
 * cookie so post.php can stamp it on each post; the full payload is sent to
 * /fp.php once per session (sessionStorage-guarded), with one later refresh so
 * accumulated behaviour is included.
 *
 * Every probe is wrapped so a single failure never aborts the collection.
 */
(function () {
	'use strict';

	if (window.__imgboardFP) { return; }
	window.__imgboardFP = true;

	/* ---- tiny helpers ---------------------------------------------- */

	// cyrb53: fast 53-bit string hash. Two seeds are concatenated to make a
	// 16-hex-char id (no crypto needed; this is an identifier, not a secret).
	function cyrb53(str, seed) {
		var h1 = 0xdeadbeef ^ seed, h2 = 0x41c6ce57 ^ seed;
		for (var i = 0, ch; i < str.length; i++) {
			ch = str.charCodeAt(i);
			h1 = Math.imul(h1 ^ ch, 2654435761);
			h2 = Math.imul(h2 ^ ch, 1597334677);
		}
		h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
		h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
		return (h2 >>> 0).toString(16).padStart(8, '0') + (h1 >>> 0).toString(16).padStart(8, '0');
	}
	function hash(v) {
		var s = (typeof v === 'string') ? v : JSON.stringify(v);
		return cyrb53(s, 0);
	}
	function shortHash(v) { return hash(v).slice(0, 8); }

	// Run a probe safely; record the raw value or an error marker.
	function probe(obj, key, fn) {
		try {
			var r = fn();
			if (r && typeof r.then === 'function') { return r; } // promise
			obj[key] = r;
		} catch (e) {
			obj[key] = '✕ ' + (e && e.message ? e.message : 'err');
		}
		return null;
	}

	var LIES = [];
	function lie(msg) { if (LIES.indexOf(msg) === -1) { LIES.push(msg); } }

	/* ---- native-integrity (lie) checks ----------------------------- */

	function isNative(fn) {
		try {
			return /\{\s*\[native code\]\s*\}/.test(Function.prototype.toString.call(fn));
		} catch (e) { return false; }
	}
	function checkToStringTampered() {
		// If Function.prototype.toString itself is patched, lie-detection via
		// isNative() can't be trusted -- a classic anti-fingerprint tell.
		try {
			if (!/\{\s*\[native code\]\s*\}/.test(Function.prototype.toString.call(Function.prototype.toString))) {
				lie('Function.toString patched');
			}
		} catch (e) { lie('Function.toString throws'); }
	}

	/* ---- shared DTEG namespace (used by the other dteg-*.js files) -- */

	var EXTRA = [];
	window.DTEG = {
		hash: hash,
		shortHash: shortHash,
		probe: probe,
		lie: lie,
		isNative: isNative,
		// register(name, fn): fn runs during collection; may return an object or a Promise of one.
		register: function (name, fn) { EXTRA.push({ name: name, fn: fn }); },
		// setBehavior(fn): fn returns the current behaviour snapshot at send time.
		setBehavior: function (fn) { window.DTEG._behavior = fn; }
	};

	/* ---- individual signal groups ---------------------------------- */

	function navSignals() {
		var o = {};
		var n = navigator;
		probe(o, 'userAgent', function () { return n.userAgent; });
		probe(o, 'appVersion', function () { return n.appVersion; });
		probe(o, 'platform', function () { return n.platform; });
		probe(o, 'vendor', function () { return n.vendor; });
		probe(o, 'vendorSub', function () { return n.vendorSub; });
		probe(o, 'product', function () { return n.product; });
		probe(o, 'productSub', function () { return n.productSub; });
		probe(o, 'oscpu', function () { return n.oscpu; });
		probe(o, 'language', function () { return n.language; });
		probe(o, 'languages', function () { return (n.languages || []).join(','); });
		probe(o, 'hardwareConcurrency', function () { return n.hardwareConcurrency; });
		probe(o, 'deviceMemory', function () { return n.deviceMemory; });
		probe(o, 'maxTouchPoints', function () { return n.maxTouchPoints; });
		probe(o, 'cookieEnabled', function () { return n.cookieEnabled; });
		probe(o, 'doNotTrack', function () { return n.doNotTrack; });
		probe(o, 'globalPrivacyControl', function () { return n.globalPrivacyControl; });
		probe(o, 'pdfViewerEnabled', function () { return n.pdfViewerEnabled; });
		probe(o, 'webdriver', function () { return n.webdriver; });
		probe(o, 'buildID', function () { return n.buildID; });
		probe(o, 'onLine', function () { return n.onLine; });
		if (o.webdriver === true) { lie('navigator.webdriver = true (automation)'); }
		// UA / platform coherence
		try {
			var ua = (n.userAgent || '').toLowerCase(), pf = (n.platform || '').toLowerCase();
			if (pf) {
				if (ua.indexOf('windows') > -1 && pf.indexOf('win') === -1) { lie('UA=Windows but platform=' + n.platform); }
				if (ua.indexOf('mac os') > -1 && pf.indexOf('mac') === -1 && pf.indexOf('iphone') === -1 && pf.indexOf('ipad') === -1) { lie('UA=Mac but platform=' + n.platform); }
				if (ua.indexOf('linux') > -1 && ua.indexOf('android') === -1 && pf.indexOf('linux') === -1) { lie('UA=Linux but platform=' + n.platform); }
				if (ua.indexOf('android') > -1 && pf.indexOf('linux') === -1 && pf.indexOf('android') === -1 && pf.indexOf('arm') === -1) { lie('UA=Android but platform=' + n.platform); }
			}
			var touch = (n.maxTouchPoints || 0) > 0;
			if (ua.indexOf('mobile') > -1 && !touch) { lie('UA=Mobile but no touch points'); }
		} catch (e) {}
		return o;
	}

	function uaDataSignals() {
		// Returns a promise (high-entropy client hints) or null.
		// Only the low-entropy client hints the browser already exposes
		// (brands, mobile, platform). Deliberately NOT getHighEntropyValues():
		// that is the one client-hints call shaped like a request for extra
		// detail, so we skip it to stay as quiet as possible.
		try {
			var d = navigator.userAgentData;
			if (!d) { return null; }
			var brands = [];
			try { brands = (d.brands || []).map(function (b) { return b.brand + ';' + b.version; }); } catch (e) {}
			return { mobile: d.mobile, platform: d.platform, brands: brands.join(',') };
		} catch (e) { return null; }
	}

	function screenSignals() {
		var o = {}, s = screen;
		probe(o, 'width', function () { return s.width; });
		probe(o, 'height', function () { return s.height; });
		probe(o, 'availWidth', function () { return s.availWidth; });
		probe(o, 'availHeight', function () { return s.availHeight; });
		probe(o, 'colorDepth', function () { return s.colorDepth; });
		probe(o, 'pixelDepth', function () { return s.pixelDepth; });
		probe(o, 'devicePixelRatio', function () { return window.devicePixelRatio; });
		probe(o, 'orientation', function () { return s.orientation && s.orientation.type; });
		probe(o, 'innerWidth', function () { return window.innerWidth; });
		probe(o, 'innerHeight', function () { return window.innerHeight; });
		probe(o, 'outerWidth', function () { return window.outerWidth; });
		probe(o, 'outerHeight', function () { return window.outerHeight; });
		probe(o, 'screenX', function () { return window.screenX; });
		probe(o, 'screenY', function () { return window.screenY; });
		try {
			if (s.availWidth > s.width || s.availHeight > s.height) { lie('screen.avail > screen size'); }
		} catch (e) {}
		return o;
	}

	function localeSignals() {
		var o = {};
		probe(o, 'timezone', function () { return Intl.DateTimeFormat().resolvedOptions().timeZone; });
		probe(o, 'timezoneOffset', function () { return new Date().getTimezoneOffset(); });
		probe(o, 'dateFormat', function () { return Intl.DateTimeFormat().resolvedOptions().locale; });
		probe(o, 'numberFormat', function () { return new Intl.NumberFormat().resolvedOptions().locale; });
		probe(o, 'collator', function () { return new Intl.Collator().resolvedOptions().locale; });
		probe(o, 'calendar', function () { return Intl.DateTimeFormat().resolvedOptions().calendar; });
		probe(o, 'numberingSystem', function () { return Intl.DateTimeFormat().resolvedOptions().numberingSystem; });
		probe(o, 'sampleDate', function () { return new Date(0).toLocaleString(); });
		probe(o, 'sampleNumber', function () { return (1234567.89).toLocaleString(); });
		probe(o, 'currency', function () { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(1); });
		// timezone vs reported locale is a loose coherence hint only.
		return o;
	}

	function mathSignals() {
		// Engine-specific rounding in transcendental functions.
		var o = {}, fns = {
			acos: [0.123], acosh: [1e308], asin: [0.123], asinh: [1e300], atan: [0.5],
			atanh: [0.5], cbrt: [100], cos: [1e13], cosh: [500], exp: [1], expm1: [1],
			log: [1e100], log1p: [10], sin: [1e13], sinh: [500], tan: [-1e300],
			tanh: [0.123], pow: [Math.PI, -100]
		};
		for (var k in fns) {
			if (fns.hasOwnProperty(k)) {
				(function (name, args) {
					probe(o, name, function () { return Math.round(Math[name].apply(Math, args) * 1e15) / 1e15; });
				})(k, fns[k]);
			}
		}
		return o;
	}

	function errorSignals() {
		// Error message / stack formatting differs across engines.
		var o = {};
		probe(o, 'evalLength', function () { return eval.toString().length; });
		probe(o, 'typeError', function () {
			try { null(); } catch (e) { return e.message; }
			return '';
		});
		probe(o, 'stackStyle', function () {
			try { throw new Error('x'); } catch (e) {
				return (e.stack || '').split('\n').length > 1 ? 'multiline' : 'single';
			}
		});
		return o;
	}

	function canvas2dSignals() {
		var o = {};
		try {
			var c = document.createElement('canvas');
			c.width = 300; c.height = 60;
			var ctx = c.getContext('2d');
			ctx.textBaseline = 'alphabetic';
			ctx.fillStyle = '#f60'; ctx.fillRect(100, 5, 80, 40);
			ctx.fillStyle = '#069'; ctx.font = '13px "Arial"';
			ctx.fillText('example.com 🐕⚓ «αΩ»', 2, 20);
			ctx.fillStyle = 'rgba(102,204,0,0.65)'; ctx.font = '17px "Times New Roman"';
			ctx.fillText('Cwm fjordbank glyphs vext', 4, 42);
			ctx.globalCompositeOperation = 'multiply';
			ctx.fillStyle = 'rgb(255,0,255)'; ctx.beginPath();
			ctx.arc(50, 30, 25, 0, Math.PI * 2, true); ctx.closePath(); ctx.fill();
			var data = c.toDataURL();
			o.hash = shortHash(data);
			o.data = data; // the rendering itself, not only its hash
			// winding rule probe
			ctx.globalCompositeOperation = 'source-over';
			o.winding = (typeof ctx.isPointInPath === 'function');
			// noise detection: a second identical render that differs => randomised
			var c2 = document.createElement('canvas'); c2.width = 300; c2.height = 60;
			var x2 = c2.getContext('2d');
			x2.fillStyle = '#069'; x2.font = '13px "Arial"';
			x2.fillText('example.com 🐕⚓ «αΩ»', 2, 20);
			if (shortHash(c.toDataURL()) !== o.hash) {
				// re-render of the SAME canvas is deterministic; compare c twice instead
			}
			var first = c.toDataURL(), second = c.toDataURL();
			if (first !== second) { lie('canvas 2d noise (randomised)'); o.noise = true; }
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	function webglSignals(which) {
		var o = {};
		try {
			var c = document.createElement('canvas');
			var gl = c.getContext(which) || c.getContext('experimental-' + which);
			if (!gl) { return { unsupported: true }; }
			o.version = gl.getParameter(gl.VERSION);
			o.shadingLanguage = gl.getParameter(gl.SHADING_LANGUAGE_VERSION);
			o.vendor = gl.getParameter(gl.VENDOR);
			o.renderer = gl.getParameter(gl.RENDERER);
			var dbg = gl.getExtension('WEBGL_debug_renderer_info');
			if (dbg) {
				o.unmaskedVendor = gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL);
				o.unmaskedRenderer = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL);
			}
			o.maxTextureSize = gl.getParameter(gl.MAX_TEXTURE_SIZE);
			o.maxViewport = (function () { try { return Array.prototype.join.call(gl.getParameter(gl.MAX_VIEWPORT_DIMS), 'x'); } catch (e) { return ''; } })();
			o.maxRenderbuffer = gl.getParameter(gl.MAX_RENDERBUFFER_SIZE);
			o.maxVertexAttribs = gl.getParameter(gl.MAX_VERTEX_ATTRIBS);
			o.aliasedLineWidth = (function () { try { return Array.prototype.join.call(gl.getParameter(gl.ALIASED_LINE_WIDTH_RANGE), ','); } catch (e) { return ''; } })();
			try { o.extensions = (gl.getSupportedExtensions() || []).join(','); } catch (e) {}
			// precision formats
			try {
				var p = gl.getShaderPrecisionFormat(gl.FRAGMENT_SHADER, gl.HIGH_FLOAT);
				o.fragHighFloat = p ? (p.precision + ',' + p.rangeMin + ',' + p.rangeMax) : '';
			} catch (e) {}
			// render a triangle and hash the pixels
			try {
				var buf = new Uint8Array(4);
				gl.clearColor(0.2, 0.4, 0.6, 1); gl.clear(gl.COLOR_BUFFER_BIT);
				gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, buf);
				o.pixelHash = shortHash(Array.prototype.join.call(buf, ','));
			} catch (e) {}
			// UA/renderer coherence
			try {
				var r = (o.unmaskedRenderer || o.renderer || '').toLowerCase();
				var ua = (navigator.userAgent || '').toLowerCase();
				if (r && ua.indexOf('windows') > -1 && (r.indexOf('apple') > -1 || r.indexOf('metal') > -1)) {
					lie('WebGL renderer (Apple) contradicts UA (Windows)');
				}
			} catch (e) {}
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	function audioSignals() {
		// OfflineAudioContext render sum -- stable per audio stack.
		return new Promise(function (resolve) {
			var o = {};
			try {
				var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
				if (!OAC) { return resolve({ unsupported: true }); }
				var ctx = new OAC(1, 44100, 44100);
				var osc = ctx.createOscillator();
				osc.type = 'triangle'; osc.frequency.value = 10000;
				var comp = ctx.createDynamicsCompressor();
				try {
					comp.threshold.value = -50; comp.knee.value = 40; comp.ratio.value = 12;
					comp.attack.value = 0; comp.release.value = 0.25;
				} catch (e) {}
				osc.connect(comp); comp.connect(ctx.destination);
				osc.start(0);
				ctx.startRendering();
				var done = false;
				ctx.oncomplete = function (ev) {
					if (done) { return; } done = true;
					try {
						var buf = ev.renderedBuffer.getChannelData(0);
						var sum = 0;
						for (var i = 4500; i < 5000; i++) { sum += Math.abs(buf[i]); }
						o.sum = sum.toString();
						o.hash = shortHash(o.sum);
						try { o.sampleRate = ctx.sampleRate; } catch (e) {}
					} catch (e) { o.error = String(e && e.message); }
					resolve(o);
				};
				setTimeout(function () { if (!done) { done = true; resolve({ timeout: true }); } }, 1500);
			} catch (e) { resolve({ error: String(e && e.message) }); }
		});
	}

	function fontSignals() {
		var o = {};
		try {
			var baseFonts = ['monospace', 'sans-serif', 'serif'];
			var testString = 'mmmmmmmmmmlli-WWW-éçà';
			var testSize = '72px';
			var list = [
				'Arial', 'Arial Black', 'Arial Narrow', 'Arial Rounded MT Bold', 'Bahnschrift',
				'Calibri', 'Cambria', 'Candara', 'Comic Sans MS', 'Consolas', 'Constantia',
				'Corbel', 'Courier New', 'Ebrima', 'Franklin Gothic Medium', 'Gabriola',
				'Gadugi', 'Georgia', 'Impact', 'Ink Free', 'Javanese Text', 'Leelawadee UI',
				'Lucida Console', 'Lucida Sans Unicode', 'MS Gothic', 'MS PGothic', 'MV Boli',
				'Malgun Gothic', 'Marlett', 'Microsoft Himalaya', 'Microsoft JhengHei',
				'Microsoft New Tai Lue', 'Microsoft PhagsPa', 'Microsoft Sans Serif',
				'Microsoft Tai Le', 'Microsoft YaHei', 'Microsoft Yi Baiti', 'MingLiU-ExtB',
				'Mongolian Baiti', 'Myanmar Text', 'Nirmala UI', 'Palatino Linotype',
				'Segoe Print', 'Segoe Script', 'Segoe UI', 'Segoe UI Emoji', 'Segoe UI Symbol',
				'SimSun', 'Sitka', 'Sylfaen', 'Symbol', 'Tahoma', 'Times New Roman',
				'Trebuchet MS', 'Verdana', 'Webdings', 'Wingdings', 'Yu Gothic',
				// macOS / Linux common
				'American Typewriter', 'Andale Mono', 'Apple Chancery', 'Apple Color Emoji',
				'AppleGothic', 'Avenir', 'Avenir Next', 'Baskerville', 'Big Caslon',
				'Chalkboard', 'Cochin', 'Copperplate', 'Didot', 'Futura', 'Geneva',
				'Gill Sans', 'Helvetica', 'Helvetica Neue', 'Hoefler Text', 'Lucida Grande',
				'Menlo', 'Monaco', 'Optima', 'Papyrus', 'San Francisco', 'Skia',
				'DejaVu Sans', 'DejaVu Serif', 'Liberation Sans', 'Liberation Serif',
				'Ubuntu', 'Cantarell', 'Droid Sans', 'Noto Sans', 'Noto Color Emoji',
				'Roboto', 'FreeSans', 'FreeSerif'
			];
			var span = document.createElement('span');
			span.style.position = 'absolute'; span.style.left = '-9999px';
			span.style.fontSize = testSize; span.style.lineHeight = 'normal';
			span.textContent = testString;
			var baseline = {};
			var h = document.getElementsByTagName('body')[0] || document.documentElement;
			var measure = document.createElement('div');
			measure.appendChild(span);
			h.appendChild(measure);
			for (var b = 0; b < baseFonts.length; b++) {
				span.style.fontFamily = baseFonts[b];
				baseline[baseFonts[b]] = { w: span.offsetWidth, hh: span.offsetHeight };
			}
			var detected = [];
			for (var f = 0; f < list.length; f++) {
				var found = false;
				for (var b2 = 0; b2 < baseFonts.length; b2++) {
					span.style.fontFamily = '"' + list[f] + '",' + baseFonts[b2];
					if (span.offsetWidth !== baseline[baseFonts[b2]].w || span.offsetHeight !== baseline[baseFonts[b2]].hh) {
						found = true; break;
					}
				}
				if (found) { detected.push(list[f]); }
			}
			h.removeChild(measure);
			o.installed = detected.join(',');
			o.count = detected.length;
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	function cssMediaSignals() {
		var o = {};
		function mm(q) { try { return window.matchMedia(q).matches; } catch (e) { return null; } }
		o.prefersColorScheme = mm('(prefers-color-scheme: dark)') ? 'dark' : (mm('(prefers-color-scheme: light)') ? 'light' : 'no-pref');
		o.prefersReducedMotion = mm('(prefers-reduced-motion: reduce)');
		o.prefersContrast = mm('(prefers-contrast: more)') ? 'more' : (mm('(prefers-contrast: less)') ? 'less' : 'no-pref');
		o.forcedColors = mm('(forced-colors: active)');
		o.invertedColors = mm('(inverted-colors: inverted)');
		o.hover = mm('(hover: hover)');
		o.anyHover = mm('(any-hover: hover)');
		o.pointer = mm('(pointer: fine)') ? 'fine' : (mm('(pointer: coarse)') ? 'coarse' : 'none');
		o.colorGamut = mm('(color-gamut: rec2020)') ? 'rec2020' : (mm('(color-gamut: p3)') ? 'p3' : (mm('(color-gamut: srgb)') ? 'srgb' : '?'));
		o.dynamicRange = mm('(dynamic-range: high)') ? 'high' : 'standard';
		o.monochrome = mm('(monochrome)');
		return o;
	}

	function cssFeatureSignals() {
		var o = {};
		try {
			var feats = [
				'display: grid', 'display: flex', 'gap: 1px', 'aspect-ratio: 1',
				'backdrop-filter: blur(1px)', 'color: color(display-p3 1 1 1)',
				'width: 1cqw', 'inset: 0', 'accent-color: red', 'scrollbar-width: thin',
				'-webkit-touch-callout: none', '-moz-user-select: none',
				'contain: paint', 'content-visibility: auto', 'overflow: clip'
			];
			var supported = [];
			for (var i = 0; i < feats.length; i++) {
				try { if (window.CSS && CSS.supports(feats[i])) { supported.push(feats[i]); } } catch (e) {}
			}
			o.supports = supported.join('|');
			// system font / colours
			var el = document.createElement('div');
			el.style.cssText = 'color: ActiveText; font: -apple-system-body';
			document.documentElement.appendChild(el);
			var cs = getComputedStyle(el);
			o.systemColor = cs.color;
			document.documentElement.removeChild(el);
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	function domRectSignals() {
		var o = {};
		try {
			var d = document.createElement('div');
			d.style.cssText = 'position:absolute;left:-9999px;width:100.3px;height:50.7px;' +
				'padding:3.1px;border:1.2px solid;transform:rotate(11.3deg) scale(1.03);font-size:17px';
			d.textContent = 'fingerprint';
			document.documentElement.appendChild(d);
			var r = d.getBoundingClientRect();
			o.rect = [r.width, r.height, r.top, r.left, r.right, r.bottom].map(function (n) { return Math.round(n * 1000) / 1000; }).join(',');
			o.hash = shortHash(o.rect);
			document.documentElement.removeChild(d);
		} catch (e) { o.error = String(e && e.message); }
		return o;
	}

	function voiceSignals() {
		return new Promise(function (resolve) {
			var o = {};
			try {
				if (!window.speechSynthesis) { return resolve({ unsupported: true }); }
				var got = function () {
					var vs = speechSynthesis.getVoices() || [];
					o.count = vs.length;
					var all = vs.map(function (v) { return v.name + ':' + v.lang + (v['default'] ? '*' : ''); });
					o.voices = all.join(',');
					// hashed over the first 40 as before, so existing stable ids and fingerprint bans still match
					o.hash = shortHash(all.slice(0, 40).join(','));
					resolve(o);
				};
				var vs0 = speechSynthesis.getVoices();
				if (vs0 && vs0.length) { return got(); }
				speechSynthesis.onvoiceschanged = got;
				setTimeout(function () { if (o.count === undefined) { got(); } }, 800);
			} catch (e) { resolve({ error: String(e && e.message) }); }
		});
	}

	function incognitoSignals() {
		// Best-effort, SILENT private-browsing detection (no permission prompt):
		//   Firefox private windows disable service workers.
		//   Chromium incognito sharply caps the Storage quota.
		// Heuristic by nature, so we keep the raw signals alongside the guess.
		return new Promise(function (resolve) {
			var o = { 'private': null, method: '', storageQuota: null, serviceWorker: ('serviceWorker' in navigator) };
			try {
				var ua = (navigator.userAgent || '').toLowerCase();
				var isFirefox = ua.indexOf('firefox') > -1;
				var brands = '';
				try { brands = (navigator.userAgentData && (navigator.userAgentData.brands || []).map(function (b) { return b.brand; }).join(',')) || ''; } catch (e) {}
				var isChromium = !!window.chrome || /chromium|google chrome/i.test(brands) || (ua.indexOf('chrome') > -1 && ua.indexOf('edg') === -1 && ua.indexOf('opr') === -1);

				if (isFirefox) {
					o['private'] = !('serviceWorker' in navigator);
					o.method = 'firefox:no-serviceworker';
					return resolve(o);
				}
				if (navigator.storage && navigator.storage.estimate) {
					var done = false;
					navigator.storage.estimate().then(function (est) {
						if (done) { return; } done = true;
						o.storageQuota = est && est.quota ? est.quota : 0;
						if (isChromium) {
							// Normal Chromium hands out tens of GB; incognito is capped
							// well under ~1.5 GB on typical devices.
							o['private'] = o.storageQuota > 0 && o.storageQuota < 1.5e9;
							o.method = 'chromium:storage-quota';
						} else {
							o.method = 'storage-quota-only';
						}
						resolve(o);
					})['catch'](function () { if (!done) { done = true; resolve(o); } });
					setTimeout(function () { if (!done) { done = true; resolve(o); } }, 1000);
				} else {
					resolve(o);
				}
			} catch (e) { resolve(o); }
		});
	}

	function miscSignals() {
		var o = {};
		probe(o, 'plugins', function () {
			var p = [];
			for (var i = 0; i < navigator.plugins.length; i++) { p.push(navigator.plugins[i].name); }
			return p.join(',');
		});
		probe(o, 'mimeTypes', function () {
			var m = [];
			for (var i = 0; i < navigator.mimeTypes.length; i++) { m.push(navigator.mimeTypes[i].type); }
			return m.join(',');
		});
		probe(o, 'touchSupport', function () { return ('ontouchstart' in window) + ',' + (navigator.maxTouchPoints || 0); });
		probe(o, 'gamepads', function () { return (navigator.getGamepads ? 'yes' : 'no'); });
		probe(o, 'hardwareConcurrency', function () { return navigator.hardwareConcurrency; });
		probe(o, 'jsHeapLimit', function () { return window.performance && performance.memory ? performance.memory.jsHeapSizeLimit : null; });
		probe(o, 'timingResolution', function () {
			var a = performance.now(), b = performance.now();
			return (b - a);
		});
		probe(o, 'cookieEnabled', function () { return navigator.cookieEnabled; });
		probe(o, 'localStorage', function () { return !!window.localStorage; });
		probe(o, 'sessionStorage', function () { return !!window.sessionStorage; });
		probe(o, 'indexedDB', function () { return !!window.indexedDB; });
		// API presence bitmap (stable across a browser build)
		probe(o, 'apiSurface', function () {
			var apis = ['Promise', 'Proxy', 'Reflect', 'WeakRef', 'BigInt', 'SharedArrayBuffer',
				'Atomics', 'WebAssembly', 'OffscreenCanvas', 'ReportingObserver', 'Scheduler',
				'CSSStyleSheet', 'GPU', 'Serial', 'Bluetooth', 'USB', 'HID', 'PaymentRequest',
				'CredentialsContainer', 'Notification', 'ServiceWorker', 'SpeechRecognition',
				'webkitSpeechRecognition', 'ApplePaySession', 'chrome', 'InstallTrigger'];
			var bits = '';
			for (var i = 0; i < apis.length; i++) { bits += (apis[i] in window) ? '1' : '0'; }
			return bits;
		});
		probe(o, 'intlListFormat', function () { return typeof Intl.ListFormat; });
		return o;
	}

	function integritySignals() {
		var o = {};
		checkToStringTampered();
		var checks = {
			'canvas.toDataURL': function () { return HTMLCanvasElement.prototype.toDataURL; },
			'canvas.getContext': function () { return HTMLCanvasElement.prototype.getContext; },
			'audio.createAnalyser': function () { return (window.AudioContext || window.webkitAudioContext) && (window.AudioContext || window.webkitAudioContext).prototype.createAnalyser; },
			'getImageData': function () { return CanvasRenderingContext2D.prototype.getImageData; }
		};
		for (var k in checks) {
			if (checks.hasOwnProperty(k)) {
				try {
					var fn = checks[k]();
					if (fn && !isNative(fn)) { lie('patched: ' + k); o[k] = 'patched'; }
					else { o[k] = fn ? 'native' : 'absent'; }
				} catch (e) { o[k] = 'err'; }
			}
		}
		// navigator property getter tampering
		try {
			var d = Object.getOwnPropertyDescriptor(Navigator.prototype, 'userAgent');
			if (d && d.get && !isNative(d.get)) { lie('navigator.userAgent getter patched'); }
		} catch (e) {}
		return o;
	}

	/* ---- assemble + send ------------------------------------------- */

	function assemble(components) {
		// Stable subset: the signals most durable across browser updates.
		var stableParts = [
			JSON.stringify(components.webgl && (components.webgl.unmaskedRenderer || components.webgl.renderer)),
			JSON.stringify(components.canvas && components.canvas.hash),
			JSON.stringify(components.audio && components.audio.hash),
			JSON.stringify(components.fonts && components.fonts.installed),
			JSON.stringify(components.screen && [components.screen.width, components.screen.height, components.screen.colorDepth, components.screen.devicePixelRatio]),
			JSON.stringify(components.locale && components.locale.timezone),
			JSON.stringify(components.nav && [components.nav.platform, components.nav.hardwareConcurrency, components.nav.deviceMemory, components.nav.maxTouchPoints]),
			JSON.stringify(components.voices && components.voices.hash)
		];
		var stable = hash(stableParts.join('~'));
		var fp = hash(JSON.stringify(components));
		var trust = Math.max(0, 100 - LIES.length * 20);
		return { fp: fp, stable: stable, trust: trust, lies: LIES.slice(), components: components };
	}

	function setCookie(name, value) {
		try {
			var secure = location.protocol === 'https:' ? '; Secure' : '';
			document.cookie = name + '=' + value + '; path=/; max-age=' + (60 * 60 * 24 * 730) + '; SameSite=Lax' + secure;
		} catch (e) {}
	}

	function send(payload) {
		try {
			var body = JSON.stringify(payload);
			fetch('/fp.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				credentials: 'same-origin',
				// browsers refuse keepalive bodies over 64 KiB, which would lose the whole report
				keepalive: new Blob([body]).size < 60000
			}).then(function (res) {
				// Normal case: 204 no content. A JSON body means this visitor is banned
				// (IP/range or fingerprint) - show the ban page (static boards never hit PHP).
				if (!res || !res.ok || res.status === 204) { return; }
				var ct = res.headers.get('content-type') || '';
				if (ct.indexOf('application/json') === -1) { return; }
				return res.json().then(function (j) {
					if (j && j.banned && j.url && location.pathname !== '/banned.php') {
						location.replace(j.url);
					}
				});
			})['catch'](function () {});
		} catch (e) {}
	}

	function run() {
		var t0 = performance.now();
		var components = {};
		// synchronous groups
		components.nav = navSignals();
		components.screen = screenSignals();
		components.locale = localeSignals();
		components.math = mathSignals();
		components.errors = errorSignals();
		components.canvas = canvas2dSignals();
		components.webgl = webglSignals('webgl');
		components.webgl2 = webglSignals('webgl2');
		components.fonts = fontSignals();
		components.cssMedia = cssMediaSignals();
		components.cssFeatures = cssFeatureSignals();
		components.domRect = domRectSignals();
		components.misc = miscSignals();
		var ti = performance.now();
		components.integrity = integritySignals();
		components.integrityMs = Math.round((performance.now() - ti) * 100) / 100; // how long Integrity ran (bot timing tell)
		components.uaData = uaDataSignals(); // low-entropy client hints (synchronous)

		// Fast async groups that feed the STABLE hash: an OfflineAudioContext
		// render (no microphone), the speech-voice list, and the private-browsing
		// probe. The cookie is set as soon as these finish so posts are stamped
		// without waiting on the slow detection probes below.
		var fastJobs = [
			audioSignals().then(function (r) { components.audio = r; }),
			voiceSignals().then(function (r) { components.voices = r; }),
			incognitoSignals().then(function (r) { components.incognito = r; })
		];

		// Extra collectors registered by the other dteg-*.js files (headless,
		// cpu, props are sync; webrtc/webgpu/extensions/permissions are async and
		// can take a couple of seconds - not part of the stable hash).
		var slowJobs = [];
		for (var i = 0; i < EXTRA.length; i++) {
			(function (ex) {
				try {
					var r = ex.fn();
					if (r && typeof r.then === 'function') { slowJobs.push(Promise.resolve(r).then(function (v) { components[ex.name] = v; })['catch'](function () {})); }
					else { components[ex.name] = r; }
				} catch (e) {}
			})(EXTRA[i]);
		}

		var settle = function (arr) { return Promise.all(arr.map(function (p) { return Promise.resolve(p)['catch'](function () {}); })); };

		settle(fastJobs).then(function () {
			// Stable hash is ready -> stamp the cookie now, as before.
			setCookie('imgboard_fp', assemble(components).stable);

			settle(slowJobs).then(function () {
				if (window.DTEG._behavior) { try { components.behavior = window.DTEG._behavior(); } catch (e) {} }
				components.collectMs = Math.round((performance.now() - t0) * 100) / 100; // total DTEG run time
				var result = assemble(components);
				setCookie('imgboard_fp', result.stable);
				// Send the full payload once per session, and again every 10 minutes of browsing so
				// a change of address (VPN hop, new network) is recorded.
				var marker = 'imgboard_fp_sent';
				var stamp = result.stable + ':' + Math.floor(Date.now() / 600000);
				var already = null;
				try { already = sessionStorage.getItem(marker); } catch (e) {}
				if (already !== stamp) {
					send(result);
					try { sessionStorage.setItem(marker, stamp); } catch (e) {}
				}
				window.__imgboardFPResult = { fp: result.fp, stable: result.stable, trust: result.trust, lies: result.lies };

			// One later refresh so accumulated input behaviour reaches staff. fp.php upserts the
			// same row (ON DUPLICATE KEY), so this updates rather than adding a sighting.
			var refreshed = false;
			var refresh = function () {
				if (refreshed) { return; }
				refreshed = true;
				try {
					if (window.DTEG._behavior) { components.behavior = window.DTEG._behavior(); }
					components.collectMs = Math.round((performance.now() - t0) * 100) / 100;
					var r2 = assemble(components);
					var body = JSON.stringify(r2);
					// sendBeacon returns false when the body is too large for it
					if (!(navigator.sendBeacon && navigator.sendBeacon('/fp.php', new Blob([body], { type: 'application/json' })))) { send(r2); }
				} catch (e) {}
			};
				setTimeout(refresh, 20000);
				window.addEventListener('pagehide', refresh);
				document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { refresh(); } });
			}); // settle(slowJobs)
		}); // settle(fastJobs)
	}

	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		setTimeout(run, 0);
	} else {
		window.addEventListener('DOMContentLoaded', function () { setTimeout(run, 0); });
	}
})();
