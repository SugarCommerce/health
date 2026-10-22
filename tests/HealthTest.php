<?php
/**
 * Tests for the health report and its alert.
 *
 * @package   SugarCommerce\Health
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Health\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Health\{Alert, Level, Report};
use SugarCommerce\HttpClient\{Fake, Response};

/**
 * Class HealthTest
 */
final class HealthTest extends TestCase {

	/* ─── Levels ───────────────────────────────────────────────────── */

	public function test_each_level_has_a_six_character_icon(): void {
		$this->assertSame( '  ok  ', Level::Ok->icon() );
		$this->assertSame( ' warn ', Level::Warn->icon() );
		$this->assertSame( ' FAIL ', Level::Fail->icon() );
	}

	/* ─── Collecting ───────────────────────────────────────────────── */

	public function test_results_keep_their_order_and_level(): void {
		$report = ( new Report() )
			->ok( 'Database', '12 tables' )
			->add( 'warn', 'Journal mode', 'delete' )
			->add( Level::Fail, 'Token secret', '3 characters' )
			->warn( 'Backup' )
			->fail( 'Queue' );

		$this->assertSame(
			array(
				array( 'level' => 'ok', 'label' => 'Database', 'detail' => '12 tables' ),
				array( 'level' => 'warn', 'label' => 'Journal mode', 'detail' => 'delete' ),
				array( 'level' => 'fail', 'label' => 'Token secret', 'detail' => '3 characters' ),
				array( 'level' => 'warn', 'label' => 'Backup', 'detail' => '' ),
				array( 'level' => 'fail', 'label' => 'Queue', 'detail' => '' ),
			),
			$report->results()
		);
		$this->assertSame( array( 'ok' => 1, 'warn' => 2, 'fail' => 2 ), $report->counts() );
		$this->assertSame( array( 'Token secret', 'Queue' ), array_column( $report->failures(), 'label' ) );
	}

	public function test_an_unknown_level_is_refused(): void {
		$this->expectException( \ValueError::class );

		( new Report() )->add( 'info', 'Nope' );
	}

	public function test_an_empty_report_counts_zero_of_each(): void {
		$this->assertSame( array( 'ok' => 0, 'warn' => 0, 'fail' => 0 ), ( new Report() )->counts() );
	}

	/* ─── All or none ──────────────────────────────────────────────── */

	public function test_a_feature_with_every_variable_is_on(): void {
		$report = ( new Report() )->all_or_none( 'Discord', array( 'A' => 'x', 'B' => 'y' ), 'roles on', 'no roles' );

		$this->assertSame( array( 'level' => 'ok', 'label' => 'Discord', 'detail' => 'roles on' ), $report->results()[0] );
	}

	public function test_a_feature_with_none_is_off_and_says_how_to_switch_it_on(): void {
		$report = ( new Report() )->all_or_none( 'Discord', array( 'A' => '', 'B' => '' ), 'roles on', 'no roles' );

		$this->assertSame( array( 'level' => 'warn', 'label' => 'Discord', 'detail' => 'off — no roles. Set A, B' ), $report->results()[0] );
	}

	public function test_a_feature_half_set_up_names_what_is_missing(): void {
		$report = ( new Report() )->all_or_none( 'Discord', array( 'A' => 'x', 'B' => '', 'C' => '' ), 'roles on', 'no roles' );

		$this->assertSame( array( 'level' => 'warn', 'label' => 'Discord', 'detail' => 'half set up — missing B, C; no roles' ), $report->results()[0] );
		$this->assertSame( 0, $report->exit_code() );
	}

	/* ─── Rendering and exit codes ─────────────────────────────────── */

	public function test_the_report_renders_as_the_doctor_printed_it(): void {
		$report = ( new Report() )->ok( 'Database', '12 tables' )->warn( 'Backup', 'none yet' )->fail( 'Token secret', '3 characters' );

		$this->assertSame(
			"\nSugarCart\n\n"
			. "  ok   Database                     12 tables\n"
			. " warn  Backup                       none yet\n"
			. " FAIL  Token secret                 3 characters\n"
			. "\n1 ok, 1 warning(s), 1 failure(s)\n\n",
			$report->render( 'SugarCart' )
		);
	}

	public function test_only_a_failure_changes_the_exit_code(): void {
		$report = ( new Report() )->ok( 'A' )->warn( 'B' );

		$this->assertSame( 0, $report->exit_code() );
		$this->assertFalse( $report->failed() );
		$this->assertSame( 1, $report->fail( 'C' )->exit_code() );
	}

	/* ─── Alerts ───────────────────────────────────────────────────── */

	public function test_the_summary_names_every_failure(): void {
		$report = ( new Report() )->ok( 'A', 'fine' )->fail( 'B', 'broken' )->fail( 'C', 'gone' );

		$this->assertSame( 'SugarCart doctor: B — broken; C — gone', Alert::summary( 'SugarCart doctor', $report ) );
		$this->assertSame( '', Alert::summary( 'SugarCart doctor', ( new Report() )->ok( 'A' ) ) );
	}

	public function test_an_alert_posts_text_and_content_as_json(): void {
		$http = Fake::strict()->on( 'POST', 'https://hooks.example.com/*', new Response( true, 204 ) );

		$this->assertTrue( ( new Alert( $http ) )->post( 'https://hooks.example.com/abc', 'it broke' ) );

		$sent = $http->sent( 'POST' );

		$this->assertCount( 1, $sent );
		$this->assertSame( array( 'text' => 'it broke', 'content' => 'it broke', 'allowed_mentions' => array( 'parse' => array() ) ), json_decode( (string) $sent[0]['body'], true ) );
		$this->assertSame( 'application/json', $sent[0]['headers']['Content-Type'] );
	}

	public function test_an_alert_does_not_carry_credentials(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( true, 204 ) );

		( new Alert( $http ) )->post( 'https://hooks.example.com/abc', 'DB — SQLSTATE: mysql://root:hunter2@db/shop; S3 — https://s3.example/b?X-Amz-Signature=abc123&x=1 https://api.example/?token=t0k3n' );

		$body = (string) $http->sent()[0]['body'];

		$this->assertStringNotContainsString( 'hunter2', $body );
		$this->assertStringNotContainsString( 'abc123', $body );
		$this->assertStringNotContainsString( 't0k3n', $body );
		$this->assertStringContainsString( 'mysql://root:[redacted]@db/shop', $body );
		$this->assertStringContainsString( 'x=1', $body );
	}

	public function test_an_alert_pages_nobody(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( true, 204 ) );

		( new Alert( $http ) )->post( 'https://hooks.example.com/abc', '@everyone <!channel> & more' );

		$sent = json_decode( (string) $http->sent()[0]['body'], true );

		$this->assertSame( '@everyone &lt;!channel&gt; &amp; more', $sent['text'] );
		$this->assertSame( array( 'parse' => array() ), $sent['allowed_mentions'] );
	}

	/**
	 * Discord refuses a message over 2,000 characters outright.
	 */
	public function test_a_long_alert_is_cut_short_not_lost(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( true, 204 ) );

		( new Alert( $http ) )->post( 'https://hooks.example.com/abc', str_repeat( 'é', 5000 ) );

		$sent = json_decode( (string) $http->sent()[0]['body'], true );

		$this->assertSame( Alert::LENGTH, mb_strlen( $sent['content'] ) );
		$this->assertStringEndsWith( '…', $sent['content'] );
	}

	public function test_an_alert_with_broken_utf8_is_still_sent(): void {
		$http = Fake::strict()->on( 'POST', '*', new Response( true, 204 ) );

		( new Alert( $http ) )->post( 'https://hooks.example.com/abc', "bad \xC3 byte" );

		$this->assertStringStartsWith( 'bad ', json_decode( (string) $http->sent()[0]['body'], true )['content'] );
	}

	public function test_a_detail_cannot_start_a_row_of_its_own(): void {
		$text = ( new Report() )->fail( 'DB', "gone\n  ok   Fake row\e[2J" )->render( 'Doctor' );

		$this->assertStringNotContainsString( "\n  ok   Fake", $text );
		$this->assertStringNotContainsString( "\e", $text );
	}

	public function test_a_refused_alert_is_false_not_an_exception(): void {
		$http = Fake::strict()->on( 'POST', '*', Response::failure( 'timeout' ) );

		$this->assertFalse( ( new Alert( $http ) )->post( 'https://hooks.example.com/abc', 'it broke' ) );
	}

	public function test_an_alert_that_throws_is_swallowed(): void {
		$http = Fake::strict()->on( 'POST', '*', static function (): Response {
			throw new \RuntimeException( 'network gone' );
		} );

		$this->assertFalse( ( new Alert( $http ) )->post( 'https://hooks.example.com/abc', 'it broke' ) );
	}

	public function test_no_url_sends_nothing(): void {
		$http = Fake::strict();

		$this->assertFalse( ( new Alert( $http ) )->post( '', 'it broke' ) );
		$this->assertSame( array(), $http->sent() );
	}

	public function test_failures_are_posted_only_when_there_are_some(): void {
		$http  = Fake::strict()->on( 'POST', '*', new Response( true, 200 ) );
		$alert = new Alert( $http );

		$this->assertFalse( $alert->failures( 'https://hooks.example.com/abc', 'Doctor', ( new Report() )->ok( 'A' ) ) );
		$this->assertSame( array(), $http->sent() );

		$this->assertTrue( $alert->failures( 'https://hooks.example.com/abc', 'Doctor', ( new Report() )->fail( 'B', 'broken' ) ) );
		$this->assertSame( 'Doctor: B — broken', json_decode( (string) $http->sent()[0]['body'], true )['text'] );
	}
}
