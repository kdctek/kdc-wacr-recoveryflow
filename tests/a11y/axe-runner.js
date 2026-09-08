'use strict';

/*
 * pa11y's own axe runner, with one thing put back.
 *
 * axe-core answers in two lists. `violations` are rules it checked and that
 * failed. `incomplete` are rules it could not decide -- most often colour
 * contrast over an element with a background image, where axe can read the text
 * colour but not what is behind it. Deque's own tooling calls that list "needs
 * review" and never reports it as a failure, because it is not one.
 *
 * pa11y's runner sets `type` to 'warning' for an incomplete result and then
 * calls processIssue(), which overwrites `type` from the rule's IMPACT --
 * discarding the distinction. So every "could not determine" comes out as an
 * error. On this plugin that was 28 contrast errors on core's own <select>
 * elements, whose chevron is an SVG background image. Measured in the browser,
 * their text is rgb(30,30,30) on rgb(255,255,255): about 17:1, against a AAA
 * requirement of 7:1. Nothing was wrong with any of them.
 *
 * That matters more than the twenty minutes it costs to check. A gate that
 * reports two dozen failures which are provably fine is a gate people learn to
 * skim, and then the one real finding underneath goes past with them. This
 * repository has already had to remove two commands that reported success while
 * checking nothing; a command that reports failure while finding nothing is the
 * same defect wearing the other colour.
 *
 * So: violations stay errors, incomplete becomes a warning. pa11y drops
 * warnings unless `includeWarnings` is set, so `npm run a11y` gates on what axe
 * actually found and `npm run a11y -- --include-warnings` shows what it could
 * not decide. Nothing is filtered away; the two are told apart.
 *
 * Everything else -- the context, the options, the tag mapping from the
 * `standard` setting -- is pa11y's, and is deliberately left alone.
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
const path = require('path');
const pa11yPath = require.resolve('pa11y');
const axePath = path.dirname(require.resolve('axe-core', { paths: [pa11yPath] }));

const runner = (module.exports = {});

runner.supports = '^6.0.0 || ^6.0.0-alpha || ^6.0.0-beta';

runner.scripts = [`${axePath}/axe.min.js`];

/*
 * This function is stringified and evaluated inside the page, so it may not
 * close over anything in this file.
 */
runner.run = async options => {
	const result = await window.axe.run(getContext(), getOptions());

	return [].concat(
		...result.violations.map(issue => process(issue, level(issue.impact))),
		...result.incomplete.map(issue => process(issue, 'warning'))
	);

	function getContext() {
		return options.rootElement || window.document;
	}

	function getOptions() {
		const tags = {
			WCAG2A: ['wcag2a', 'wcag21a'],
			WCAG2AA: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
			WCAG2AAA: ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21a', 'wcag21aa']
		};

		const axeOptions = {
			runOnly: tags[options.standard] || tags.WCAG2AA
		};

		// pa11y's `ignore` carries its own level names ('notice', 'warning')
		// alongside any rule ids, and axe rejects the whole run on a name it
		// does not know. So both lists are filtered against the rules axe
		// actually has, exactly as pa11y's own runner does.
		const known = {};

		window.axe.getRules().forEach(rule => {
			known[rule.ruleId] = true;
		});

		const rules = {};

		(options.rules || []).filter(rule => known[rule]).forEach(rule => {
			rules[rule] = { enabled: true };
		});

		(options.ignore || []).filter(rule => known[rule]).forEach(rule => {
			rules[rule] = { enabled: false };
		});

		if (Object.keys(rules).length) {
			axeOptions.rules = rules;
		}

		return axeOptions;
	}

	function process(issue, type) {
		let elements = [null];

		if (issue.nodes.length) {
			elements = issue.nodes
				.map(node => node.target.reduce((parts, part) => parts.concat(part), []).join(' '))
				.map(selector => window.document.querySelector(selector));
		}

		return elements.map(element => ({
			type,
			code: issue.id,
			message: `${issue.help} (${issue.helpUrl})`,
			element,
			runnerExtras: {
				description: issue.description,
				impact: issue.impact,
				help: issue.help,
				helpUrl: issue.helpUrl,
				// So a reader of the JSON can tell the two apart without
				// having to know how the type was arrived at.
				needsReview: 'warning' === type
			}
		}));
	}

	function level(impact) {
		switch (impact) {
			case 'critical':
			case 'serious':
				return 'error';
			case 'moderate':
				return 'warning';
			case 'minor':
				return 'notice';
			default:
				return 'error';
		}
	}
};
