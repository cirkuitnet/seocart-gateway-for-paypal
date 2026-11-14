<?php
/**
 * Fixtures: PayPal's answers, as written in tests/fixtures/paypal
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

/**
 * Loads a fixture, and changes it where a test needs another state.
 *
 * The fixtures are written by hand from PayPal's documented shapes (see tests/fixtures/paypal/README.md).
 *
 * @since 0.2.0
 */
final class Fixtures {

	/**
	 * The intent every order fixture was created for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTENT_UUID = '0199c0de-0000-7000-8000-00000000a001';

	/**
	 * The refund every refund fixture was asked with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_UUID = '0199c0de-0000-7000-8000-00000000b001';

	/**
	 * The order of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_ID = '5O190127TN364715T';

	/**
	 * The authorization of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUTHORIZATION_ID = '0VF52814937998046';

	/**
	 * The capture of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPTURE_ID = '2GG279541U471931P';

	/**
	 * The refund of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_ID = '1JU08902781691411';

	/**
	 * The debug id of the error fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEBUG_ID = 'b1d0a9f2c3e47';

	/**
	 * The access token of the token fixture: the shape of one, nothing more.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACCESS_TOKEN = 'UnitTestAccessTokenOfTheFixturesOnly';

	/**
	 * The vaulted card of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VAULT_ID = '8kk8451t';

	/**
	 * Returns a fixture's text.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When there is no such fixture.
	 *
	 * @param string $name The fixture's name, without `.json`.
	 * @return string The text.
	 */
	public static function text( string $name ): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/paypal/' . $name . '.json' );

		if ( false === $text ) {
			throw new \LogicException( "No fixture {$name}." );
		}

		return $text;
	}

	/**
	 * Returns a fixture decoded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The fixture's name.
	 * @return array<mixed> The object.
	 */
	public static function object( string $name ): array {
		return json_decode( self::text( $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns a fixture with some of its fields changed, encoded.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $name    The fixture's name.
	 * @param array<mixed> $changes The fields to replace, nested as in the object; a list's item by its position.
	 * @return string The changed object, encoded.
	 */
	public static function with( string $name, array $changes ): string {
		return self::encode( array_replace_recursive( self::object( $name ), $changes ) );
	}

	/**
	 * Returns an order fixture whose authorization has another status.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $status  The authorization's status.
	 * @param array<mixed> $changes Optional. More of the authorization's fields. Default none.
	 * @return string The order, encoded.
	 */
	public static function orderWithAuthorization( string $status, array $changes = array() ): string {
		return self::with( 'order-authorized', array( 'purchase_units' => array( array( 'payments' => array( 'authorizations' => array( array( 'status' => $status ) + $changes ) ) ) ) ) );
	}

	/**
	 * Returns the order fixture once its authorization was captured, with its capture and the refunds given.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $refunds Optional. The order's refunds, as PayPal lists them. Default none.
	 * @param array<mixed>       $capture Optional. Fields of the capture to change. Default none.
	 * @return string The order, encoded.
	 */
	public static function orderCaptured( array $refunds = array(), array $capture = array() ): string {
		$payments = array(
			'authorizations' => array( array( 'status' => 'CAPTURED' ) ),
			'captures'       => array( array_replace_recursive( self::object( 'capture' ), $capture ) ),
		);

		if ( array() !== $refunds ) {
			$payments['refunds'] = $refunds;
		}

		return self::with( 'order-authorized', array( 'purchase_units' => array( array( 'payments' => $payments ) ) ) );
	}

	/**
	 * Returns the 422 fixture with another issue.
	 *
	 * @since 0.2.0
	 *
	 * @param string $issue The issue.
	 * @return string The error, encoded.
	 */
	public static function issue( string $issue ): string {
		return self::with( 'error-422', array( 'details' => array( array( 'issue' => $issue ) ) ) );
	}

	/**
	 * Encodes an object as PayPal sends one.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data The object.
	 * @return string The JSON.
	 */
	public static function encode( array $data ): string {
		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}
}
