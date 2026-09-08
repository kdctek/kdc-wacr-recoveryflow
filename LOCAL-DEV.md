# Local development site

A throwaway WordPress runs in Docker via `@wordpress/env`, with WooCommerce
installed and this plugin mounted live from the repository. Edit a file here and
reload the browser: there is no build step and nothing to copy.

## Getting in

| | |
| --- | --- |
| Site | <http://localhost:8888> |
| Admin | <http://localhost:8888/wp-admin> |
| Username | `admin` |
| Password | `password` |
| Test site (PHPUnit only, wiped by test runs) | <http://localhost:8889> |

The test site is a separate WordPress that the integration suite resets. Do not
review anything there; use port 8888.

## Running it

```bash
npm run env:start     # or: npx @wordpress/env start
npm run env:stop
npx @wordpress/env destroy   # deletes the database and starts over
```

Both sites survive a laptop reboot as stopped containers; `start` brings them
back with their data.

## Useful commands

```bash
npx @wordpress/env run cli wp plugin list
npx @wordpress/env run cli wp db query "SELECT * FROM wp_recoveryflow_journeys LIMIT 5"
npx @wordpress/env run cli wp eval 'print_r( WAcr\RecoveryFlow\Support\Options::all() );'
npx @wordpress/env run cli wp option get recoveryflow_settings --format=json
```

`WP_DEBUG` and `WP_DEBUG_LOG` are on, so PHP notices land in
`wp-content/debug.log` inside the container rather than on the page:

```bash
npx @wordpress/env run cli wp eval 'echo shell_exec( "tail -50 " . WP_CONTENT_DIR . "/debug.log" );'
```

## What is already set up

- WooCommerce is installed and active.
- RecoveryFlow is active, its ten tables are created, and the administrator and
  shop manager roles hold its capabilities.
- Nothing is connected to WA.cr, so the plugin sends nothing. Settings has no
  screen yet — that lands in slice 1c.

## Connecting it to WA.cr

Use a **staging** credential (`api.wacart.dev`), never a live one: this site is
disposable and its outbound messages would be real. The environment selector is
on the settings screen once slice 1c lands; until then set it by hand:

```bash
npx @wordpress/env run cli wp eval '$c = new WAcr\RecoveryFlow\WAcr\Credentials(); $c->set_api_key( "wacr_test_..." );'
npx @wordpress/env run cli wp option patch update recoveryflow_settings wacr_environment staging
```
