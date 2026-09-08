# Accessibility

RecoveryFlow by WA.cr's admin is built to WCAG 2.2 Level AA as the minimum and Level AAA wherever it can be achieved, using only core WordPress admin components. That is not a constraint we work around; it is most of the work done for us. Core's `.wrap`, `.nav-tab-wrapper`, `.form-table`, `.card`, `.notice`, `.button`, `WP_List_Table`, `wp.a11y.speak` and `wp.i18n` already carry landmarks, focus styles, contrast and keyboard behaviour. The plugin adds no framework, no build step, and only vanilla JavaScript that progressively enhances server-rendered HTML. Every screen works with JavaScript off.

## Checklist

Each item is checked in code review and, where a tool can catch it, by `tests/a11y`. Success-criterion numbers are from WCAG 2.2.

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
| Overview | `.card` grid of stat cards, each with a visible label and an `aria-label` that includes the value; a recent-activity list; the one-line "RecoveryFlow detects → WA.cr communicates → customer converts" |
| Recovery Journeys | `WP_List_Table` with status badges (icon plus text), row actions with nonces, filters as real form controls in a `<form>`, bulk cancel with confirmation |
| Journey detail | A timeline as an ordered list (`<ol>`); a "Reveal" button for personal data that needs the capability and writes an audit row |
| Workflows | Read-only list and JSON view in the first release; the form-based editor (steps as expandable cards) is planned for slice 2 and will follow this checklist |
| Integrations | Cards with status in words; each links to its settings |
| Settings | Tabs, sections, cards, fields (below) |
| System Status | Tables with row headers; a "Copy diagnostic report" button whose output is redacted; the WP-Cron driver's "Run now" button |
| Public pages | Opt-out confirmation, opt-out done and invalid-link templates: single heading, plain text, one form with one button, no scripts |

## Settings deeplinks

Every tab, section and field in Settings has a stable URL:

```text
admin.php?page=recoveryflow-settings&tab=wacr&section=connection&field=api_key
```

- **Tabs** are real links in a `.nav-tab-wrapper` with `aria-current="page"` on the active one, so they work without JavaScript and are announced correctly.
- **`section=`** scrolls the section heading into view and moves focus to it.
- **`field=`** focuses the control, scrolls it into view (no smooth scroll under `prefers-reduced-motion`), outlines it with a border and a text marker rather than colour alone, and announces the field's label through `wp.a11y.speak()`.
- **Notices, System Status checks and Integrations cards deeplink** to the exact setting: "Missing scope `messages:read` → Settings › WA.cr › Scopes" is a link, not an instruction.
- **"Copy link to this section"** sits on each section heading as a core `.button-link`. It uses `navigator.clipboard` with a visible text fallback and announces success through the live region.
- **Dependent fields are disabled, not hidden**, with an explanation of what to change first, so nothing is discoverable only by accident.
- **Expandable panels** for advanced groups are native `<details>/<summary>`, keyboard-accessible with no ARIA required. Their open state is remembered per user (a small REST update to user meta, with `localStorage` as the fallback).
- **Forms** use the Settings API, so core handles nonces, `settings_errors()` and the save round-trip; each tab is its own settings group.

## How pa11y-ci is run

`npm run a11y` runs pa11y-ci inside the wp-env environment:

1. Start wp-env (`npx @wordpress/env start`) with the plugin active and demo data seeded.
2. pa11y-ci logs in to the admin once (a scripted login action) and reuses the session.
3. It visits every screen listed under Screens and components, each Settings tab, and the public opt-out and invalid-link pages.
4. Standard `WCAG2AAA`; runners `axe` and `htmlcs`. Any failure at AA fails the run; AAA findings are printed and reviewed in the pull request.
5. The same command runs in CI on the integration matrix cell.

## Manual keyboard pass

Before each release, every screen gets a keyboard-only pass recorded here: reach every control with Tab and Shift+Tab in reading order, operate it with Enter or Space, escape from nothing (there is nothing to escape from), and confirm the focus ring is visible throughout. Results for the current release are recorded in the release checklist when the admin screens land in slice 1c.
