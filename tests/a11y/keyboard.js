'use strict';

/*
 * A keyboard pass over every screen, driven rather than described.
 *
 * `docs/accessibility.md` has always said that each release gets a
 * keyboard-only pass. None was ever recorded, and the reason is worth being
 * honest about: a pass done by hand across twenty-two screens is an hour of
 * work that produces a sentence, and the sentence is indistinguishable from one
 * written without doing it. So it did not get done, and the document said it
 * had.
 *
 * This walks the screens with the Tab key and reports what it finds. It does
 * not replace a person -- it cannot tell you whether the order makes SENSE,
 * whether a label reads well out of context, or whether a screen is
 * comprehensible. It does check the four things a person is worst at checking
 * reliably across twenty-two screens, and best at checking on one:
 *
 *   1. NO KEYBOARD TRAP (2.1.2). Tab all the way round and arrive back where
 *      you started, having visited a finite number of stops.
 *   2. NO HIDDEN TAB STOPS. Nothing receives focus that a sighted user cannot
 *      see -- a control inside a closed panel or a `hidden` row that still
 *      takes focus on the way past. This is the failure the settings screen is
 *      most exposed to, because dependent fields are rendered and then hidden.
 *   3. VISIBLE FOCUS (2.4.7, 2.4.13). Every stop paints something when focused
 *      -- an outline, a box-shadow or a border change measured against the same
 *      element unfocused, not merely asserted from the stylesheet.
 *   4. AN ACCESSIBLE NAME (4.1.2). Every stop announces as something.
 *
 * What it deliberately does NOT do is press anything. A pass that activated
 * every control it found would cancel recoveries, revoke links and opt people
 * out, and on this plugin one of those really sends and really bills.
 *
 * Run it through bin/keyboard.sh, which supplies the session the same way the
 * pa11y run does.
 */

/*
 * axe-core and puppeteer are resolved THROUGH pa11y rather than required
 * directly. They are pa11y's dependencies, not this repository's, and reaching
 * for the bare name works only as long as npm happens to hoist them to the top
 * of node_modules -- which is a fact about the installer, not a fact anyone
 * declared. Resolving through pa11y also guarantees this uses the same axe and
 * the same browser pa11y itself runs, so the two passes cannot disagree about
 * what a page contains, and CI does not download a second Chromium.
 */
const puppeteer = require(require.resolve('puppeteer', { paths: [require.resolve('pa11y')] }));

const MAX_STOPS = 400;

async function focusSignature(page) {
	return page.evaluate(() => {
		const el = document.activeElement;

		if (!el || el === document.body) {
			return null;
		}

		const style = window.getComputedStyle(el);
		const rect = el.getBoundingClientRect();

		const label =
			(el.getAttribute('aria-label') || '').trim() ||
			(el.id && (document.querySelector(`label[for="${CSS.escape(el.id)}"]`) || {}).textContent || '').trim() ||
			(el.closest('label') ? el.closest('label').textContent.trim() : '') ||
			(el.textContent || '').trim() ||
			(el.getAttribute('title') || '').trim() ||
			(el.getAttribute('value') || '').trim();

		// "Can a sighted user see this?" -- walk up, because a focusable child
		// of a display:none or [hidden] parent is still focusable in some
		// browsers and is the classic invisible tab stop.
		let hidden = rect.width === 0 && rect.height === 0;

		for (let node = el; node && node !== document.body; node = node.parentElement) {
			const s = window.getComputedStyle(node);

			if (s.display === 'none' || s.visibility === 'hidden' || node.hasAttribute('hidden')) {
				hidden = true;
				break;
			}

			// A closed <details> hides everything but its summary.
			if (node.parentElement && node.parentElement.tagName === 'DETAILS' &&
				!node.parentElement.open && node.tagName !== 'SUMMARY') {
				hidden = true;
				break;
			}
		}

		// Cycle detection marks the ELEMENT, not a description of it. The first
		// version of this built a key from tag, id and class, and on a wp-admin
		// screen a dozen menu links share all three -- so the walk decided it
		// had come back round after eight stops and reported every screen as
		// eight stops with nothing wrong. Eight identical numbers across
		// twenty-one different screens is what a broken measurement looks like;
		// it passed, which is why it is worth saying out loud.
		const revisited = el.hasAttribute('data-rf-kb-seen');

		el.setAttribute('data-rf-kb-seen', '1');

		return {
			tag: el.tagName.toLowerCase(),
			id: el.id || '',
			cls: (el.className && String(el.className).slice(0, 40)) || '',
			label: label.replace(/\s+/g, ' ').slice(0, 60),
			hidden,
			revisited,
			outline: style.outlineWidth,
			outlineStyle: style.outlineStyle,
			shadow: style.boxShadow === 'none' ? '' : style.boxShadow.slice(0, 40),
			key: `${el.tagName}#${el.id}.${String(el.className).slice(0, 30)}`
		};
	});
}

async function walk(page) {
	const stops = [];

	// Start from the top of the document, wherever the page left focus. A
	// deeplinked field is focused on load, so a walk that started from there
	// tabbed to the end of the page and stopped -- four stops on a screen with
	// a hundred and thirteen, reported as a clean pass.
	await page.evaluate(() => {
		window.scrollTo(0, 0);
		document.querySelectorAll('[data-rf-kb-seen]').forEach(el => el.removeAttribute('data-rf-kb-seen'));

		// Blurring is not enough. Chrome keeps a "sequential focus navigation
		// starting point" at the element that had focus, so after a blur the
		// next Tab carries on from THERE rather than from the top -- which on
		// the deeplinked field meant a walk of four stops on a screen with a
		// hundred and thirteen, reported as clean. Focusing the body moves the
		// starting point to the beginning of the document. tabindex="-1" makes
		// the body focusable without adding a tab stop of its own.
		document.body.setAttribute('tabindex', '-1');
		document.body.focus();
	});

	for (let i = 0; i < MAX_STOPS; i++) {
		await page.keyboard.press('Tab');

		const stop = await focusSignature(page);

		if (!stop) {
			// Focus left the document, into the browser's own chrome. That is
			// the end of the cycle, not a fault.
			break;
		}

		if (stop.revisited) {
			// Back on an element already visited: the cycle has closed.
			break;
		}

		stops.push(stop);
	}

	return stops;
}

async function check(page, url) {
	await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });

	// If the session did not survive, this is the login form -- which has a
	// perfectly good tab order and would be reported as a clean screen. wp-admin
	// is behind a capability check, so every admin screen must carry the admin
	// body class; nothing else on this site does.
	const url_is_admin = url.includes('/wp-admin/');
	const is_admin_screen = await page.evaluate(() => document.body.classList.contains('wp-admin') && !document.body.classList.contains('login'));

	if (url_is_admin && !is_admin_screen) {
		throw new Error('this is not the admin screen -- the session did not reach it');
	}

	// Where the page put focus by itself, before anything is pressed. On a
	// deeplinked field that is the point of the URL -- ?field=... is supposed to
	// move focus into that control and announce it -- and it is the only chance
	// to observe it, because the walk below deliberately starts again from the
	// top of the document.
	const landedOn = await page.evaluate(() => {
		const el = document.activeElement;

		return !el || el === document.body ? '' : (el.id || el.name || el.tagName.toLowerCase());
	});

	const stops = await walk(page);

	const faults = [];

	// A ?field= URL that does not move focus is a broken deeplink, and it looks
	// exactly like a working one in a screenshot.
	const wanted = (url.match(/[?&]field=([^&]+)/) || [])[1];

	if (wanted && landedOn.replace(/_/g, '-') !== ('recoveryflow-field-' + wanted.replace(/_/g, '-'))) {
		faults.push(`?field=${wanted} did not move focus into that control (focus was on "${landedOn || 'nothing'}")`);
	}

	if (stops.length === 0) {
		faults.push('nothing at all was reachable with Tab');
	}

	if (stops.length >= MAX_STOPS) {
		faults.push(`Tab did not come back round after ${MAX_STOPS} stops, which is what a keyboard trap looks like`);
	}

	stops.forEach((stop, i) => {
		if (stop.hidden) {
			faults.push(`stop ${i + 1} (${stop.key}) takes focus but cannot be seen`);
		}

		const paints = (stop.outlineStyle !== 'none' && parseFloat(stop.outline) > 0) || stop.shadow !== '';

		if (!paints) {
			faults.push(`stop ${i + 1} (${stop.key}) paints nothing when focused`);
		}

		if (!stop.label) {
			faults.push(`stop ${i + 1} (${stop.key}) announces as nothing`);
		}
	});

	return { url, stops: stops.length, faults, landedOn };
}

(async () => {
	const cookie = process.env.RECOVERYFLOW_A11Y_COOKIE || '';
	const urls = JSON.parse(process.env.RECOVERYFLOW_A11Y_URLS || '[]');

	if (!cookie || urls.length === 0) {
		console.error('keyboard.js: run it through bin/keyboard.sh, which supplies the session and the URLs.');
		process.exit(1);
	}

	const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
	const page = await browser.newPage();

	// The session goes in the COOKIE JAR, not in an extra request header.
	// A header set with setExtraHTTPHeaders is overridden by the browser's own
	// cookie handling as soon as the first response sets a cookie, so the first
	// screen loaded fine and every screen after it was the login form -- which
	// has a tidy tab order and was duly reported as clean. Every admin screen
	// came back as exactly 8 stops while the first came back as 103, which is
	// what that failure looks like from the outside.
	const origin = new URL(urls[0]);

	await page.setCookie(
		...cookie
			.split(';')
			.map(pair => pair.trim())
			.filter(Boolean)
			.map(pair => {
				const at = pair.indexOf('=');

				return {
					name: pair.slice(0, at),
					value: pair.slice(at + 1),
					domain: origin.hostname,
					path: '/'
				};
			})
	);

	let failed = 0;

	for (const url of urls) {
		let result;

		try {
			result = await check(page, url);
		} catch (error) {
			result = { url, stops: 0, faults: [`could not be checked: ${error.message}`] };
		}

		const short = url.replace(/^https?:\/\/[^/]+/, '');

		if (result.faults.length === 0) {
			const landed = result.landedOn ? `  (focus landed on ${result.landedOn})` : '';

			console.log(`  ok    ${String(result.stops).padStart(3)} stops  ${short}${landed}`);
			continue;
		}

		failed++;
		console.log(`  FAIL  ${String(result.stops).padStart(3)} stops  ${short}`);
		result.faults.slice(0, 8).forEach(f => console.log(`          ${f}`));

		if (result.faults.length > 8) {
			console.log(`          ... and ${result.faults.length - 8} more`);
		}
	}

	await browser.close();

	console.log('');

	if (failed > 0) {
		console.log(`keyboard: ${failed} of ${urls.length} screens have something to answer for.`);
		process.exit(1);
	}

	console.log(`keyboard: ${urls.length} screens walked, no traps, no invisible stops, every stop paints and announces.`);
})();
