<?php
/**
 * How a check came out.
 *
 * @package   SugarCommerce\Health
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Health;

/**
 * Enum Level
 *
 * Three answers and no more. A fourth — "info", "skipped" — is always a
 * warning somebody did not want to call a warning, and a report people learn
 * to skim past is a report that stops being read.
 *
 * - **ok**: works.
 * - **warn**: works, or is off on purpose, but somebody should know.
 * - **fail**: something a customer will run into. A deploy is gated on these.
 *
 * @since 1.0.0
 */
enum Level: string {

	/**
	 * Works.
	 */
	case Ok = 'ok';

	/**
	 * Works, or is off on purpose, but somebody should know.
	 */
	case Warn = 'warn';

	/**
	 * Something a customer will run into. A deploy is gated on these.
	 */
	case Fail = 'fail';

	/**
	 * The six-character tag a report row starts with.
	 *
	 * Fixed width so the labels line up, and only a failure in capitals, so
	 * it is the one an eye finds first.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function icon(): string {
		return match ( $this ) {
			self::Ok   => '  ok  ',
			self::Warn => ' warn ',
			self::Fail => ' FAIL ',
		};
	}
}
