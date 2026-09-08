# Accessibility

RecoveryFlow by WA.cr's admin is built to WCAG 2.2 Level AA as the minimum and Level AAA wherever it can be achieved, using only core WordPress admin components. That is not a constraint we work around; it is most of the work done for us. Core's `.wrap`, `.nav-tab-wrapper`, `.form-table`, `.card`, `.notice`, `.button`, `WP_List_Table`, `wp.a11y.speak` and `wp.i18n` already carry landmarks, focus styles, contrast and keyboard behaviour. The plugin adds no framework, no build step, and only vanilla JavaScript that progressively enhances server-rendered HTML. Every screen works with JavaScript off.

## Checklist

Each item is checked in code review and, where a tool can catch it, by the run in `tests/a11y` (see below). Success-criterion numbers are from WCAG 2.2.

| Requirement | WCAG 2.2 | Level | How |
| --- | --- | --- | --- |
| Semantic headings in order; one `h1` per screen; sections introduced by `h2` with an `id` | 1.3.1 Info and Relationships; 2.4.6 Headings and Labels | A, AA | Headings are the settings structure: tab → section (`h2`) → card (`h3`) |
| Landmark roles from core (`main`, `navigation`), no duplicates added | 1.3.1; 2.4.1 Bypass Blocks | A | The plugin renders inside core's admin shell and adds no landmarks of its own |
| Visible focus on every interactive element; core focus styles never removed | 2.4.7 Focus Visible; 2.4.11 Focus Not Obscured (Minimum); 2.4.13 Focus Appearance | AA, AA, AAA | No `outline: none` anywhere in `admin.css`; the deeplinked field's highlight is a border plus text, not colour alone |
| Text contrast at least 7:1 for anything the plugin adds; 4.5:1 as the floor | 1.4.6 Contrast (Enhanced); 1.4.3 Contrast (Minimum) | AAA, AA | Status badges and stat cards use core admin colours checked at 7:1 |
| Non-text contrast at least 3:1 (borders, icons, focus rings) | 1.4.11 Non-text Contrast | AA | Badge icons and card borders checked |
| No information conveyed by colour alone | 1.4.1 Use of Color | A | Every status badge is icon plus text; trends carry a label; Integrations cards say Connected / Available / Not installed in words |
| Everything reachable and operable by keyboard, in a logical order, with no trap | 2.1.1 Keyboard; 2.1.2 No Keyboard Trap; 2.4.3 Focus Order | A | Tabs are real links; panels are native `<details>`; row actions are links or buttons; no custom widgets |
| Live regions for asynchronous results (test connection, run now, copy link) | 4.1.3 Status Messages | AA | `wp.a11y.speak()` on every async result; results also rendered as a core notice |
| Reduced motion respected; no animation otherwise | 2.3.3 Animation from Interactions | AAA | The only motion is the smooth scroll to a deeplinked field, and it is skipped under `prefers-reduced-motion` |
| Target size at least 24 × 24 CSS pixels; 44 × 44 where the layout allows | 2.5.8 Target Size (Minimum); 2.5.5 Target Size (Enhanced) | AA, AAA | Core buttons and row actions meet the minimum; primary actions use full-size buttons |
| Link and button text meaningful out of context | 2.4.4 Link Purpose (In Context); 2.4.9 Link Purpose (Link Only) | A, AAA | "Cancel journey for +91 ••••1234", not "Cancel"; "Copy link to this section" with the section name in the accessible name |
| Every control has a `<label for>`; help text bound with `aria-describedby`; required and invalid states in text as well as attributes | 1.3.1; 3.3.2 Labels or Instructions; 4.1.2 Name, Role, Value | A | `Field_Renderer` refuses to render a field without a label |
| Errors identified in text, with a suggestion, and linked to the field | 3.3.1 Error Identification; 3.3.3 Error Suggestion | A, AA | `settings_errors()` notice lists each error with a deeplink that focuses the field |
| Destructive actions confirmed, and reversible where feasible | 3.3.4 Error Prevention (Legal, Financial, Data); 3.3.6 Error Prevention (All) | AA, AAA | Cancel, revoke links, opt-out and disconnect confirm; cancelled journeys can be retried from `FAILED` or `EXPIRED`; disconnect keeps data |
| No time limits on admin actions | 2.2.1 Timing Adjustable; 2.2.3 No Timing | A, AAA | Nothing in the admin expires or auto-advances |
| Help text in plain language | 3.1.5 Reading Level (in spirit) | AAA | Every description says what the setting does for the merchant, not how it is implemented |
| Language of the page inherited from WordPress | 3.1.1 Language of Page | A | No `lang` overrides; all strings translatable in `kdc-wacr-recoveryflow` |
| Navigation and help consistent across screens | 3.2.3 Consistent Navigation; 3.2.6 Consistent Help | AA, A | Same menu, same tab strip, same "Copy link" affordance on every section |
| Information already entered is not asked for again | 3.3.7 Redundant Entry | A | The Integrations card links to existing settings rather than duplicating them |
| Secret fields usable without guessing | 3.3.8 Accessible Authentication (Minimum) | AA | The API key field is `type="password"` with a core "Show" toggle button; masked values never re-populate, so nothing must be retyped from memory |
| Content on hover or focus dismissible and persistent | 1.4.13 Content on Hover or Focus | AA | No tooltips; help is always visible text |

## Screens and components

| Screen | Built from |
| --- | --- |
| Overview | Two `.card` blocks: what needs attention (only the checks that did not pass, each with a link to its fix) and a `widefat` table of counts, each row linking to that filtered view of the queue |
| Recoveries | `WP_List_Table` with status filters as real links carrying `aria-current`, a search box labelled for screen readers, and sortable columns. Contact details are shortened, with no way to unmask the whole list |
| One recovery | `.card` blocks of row-header tables; a "Show contact details" link that needs the reveal capability and writes an audit row, and says so before and after |
| Integrations | Cards with status in a sentence rather than a badge; unavailable ones are shown with the reason, not hidden |
| Settings | Tabs, sections, cards, fields (below) |
| System Status | `widefat` tables with row headers, and a Result column stating Working, Check this or Stopped in words. Each failing check links to the setting that fixes it |
| Set RecoveryFlow up | The screen a fresh activation lands on. One `.card` with one step -- the API key field, labelled, `type="password"`, its help associated by `aria-describedby` -- plus a "Skip for now" link. The outcome of a connect attempt is a `role="status"` notice, so it is announced after the page loads without moving anybody's focus |
| Public pages | Opt-out confirmation, opt-out done and invalid-link templates: single heading, plain text, one form with one button, no scripts |

The workflows screen and its editor are built. Each step is a native `<details>` panel, so it is keyboard-reachable and needs no ARIA; add, remove and move are ordinary submit buttons with labels that stand up out of context ("Move step 2 up"), which is what 2.4.9 asks for at AAA; and every choice is a `<select>` or a number rather than free text, so nothing depends on knowing a syntax. The editor ships no JavaScript at all.

The diagnostic report is built. It is a read-only `<textarea>` with a real label, which can be selected and copied by hand in any browser with scripts switched off; the "Copy the report" button is an enhancement on top of that and announces success or failure through the same live region the rest of the screen uses.

The "Run now" button on the status screen is built. It is an ordinary form posting to `admin-post.php`, so it works with scripts blocked, and its result is announced through a `role="status"` notice rather than by moving focus away from somebody who had already started reading. What it does is stated in prose beside it before it is pressed -- including that it really sends and really bills -- because the alternative safeguard, a confirmation dialog, only exists when a script loads and so is no safeguard at all on this screen. Somebody who may read the status screen but not work the queue is told which permission is missing rather than finding the control silently absent.

The queue's row action opens a recovery and changes nothing. The four things that DO change one -- stop it, try it again, stop its links working, never message this customer again -- are POST buttons on the recovery's own screen, each with a sentence saying what it will do before it is pressed, because three of them cannot be undone. They are not row actions on the list on purpose: a row action is an anchor, and an anchor that cancels somebody's recovery is fetched by anything that follows links -- a prefetcher, a crawler, the antivirus proxy that opens every URL in an incoming email. A nonce is no defence, because the link in the page carries a valid one. Somebody who may read the queue but not change it is told which permission is missing rather than finding the buttons silently absent.

Not built yet: bulk actions on the queue.

The setup screen has one step rather than four on purpose. Every later thing a wizard would ask -- which channel, how messages are sent, consent, retention -- already has a control on the settings screen with its own deeplink, and a wizard whose remaining steps restate settings that exist elsewhere is a wizard people learn to click through without reading. Connecting a workspace is the only thing on it because it is the only thing RecoveryFlow cannot do for itself.

## Settings deeplinks

Every tab, section and field in Settings has a stable URL:

```text
admin.php?page=recoveryflow-settings&tab=channels&section=email&field=merchant_postal_address#recoveryflow-field-merchant-postal-address
```

- **Tabs** are real links in a `.nav-tab-wrapper` with `aria-current="page"` on the active one, so they work without JavaScript and are announced correctly.
- **`section=`** names the section; each `<h2>` carries a matching `id` and the section is a `<section>` labelled by it.
- **`field=`** outlines the control's row with a border and a background — never colour alone — and the URL carries a matching fragment, so the browser scrolls to the right control with scripts off. With scripts on, focus is moved into the control and the move is announced through `wp.a11y.speak()`; a control inside a collapsed panel has the panel opened first, because focus on a zero-height element goes nowhere.
- **System Status checks and the email compliance card deeplink** to the exact setting. "Set the recovery link lifetime to at least 30 days" is a link, and it crosses to another tab, which is precisely why it is a link and not a sentence.
- **"Link"** sits on each section heading as a real anchor to that section, so right-click and open-in-new-tab keep working; with scripts on it copies to the clipboard instead and announces the result.
- **Dependent fields are rendered and then hidden**, using the `hidden` attribute so they leave the accessibility tree as well as the layout — a row that is merely invisible is still read out and still takes focus on the way past. With scripts off nothing is hidden and every setting is present and editable, which is the difference between an enhancement and a dependency.
- **Expandable panels** for advanced groups are native `<details>/<summary>`, keyboard-accessible with no ARIA required. Their open state is remembered per user through the `/ui-state` REST route, rendered into the page by PHP so a panel that should be open is open in the first paint; `localStorage` is the fallback when that request cannot be made.
- **Forms** use the Settings API, so core handles nonces, `settings_errors()` and the save round-trip; each tab is its own settings group, and a hidden field names the tab so a save cannot wipe the other five.
- **Destructive options** confirm on the way on and never on the way off — error prevention must not stand between somebody and safety.

## How the accessibility run works

It is built. `npm run a11y` checks every screen listed below against WCAG 2.2 AA
and fails if any of them has a finding, then runs the same screens again at AAA
and prints what it finds without failing on it. That split is the requirement:
AA is what this plugin commits to, AAA is what it reaches for, and a gate that
enforced AAA would fail on core WordPress's own colours forever and be switched
off within a week.

```sh
npm run env:start      # a WordPress with the plugin and WooCommerce on it
npm run a11y           # the gate: 22 screens at AA, then the AAA report
npm run a11y:keyboard  # the keyboard pass over the same screens
npm run a11y:clear     # remove the demo data again
```

`npm run a11y` seeds the site itself before each pass, so it is repeatable and
there is no separate step to forget. Set `RECOVERYFLOW_A11Y_SEED=''` to seed by
hand, and `RECOVERYFLOW_A11Y_URL`, `_USER` and `_PASS` to point the whole thing
at something other than the local wp-env.

Four things had to exist before any of this could be honest.

**A session.** Every admin screen is behind a capability check, and a login form
passes an accessibility check cleanly — so a run that was not logged in would
report twenty green screens it never saw. `bin/a11y.sh` logs in once with curl
and hands Chrome the cookies. That the session actually held is not taken on
trust: every admin entry sets `rootElement` to `#wpbody-content`, which exists
only inside wp-admin, so a run that lost its session fails instead of passing.
Verified by mutation — point `rootElement` at a selector that does not exist and
19 of the 22 URLs fail.

**Rows on the screens.** An empty `WP_List_Table` renders none of the status
badges, none of the shortened contact columns and none of the row actions this
document makes claims about, and three screens have no address at all until
something exists to address: one recovery, one workflow's editor, and the public
opt-out page, which needs a live token. `tests/a11y/seed.php` creates six
recoveries through the plugin's own ingest and evaluation passes and spreads
them across the states that render differently. It clears the **consent ledger**
as well as the journeys, which matters more than it sounds: checking the opt-out
page means pressing its button, an opt-out is recorded against the identity
rather than the journey, and a clear-out that missed it left that person
suppressed so the next seeding enrolled one fewer. The queue lost a row per run
while every gate stayed green.

**A site nobody has opened yet.** A freshly activated plugin gets to claim the
first admin request and send it somewhere else. RecoveryFlow does it to show the
setup screen once; WooCommerce does it to show its onboarding wizard. Left
alone, whichever URL pa11y happens to visit first is checked as that screen
instead of itself — and because pa11y-ci visits concurrently, which URL that is
varies between runs. WooCommerce's is the more dangerous of the two: it is a
transient that lives **thirty seconds**, so it had never once fired on a
developer's machine, where wp-env has been up for hours, and fired immediately
on CI, which starts the site and checks it seconds later. It lands on
`wc-admin`, which has a `#wpbody-content` of its own, so the run would have
found its root element and reported WooCommerce's wizard as one of our screens
passing. `settle_first_run()` in the seeder spends both, so the suite always
checks a site somebody has already opened — which is the state these screens
are really used in. The login probe in `bin/a11y.sh` is what caught it, and it
now prints where it was redirected to, because a bare `HTTP 302` says nothing
about which of these two things went wrong.

**An axe runner that tells a failure from a shrug.** axe answers in two lists:
`violations`, which it checked and which failed, and `incomplete`, which it
could not decide — most often contrast over an element with a background image.
pa11y's bundled runner sets incomplete results to `warning` and then overwrites
that from the rule's impact, so every "could not determine" arrives as an error.
Here that was 28 contrast errors on core's own `<select>` elements, whose
chevron is an SVG background image; measured in the browser their text is
rgb(30,30,30) on rgb(255,255,255), about 17:1 against a AAA requirement of 7:1.
`tests/a11y/axe-runner.js` puts the distinction back. Nothing is hidden —
`npm run a11y -- --include-warnings` shows what axe could not decide.

**Somewhere to put the ids.** `.pa11yci.json` holds the list of screens, in the
repository, where it can be read and reviewed. The three addresses that cannot
be known ahead of time are written as `{{PLACEHOLDERS}}`, and the seeder writes
what they stand for into `tests/a11y/urls.generated.json`, which is ignored by
git because it holds two live recovery tokens. A placeholder that was never
filled in fails the run rather than checking a URL that does not resolve, which
pa11y would otherwise report as a clean page.

### What is checked

All 22: Overview; the queue unfiltered, filtered, and with a search that matches
nothing; one recovery; the workflows list; the editor with a workflow loaded and
with nothing loaded; Integrations; each of the seven Settings tabs; a deeplinked
field, because the highlight and the fragment exist in no other state; System
Status; the setup screen; and the three public pages — the opt-out page as it is
presented, the page after its button has been pressed, and what a link that does
not resolve renders.

`tests/smoke.php` fails if a screen this plugin registers, or a settings tab it
defines, is not in that list. Every slice so far has added screens, and a suite
that checks most of them is the same defect as one that checks none, only harder
to notice.

### The two rules that are ignored, and why

Both were verified against the browser's own accessibility tree — what a screen
reader is actually handed — rather than argued away from the specification.
`tests/smoke.php` fails if either is ignored without being explained here, or
explained here without being ignored.

**`WCAG2AA.Principle1.Guideline1_3.1_3_1.F92,ARIA4`** — "this element's role is
presentation but contains child elements with semantic meaning", raised against
`<table class="form-table" role="presentation">` with `<th scope="row">` label
cells inside. This is core WordPress's own settings markup, and ARIA propagates
`presentation` to a table's rows and cells, so the `th` is not exposed as a
header. Confirmed rather than assumed: Chrome's accessibility tree for a
settings tab contains one `LayoutTable` and **no** `table`, `rowheader`,
`columnheader` or `cell` node at all, while the same query against the queue's
`WP_List_Table` returns `table:1 columnheader:14 rowheader:6 gridcell:36`. The
labels reach their controls through `<label for>`, and no control on any screen
is without an accessible name. Re-check it with
`Accessibility.getFullAXTree` over the CDP session, not by reading the HTML.

**`WCAG2AA.Principle1.Guideline1_3.1_3_1.H43.HeadersRequired`** — raised against
the queue, which has column headers in its `thead` and a row header per row, and
so has "multiple levels of th". H43 (`headers`/`id`) and H63 (`scope`) are both
sufficient techniques for 1.3.1; HTML_CodeSniffer insists on the first when it
sees two levels. The markup is `WP_List_Table`'s, shared with every list table
in WordPress, and the accessibility tree above shows the headers resolving. It
would take reimplementing core's row rendering to satisfy the checker, and the
result would be worse markup than core's.

Nothing else is ignored, and neither ignore is silent.

### What the AAA pass reports

380 findings, all of them contrast between 4.85:1 and 6.87:1 — every one clears
AA's 4.5:1, none reaches AAA's 7:1. They are core's `.description` grey, core's
`.nav-tab`, core's `.button`, core's list-table cells: colours this plugin does
not choose and must not claim to have fixed.

One was ours. `.recoveryflow-section__link`, the "Link" anchor on each section
heading, inherited core's `#2271b1` at 4.93:1 — past AA, short of the 7:1 this
document claims for anything the plugin adds. It is now `#0a4b78`, core's own
darker link colour, at 9.2:1 on white and 8.1:1 on the admin grey. **No class
this plugin defines fails AAA.** Set `RECOVERYFLOW_A11Y_AAA_JSON=<path>` to keep
the raw results and diff a change against the last run.

## The keyboard pass

`npm run a11y:keyboard` walks every screen with the Tab key and reports what it
finds. It does not replace a person: it cannot tell you whether the order makes
sense, whether a label reads well out of context, or whether a screen is
comprehensible. It does check the four things a person is worst at checking
reliably across twenty-two screens and best at checking on one.

1. **No keyboard trap** (2.1.2) — Tab all the way round and arrive back where
   you started, in a finite number of stops.
2. **No invisible tab stops** — nothing takes focus that cannot be seen. This is
   the failure the settings screens are most exposed to, because dependent
   fields are rendered and then hidden, and it is invisible to a document scan:
   the markup is perfectly good.
3. **Visible focus** (2.4.7, 2.4.13) — every stop paints an outline or a shadow,
   measured on the focused element rather than asserted from the stylesheet.
4. **An accessible name** (4.1.2) — every stop announces as something.

It never presses anything. A pass that activated every control it found would
cancel recoveries, revoke links and opt people out, and on this plugin one of
those really sends and really bills.

It also checks that a `?field=` deeplink moves focus into that control, because
a broken deeplink looks exactly like a working one in a screenshot.

**Recorded for 0.1.0, on WordPress 6.9 with WooCommerce 11.1:** 21 screens
walked, 98 to 149 stops each, no traps, no invisible stops, every stop paints
and announces. The deeplinked field is focused on arrival.

Both detections are mutation-tested rather than trusted. Removing the focus
outline in `admin.css` turns 19 of the 21 screens red, naming the stops;
collapsing the tab strip to zero size reports each tab as taking focus while
invisible. Two earlier versions of this walk reported clean passes while
measuring almost nothing, and both are worth knowing about because they are easy
to write again:

- Cycle detection compared a description of the focused element — tag, id,
  class — and a dozen wp-admin menu links share all three, so the walk decided
  it had come back round after eight stops. **Every screen reported exactly 8
  stops except the first, which reported 103.** Identical numbers across
  different screens are what a broken measurement looks like; it passed.
- The session was passed to Chrome with `setExtraHTTPHeaders`, which the browser
  overrides once a response sets a cookie. The first screen loaded; every screen
  after it was the login form, which has a tidy tab order and was duly reported
  as clean. The session now goes in the cookie jar, and each admin screen is
  checked for the `wp-admin` body class before it is walked.

## What is still not automated

A screen reader has not been run over these screens. NVDA, JAWS and VoiceOver
disagree with each other and with the accessibility tree, and nothing here
substitutes for listening to one. The checks above establish that the
information is present and correctly associated; they do not establish that it
is pleasant to hear.
