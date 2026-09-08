# Internationalization

RecoveryFlow is translated as a whole, not in the places somebody remembered.
Every string a person can read — admin screens, notices, error sentences,
status labels, capability descriptions, WP-CLI output, JavaScript in the admin
— goes through a WordPress translation function with the literal text domain
`kdc-wacr-recoveryflow`.

This is not a formality. A merchant running a Spanish or Arabic shop sees the
plugin in whatever language its strings were left in, and an untranslated
string never announces itself: nothing errors, nothing logs, the plugin simply
speaks English at somebody who does not. The only way to catch it is to make it
impossible to add, which is what the checks below are for.

## The rules

**Use the literal domain, always.** `__( 'Text', 'kdc-wacr-recoveryflow' )`.
Never a variable, never a constant, never a concatenation — the string
extractor reads source code, not runtime values, so anything it cannot see as a
literal never reaches a translator.

**Pick the right function.**

| Situation | Function |
|---|---|
| Return a translated string | `__()` |
| Echo one | `esc_html_e()` / `esc_attr_e()` |
| Return one for HTML output | `esc_html__()` / `esc_attr__()` |
| Short or ambiguous label | `_x()` with a context |
| Anything counted | `_n()`, or `_nx()` with a context |

**Escape at output, translate at source.** `esc_html__()` is
`esc_html( __() )` in one call; use it wherever the string lands in markup. A
translated string is still untrusted input — a translation file can contain
anything.

**Give short labels a context.** On its own, "New" could be a noun, a verb or
an adjective, and languages that inflect for gender or number cannot translate
it correctly without knowing what it describes. Journey states use
`_x( 'New', 'recovery journey state', … )` for exactly this reason. If a label
is one or two words and could plausibly appear elsewhere in the UI meaning
something else, it needs a context.

**Never build a sentence by concatenation.** Word order is not universal.

```php
// Wrong -- unfixable in a language that puts the verb last.
echo __( 'Sent to ', 'kdc-wacr-recoveryflow' ) . $number;

// Right.
printf(
    /* translators: %s: the customer's WhatsApp number. */
    esc_html__( 'Sent to %s', 'kdc-wacr-recoveryflow' ),
    esc_html( $number )
);
```

**Number your placeholders whenever there is more than one.** `%1$s` and
`%2$s`, not `%s` twice — a translator has to be able to reorder them.

**Write a `/* translators: */` comment for every placeholder**, on the line
directly above the translation call, saying what each one will contain. `%s` on
its own tells a translator nothing about whether it is a name, a number or a
date.

**Do not translate values.** Hostnames, hook names, capability slugs, reason
codes, state names and API field names stay in English and stay out of the
translatable string — pass them in as placeholders. `Credentials::environments()`
does this with the API hostname.

**Format numbers and dates for the locale.** `number_format_i18n()`,
`wp_date()` — not `number_format()` or `gmdate()` — anywhere a person reads the
result. Timestamps stored in the database stay UTC and unformatted.

**Nothing is translated before `init`.** The text domain is loaded on `init`
priority 1. A translation call that runs earlier — on `plugins_loaded`, in an
activation hook, at file scope — makes WordPress load the text domain too early,
which it warns about since 6.7, and hands back the untranslated string anyway.

The shape that avoids it: decide in codes, translate at render time.
`Requirements::unmet()` returns machine-readable rows and can run at any point
in the boot; `Requirements::failures()` turns them into sentences and is only
called from `admin_notices`. Anything evaluated in a background job — where
there is no user and no locale worth resolving — must follow the same split:
store the code, translate only where it is displayed.

**Admin JavaScript is translated too.** Register the script, then call
`wp_set_script_translations( $handle, 'kdc-wacr-recoveryflow', … )`, and use
`__()` from `@wordpress/i18n` in the script itself.

**WP-CLI output is deliberately not translated**, which is a decision rather
than an oversight. WP-CLI resolves no locale of its own and its whole framework
— every flag, every error, every `--help` page a RecoveryFlow command sits
beside — is English. A half-translated help screen is harder to read than an
English one, and the audience for these commands is somebody who is already
reading English error messages from `wp db` and `wp cron`. `src/CLI/` therefore
carries no `__()` and contributes no msgids. If that changes, it changes for
the whole command, not one string in it.

## Checks

Three of them, all runnable locally and all in CI.

```
composer lint          # PHPCS, including WordPress.WP.I18n
composer i18n:audit    # the token scan described below
composer i18n:check    # fail if the .pot has drifted from the source
composer i18n          # regenerate the .pot
```

`WordPress.WP.I18n` (PHPCS) checks the calls that are already translation
calls: a missing or mismatched domain, a missing `translators` comment, an
unordered placeholder.

`tests/i18n-audit.php` is a dependency-free token scan over every shipped PHP
file. It exists because PHPCS only runs for a contributor who has run
`composer install`, whereas this runs in the smoke suite on every supported PHP
version with nothing installed. It reports a missing or wrong text domain, and
any translatable argument that is not a single literal string. It is
deliberately conservative — it reports only what is certainly wrong. Run it on
its own against any checkout:

```
php tests/i18n-audit.php            # this plugin
php tests/i18n-audit.php /some/path # another checkout
```

`bin/i18n.sh --check` regenerates the catalogue into a temporary file and
compares it, ignoring the creation date. A `.pot` that has drifted is not
harmless staleness: translators work from that file, so every string added
since the last regeneration is a string no locale has been given the chance to
translate.

**Regenerate the `.pot` in the same commit that adds or changes a string.**
`composer i18n` writes `languages/kdc-wacr-recoveryflow.pot`; commit it.

## What the scan cannot catch

A string that was never wrapped in the first place. No tool can reliably tell a
user-facing sentence from an option key, a hook name or a SQL fragment, so this
one stays a review responsibility: when reading a diff, ask of every English
string whether a person will ever read it, and wrap it if the answer is yes.
