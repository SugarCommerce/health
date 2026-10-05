<?php
/**
 * Tell somebody a health check failed.
 *
 * @package   SugarCommerce\Health
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Health;

use SugarCommerce\HttpClient\Client;

/**
 * Class Alert
 *
 * A doctor run from cron fails into a log nobody reads. Posting the failures
 * to a chat webhook is the cheapest way to make a person hear about them,
 * and one JSON body serves the common ones: `text` is what Slack and ntfy
 * read, `content` is what Discord reads, and each ignores the other.
 *
 * Best effort, always. An alert that cannot be sent must not throw, and must
 * not change the exit code a deploy is gated on — the failure it was about is
 * still the thing that matters.
 *
 * @since 1.0.0
 */
final readonly class Alert {

	/**
	 * Seconds to wait for the webhook. Short: nobody is waiting on it.
	 */
	public const int TIMEOUT = 5;

	/**
	 * @since 1.0.0
	 *
	 * @param Client $http HTTP client. Its SSRF guard stays on: the URL is
	 *                     configuration, and configuration can be wrong.
	 */
	public function __construct( private Client $http ) {}

	/**
	 * Post a summary to a webhook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url     Webhook URL. Empty sends nothing.
	 * @param string $summary What to say.
	 *
	 * @return bool Whether the webhook accepted it.
	 */
	public function post( #[\SensitiveParameter] string $url, string $summary ): bool {
		if ( '' === $url || '' === $summary ) {
			return false;
		}

		try {
			return $this->http->with_timeout( self::TIMEOUT )->post(
				$url,
				(string) json_encode( array( 'text' => $summary, 'content' => $summary ) ),
				array( 'Content-Type' => 'application/json' )
			)->successful();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Post a report's failures, if it has any.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url    Webhook URL.
	 * @param string $title  What was checked.
	 * @param Report $report The results.
	 *
	 * @return bool Whether anything was sent and accepted.
	 */
	public function failures( #[\SensitiveParameter] string $url, string $title, Report $report ): bool {
		return $report->failed() && $this->post( $url, self::summary( $title, $report ) );
	}

	/**
	 * One line naming every failure: "Title: label — detail; label — detail".
	 *
	 * Empty when nothing failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title  What was checked.
	 * @param Report $report The results.
	 *
	 * @return string
	 */
	public static function summary( string $title, Report $report ): string {
		$failures = $report->failures();

		if ( array() === $failures ) {
			return '';
		}

		return $title . ': ' . implode( '; ', array_map(
			static fn ( array $result ): string => $result['label'] . ' — ' . $result['detail'],
			$failures
		) );
	}
}
