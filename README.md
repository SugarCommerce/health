# health

The mechanism of a doctor script: collect ok/warn/fail checks, print an aligned report, exit non-zero on failure so a deploy can be gated on it, and post the failures to a chat webhook. The checks themselves stay in your application.

## Why

Every installable application grows a `doctor` — a list of questions a customer would otherwise answer for you at the worst moment. Is the database writable? Is the webhook secret set? Has the queue worker stopped? The questions are yours. Everything around them is the same in every app, and each copy gets it slightly wrong:

**Too many levels.** An "info" or "skipped" level is a warning somebody did not want to call a warning, and a report people learn to skim stops being read. There are three: ok, warn, fail.

**Warnings that block a deploy.** "Image resizing is off" failing the build teaches everyone to skip the check. Only a failure changes the exit code.

**Half-configured features passing.** A feature configured by three variables with two set is offered to users and then fails. `all_or_none()` treats none as *off* (a warning saying how to switch it on) and some as *half set up* (a warning naming what is missing).

**Failures nobody hears.** A doctor on cron fails into a log. `Alert` posts the failures to a webhook — best effort, because an alert that cannot be sent must not change the exit code the deploy is gated on.

## Features

- 🚦 **Three levels** — `ok`, `warn`, `fail`, with fixed-width icons so labels line up and only failures shout.
- 🧮 **`all_or_none()`** — the optional-feature check: on, off (with how to turn it on), or half set up (with what is missing).
- 🖨️ **`render()`** — a heading, aligned rows and a tally.
- 🚪 **`exit_code()`** — 1 on any failure, so a deploy can be gated on it; warnings never block.
- 📣 **`Alert`** — one JSON body that Slack, ntfy and Discord all read; five-second timeout; never throws.
- 🛡️ **SSRF guard stays on** — the webhook URL is configuration, and configuration can be wrong.

## Requirements

PHP 8.5+, [`sugarcommerce/http-client`](https://github.com/sugarcommerce/http-client)

## Installation

```bash
composer require sugarcommerce/health
```

## Usage

```php
use SugarCommerce\Health\{Alert, Report};
use SugarCommerce\HttpClient\Client;

$report = new Report();

$report->add( is_writable( $dir ) ? 'ok' : 'fail', 'Database directory writable', $dir );
$report->warn( 'Backup', 'none yet — add bin/backup.php to cron' );

$report->all_or_none(
    'Discord roles',
    [ 'DISCORD_CLIENT_ID' => $id, 'DISCORD_BOT_TOKEN' => $token ],
    'sellers can give buyers a role',
    'sellers cannot give buyers a Discord role'
);

echo $report->render( 'MyApp' );

( new Alert( new Client() ) )->failures( $alert_url, 'MyApp doctor', $report );

exit( $report->exit_code() );
```

```
MyApp

  ok   Database directory writable  /var/app/data
 warn  Backup                       none yet — add bin/backup.php to cron
 warn  Discord roles                off — sellers cannot give buyers a Discord role. Set DISCORD_CLIENT_ID, DISCORD_BOT_TOKEN

1 ok, 2 warning(s), 0 failure(s)
```

`Report` is deliberately mutable: a doctor is a long run of independent, often conditional checks, and threading an immutable report through each makes every one a reassignment that a forgotten `$report =` silently drops. It is built in one place and read at the end.

`Alert::summary( $title, $report )` is the one-line text on its own — `"MyApp doctor: Token secret — 3 characters; Queue — 4 overdue"` — and `Alert::post( $url, $text )` sends any text.

## Testing

```bash
composer install
composer test
```

15 tests — result order and levels, unknown levels refused, all three `all_or_none()` outcomes with their exact wording, the rendered report byte for byte, exit codes, and the alert through `HttpClient\Fake`: the JSON body, a refused post, a throwing transport swallowed, no URL sending nothing, and failures posted only when there are some.

## License

GPL-2.0-or-later
