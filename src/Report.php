<?php
/**
 * The results of an installation's health checks.
 *
 * @package   SugarCommerce\Health
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Health;

/**
 * Class Report
 *
 * A doctor script is a list of questions a customer would otherwise answer
 * for you at the worst moment — is the database writable, is the webhook
 * secret set, has the queue worker stopped. The questions are the
 * application's; how the answers are collected, printed and turned into an
 * exit code is the same everywhere, and is this.
 *
 * Mutable, unlike the family's value objects. A doctor script is a long run
 * of independent checks, several of them conditional on earlier ones, and
 * threading an immutable report through each would make every check a
 * reassignment that one forgotten `$report =` silently drops. A report is
 * built in one place and read at the end, so the mutation never escapes.
 *
 * @since 1.0.0
 */
final class Report {

	/**
	 * Every result, in the order the checks ran.
	 *
	 * @var array<int, array{level: string, label: string, detail: string}>
	 */
	private array $results = array();

	/**
	 * Record a check.
	 *
	 * @since 1.0.0
	 *
	 * @param Level|string $level  ok | warn | fail.
	 * @param string       $label  What was checked, short.
	 * @param string       $detail What was found, or what to do about it.
	 *
	 * @return self
	 *
	 * @throws \ValueError For a level that is not one of the three.
	 */
	public function add( Level|string $level, string $label, string $detail = '' ): self {
		$level = $level instanceof Level ? $level : Level::from( $level );

		$this->results[] = array( 'level' => $level->value, 'label' => $label, 'detail' => $detail );

		return $this;
	}

	/**
	 * Record a check that passed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label  What was checked.
	 * @param string $detail What was found.
	 *
	 * @return self
	 */
	public function ok( string $label, string $detail = '' ): self {
		return $this->add( Level::Ok, $label, $detail );
	}

	/**
	 * Record a check worth knowing about.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label  What was checked.
	 * @param string $detail What was found.
	 *
	 * @return self
	 */
	public function warn( string $label, string $detail = '' ): self {
		return $this->add( Level::Warn, $label, $detail );
	}

	/**
	 * Record a check that failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label  What was checked.
	 * @param string $detail What was found, and what to do.
	 *
	 * @return self
	 */
	public function fail( string $label, string $detail = '' ): self {
		return $this->add( Level::Fail, $label, $detail );
	}

	/**
	 * An optional feature configured by a set of variables: all, or none.
	 *
	 * Half a set is the case worth catching — a feature that is offered and
	 * then fails — so it warns, naming what is missing. None set is the
	 * feature switched off, which warns too, saying how to switch it on, but
	 * never fails: an installation works without any of these.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $label What it is.
	 * @param array<string, string> $vars  Variable name => value.
	 * @param string                $on    What it does when it is on.
	 * @param string                $off   What is missed while it is off.
	 *
	 * @return self
	 */
	public function all_or_none( string $label, array $vars, string $on, string $off ): self {
		$missing = array_keys( array_filter( $vars, static fn ( string $value ): bool => '' === $value ) );

		return $this->add(
			$missing ? Level::Warn : Level::Ok,
			$label,
			match ( true ) {
				! $missing                           => $on,
				count( $missing ) === count( $vars ) => 'off — ' . $off . '. Set ' . implode( ', ', array_keys( $vars ) ),
				default                              => 'half set up — missing ' . implode( ', ', $missing ) . '; ' . $off,
			}
		);
	}

	/**
	 * Every result, in order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{level: string, label: string, detail: string}>
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * How many of each level, every level present even at zero.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: int, warn: int, fail: int}
	 */
	public function counts(): array {
		return array_merge( array( 'ok' => 0, 'warn' => 0, 'fail' => 0 ), array_count_values( array_column( $this->results, 'level' ) ) );
	}

	/**
	 * The results that failed.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{level: string, label: string, detail: string}>
	 */
	public function failures(): array {
		return array_values( array_filter( $this->results, static fn ( array $result ): bool => Level::Fail->value === $result['level'] ) );
	}

	/**
	 * Whether anything failed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function failed(): bool {
		return array() !== $this->failures();
	}

	/**
	 * The report, for a person at a terminal.
	 *
	 * A heading, one aligned row per check, and the tally.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title What was checked, e.g. the application's name.
	 *
	 * @return string
	 */
	public function render( string $title ): string {
		$text = "\n" . $title . "\n\n";

		foreach ( $this->results as $result ) {
			$text .= sprintf( "%s %-28s %s\n", Level::from( $result['level'] )->icon(), $result['label'], $result['detail'] );
		}

		$counts = $this->counts();

		return $text . sprintf( "\n%d ok, %d warning(s), %d failure(s)\n\n", $counts['ok'], $counts['warn'], $counts['fail'] );
	}

	/**
	 * The exit code: 1 when anything failed, so the run can gate a deploy.
	 *
	 * Warnings do not count. A deploy blocked on "image resizing is off"
	 * teaches everybody to skip the check.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function exit_code(): int {
		return $this->failed() ? 1 : 0;
	}
}
