# WordPress.org listing assets

These files are the plugin's page on WordPress.org. They are **not** part of the
plugin: `.distignore` excludes this whole directory, and `tests/dist-manifest.php`
asserts that nothing under `.wordpress-org/` reaches the zip. On WordPress.org
they live in the `/assets` directory of the plugin's SVN repository, alongside
`/trunk` and `/tags`, not inside the plugin itself.

## What is here

| File | Size | Status |
| --- | --- | --- |
| `icon-256x256.png` | 256 × 256 | **PLACEHOLDER — replace before submission** |
| `banner-1544x500.png` | 1544 × 500 | **PLACEHOLDER — replace before submission** |
| `banner-772x250.png` | 772 × 250 | **PLACEHOLDER — replace before submission** |
| `screenshot-1.png` … `screenshot-7.png` | see below | Real captures from a running install |

## The placeholders

The icon and the two banners were generated from the plugin's name in a neutral
palette. They are legible and correctly sized, so the listing is structurally
complete and can be submitted, but **they are not brand artwork** and no
designer has seen them. They exist so that the release is not blocked on
artwork, and so that whoever makes the real thing has the exact dimensions and a
reference for what sits where.

Replace them with files of the same names and the same dimensions and nothing
else needs to change.

Two things worth knowing when replacing them:

- **The icon is shown at 128 × 128 in search results and often smaller.** Two
  letters or one mark; a wordmark at that size is a grey smudge.
- **The banner is cropped on narrow screens** and the right-hand third is the
  first to go, so nothing that must be read belongs there.

A retina icon (`icon-512x512.png`) and retina banner (`banner-1544x500.png` is
already the retina size; `banner-772x250.png` is the standard one) are optional
and can be added later without touching anything else.

## The screenshots

`readme.txt` lists these in its `== Screenshots ==` section and **the order is
the caption order** — screenshot 1 gets the first caption, and so on. Changing
the order here without changing `readme.txt` mislabels every one of them.

1. Overview: recovery statistics, recent activity and the health of the WA.cr connection.
2. Recovery Journeys: every journey with identified and eligible badges, status and attribution.
3. Journey detail: a timeline of touches, clicks, replies and the conversion.
4. Settings › WA.cr: connection, sender, templates and the "What we send" disclosure.
5. Integrations: WooCommerce connected, planned integrations listed.
6. System Status: scheduler, scopes, HTTPS and the last run of every stage.
7. The consent checkbox on the WooCommerce block checkout.

They are captured from a real install rather than mocked up, which is the point
of them: a screenshot of a screen that does not exist is the same class of
untruth as a document describing a feature that was never built, and this
plugin has had to close three of those.

**No screenshot may contain a real customer's details.** The install they come
from is seeded with invented people. Before replacing any of these, check the
new one for a phone number, an email address or a name that belongs to somebody.
