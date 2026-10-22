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
 * What is posted is cleaned first, because a check's detail is often an
 * error message, and an error message is often a connection string:
 *
 * - **Credentials in URLs** (`mysql://root:pass@db`) and secret-looking
 *   query parameters (`?token=…`) are redacted. See redact().
 * - **Mentions** do nothing. Discord is told to parse none, and Slack's
 *   `<`, `>` and `&` are escaped, so a detail carrying `@everyone` or
 *   `<!channel>` pages nobody.
 * - **Length** is capped under Discord's 2,000 characters, which refuses a
 *   longer message outright — the alert that matters most, with the most
 *   failures, would otherwise be the one that never arrives.
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
	 * The most characters posted. Under Discord's 2,000, with room for the
	 * characters it counts twice.
	 */
	public const int LENGTH = 1900;

	/**
	 * Query parameters whose values are redacted.
	 */
	private const string SECRET_PARAMS = 'access_token|api_key|apikey|auth|key|pass|password|secret|sig|signature|token';

	/**
	 * Post through this client.
	 *
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
	 * @param string $summary What to say. Redacted and capped before it goes.
	 *
	 * @return bool Whether the webhook accepted it.
	 */
	public function post( #[\SensitiveParameter] string $url, string $summary ): bool {
		if ( '' === $url || '' === $summary ) {
			return false;
		}

		$summary = self::cap( self::redact( $summary ) );

		try {
			return $this->http->with_timeout( self::TIMEOUT )->post(
				$url,
				(string) json_encode(
					array(
						'text'             => str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $summary ),
						'content'          => $summary,
						'allowed_mentions' => array( 'parse' => array() ),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
				),
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
		// Not redacted here: post() redacts whatever it is given.
		$failures = $report->failures();

		if ( array() === $failures ) {
			return '';
		}

		return $title . ': ' . implode( '; ', array_map(
			static fn ( array $result ): string => $result['label'] . ' — ' . $result['detail'],
			$failures
		) );
	}

	/**
	 * Text with the credentials it may carry taken out.
	 *
	 * The password in a URL's userinfo (`scheme://user:pass@host`, so a
	 * database DSN in an exception message), and the value of a query
	 * parameter named like a secret (`?token=…`, `&api_key=…`,
	 * `&X-Amz-Signature=…`).
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Text to post.
	 *
	 * @return string
	 */
	public static function redact( #[\SensitiveParameter] string $text ): string {
		$text = (string) preg_replace( '#(\b[a-z][a-z0-9+.\-]*://[^\s/:@]*):[^\s/@]*@#i', '$1:[redacted]@', $text );

		return (string) preg_replace( '~([?&;](?:[\w.-]*?[_-])?(?:' . self::SECRET_PARAMS . ')=)[^&\s#]+~i', '$1[redacted]', $text );
	}

	/**
	 * Text cut to LENGTH characters, ending in an ellipsis when it was cut.
	 *
	 * @param string $text Text to post.
	 *
	 * @return string
	 */
	private static function cap( string $text ): string {
		if ( strlen( $text ) <= self::LENGTH ) {
			return $text;
		}

		// By characters; by bytes only when the text is not valid UTF-8,
		// in which case json_encode() substitutes the broken tail.
		$cut = 1 === preg_match( '/^.{0,' . ( self::LENGTH - 1 ) . '}/su', $text, $match ) ? $match[0] : substr( $text, 0, self::LENGTH - 1 );

		return $cut === $text ? $text : $cut . '…';
	}
}
