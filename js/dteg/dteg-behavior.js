/*
 * DTEG  --  dteg-behavior.js
 * --------------------------
 * Input-cadence (behavioural biometrics) for bot detection. It records HOW input
 * happens - the rhythm of keystrokes and the motion of the mouse - and reports
 * only timing/geometry STATISTICS. It deliberately never reads which keys are
 * pressed, any field value, or any typed text: keys are paired for dwell timing
 * by physical position + keyCode as an opaque id, and nothing else about them is
 * kept. This is the signal that separates a human from a script, without logging
 * what anyone types (so it never touches post text or the staff password field).
 *
 * Loaded after dteg-collect.js; the snapshot is pulled at send time via
 * window.DTEG.setBehavior, and again on the later refresh send.
 */
(function () {
	'use strict';
	var D = window.DTEG;
	if (!D) { return; }

	var B = {
		keydowns: 0, keyups: 0, flights: [], dwellOpen: {}, dwells: [],
		mouseMoves: 0, mousePts: 0, lastMove: null, speeds: [], dist: 0,
		firstPt: null, lastPt: null, clicks: 0, scrolls: 0, paste: 0, startTs: null
	};
	var lastDown = null;

	function onKeyDown(e) {
		var t = performance.now();
		B.keydowns++;
		if (lastDown !== null) { B.flights.push(t - lastDown); }
		lastDown = t;
		var id = (e.location || 0) + ':' + (e.keyCode || 0); // opaque; not the character
		B.dwellOpen[id] = t;
		if (B.startTs === null) { B.startTs = Date.now(); }
	}
	function onKeyUp(e) {
		B.keyups++;
		var id = (e.location || 0) + ':' + (e.keyCode || 0);
		if (B.dwellOpen[id] != null) { B.dwells.push(performance.now() - B.dwellOpen[id]); delete B.dwellOpen[id]; }
	}
	function onMove(e) {
		var t = performance.now(), pt = [e.clientX, e.clientY];
		B.mouseMoves++;
		if (B.firstPt === null) { B.firstPt = pt; }
		if (B.lastMove !== null) {
			var dt = t - B.lastMove.t, dx = pt[0] - B.lastMove.p[0], dy = pt[1] - B.lastMove.p[1];
			var d = Math.sqrt(dx * dx + dy * dy);
			B.dist += d;
			if (dt > 0) { B.speeds.push(d / dt); }
		}
		B.lastMove = { t: t, p: pt }; B.lastPt = pt; B.mousePts++;
	}
	function onClick() { B.clicks++; }
	function onScroll() { B.scrolls++; }
	function onPaste() { B.paste++; }

	try {
		document.addEventListener('keydown', onKeyDown, true);
		document.addEventListener('keyup', onKeyUp, true);
		document.addEventListener('mousemove', onMove, { passive: true, capture: true });
		document.addEventListener('click', onClick, true);
		document.addEventListener('scroll', onScroll, { passive: true });
		document.addEventListener('paste', onPaste, true);
	} catch (e) {}

	function stat(arr) {
		var n = arr.length; if (!n) { return { n: 0 }; }
		var sum = 0, i; for (i = 0; i < n; i++) { sum += arr[i]; }
		var mean = sum / n, v = 0; for (i = 0; i < n; i++) { v += (arr[i] - mean) * (arr[i] - mean); }
		return { n: n, mean: Math.round(mean * 100) / 100, sd: Math.round(Math.sqrt(v / n) * 100) / 100 };
	}

	D.setBehavior(function () {
		var o = {};
		o.keydowns = B.keydowns; o.keyups = B.keyups;
		o.flight = stat(B.flights);   // ms between consecutive keydowns (typing rhythm)
		o.dwell = stat(B.dwells);     // ms a key is held (press -> release)
		o.mouseMoves = B.mouseMoves; o.mousePoints = B.mousePts;
		o.speed = stat(B.speeds);     // px/ms pointer speed
		o.pathDistance = Math.round(B.dist);
		if (B.firstPt && B.lastPt) {
			var nx = B.lastPt[0] - B.firstPt[0], ny = B.lastPt[1] - B.firstPt[1];
			o.netDistance = Math.round(Math.sqrt(nx * nx + ny * ny));
			o.straightness = B.dist > 0 ? Math.round((o.netDistance / B.dist) * 1000) / 1000 : null; // ~1 = robotic straight line
		}
		o.clicks = B.clicks; o.scrolls = B.scrolls; o.pastes = B.paste;
		o.noMouse = (B.keydowns > 3 && B.mouseMoves === 0);        // typed with zero pointer motion
		o.rigidRhythm = (o.flight.n > 6 && o.flight.sd < 2);       // near-perfectly regular keystrokes
		return o;
	});
})();
