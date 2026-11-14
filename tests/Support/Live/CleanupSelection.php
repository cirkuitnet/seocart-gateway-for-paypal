<?php
/**
 * CleanupSelection: what a run against PayPal's sandbox left held, and may void
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support\Live;

/**
 * Chooses, from what PayPal holds now, the authorizations a run left holding money, and nothing the run did not create.
 *
 * The run list names each order the run created with the store uuid it was created for. An order
 * is the run's only when PayPal's own copy carries that uuid as its purchase unit's `custom_id`, so
 * an id that strayed onto the list, or any order of the account's other payments, is never touched.
 * Of the run's orders, an authorization still `CREATED` holds money and is voided; an order nobody
 * approved holds nothing, and lapses at PayPal by itself; nothing else is deleted, and no webhook
 * is created or removed.
 *
 * @since 0.2.0
 */
final class CleanupSelection {

	/**
	 * The path of the request that creates an order, as a tape holds it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CREATE_PATH = '/v2/checkout/orders';

	/**
	 * The shape of an order's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ORDER_ID = '/^[A-Z0-9]{17}$/D';

	/**
	 * The shape of a store uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

	/**
	 * Returns the orders a recording created, read from its tapes: each order's id, with the store uuid it was created for.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The tape files' exchanges, decoded.
	 * @return array<string, string> The store uuids, by order id.
	 */
	public static function createdBy( array $exchanges ): array {
		$orders = array();

		foreach ( $exchanges as $exchange ) {
			$request  = (array) ( $exchange['request'] ?? array() );
			$response = (array) ( $exchange['response'] ?? array() );
			$status   = (int) ( $response['status'] ?? 0 );

			if ( 'POST' !== ( $request['method'] ?? '' ) || self::CREATE_PATH !== ( $request['path'] ?? '' ) || $status < 200 || $status > 299 ) {
				continue;
			}

			$order = json_decode( (string) ( $response['body'] ?? '' ), true );
			$id    = is_array( $order ) ? (string) ( $order['id'] ?? '' ) : '';
			$uuid  = is_array( $order ) ? (string) ( $order['purchase_units'][0]['custom_id'] ?? '' ) : '';

			if ( 1 === preg_match( self::ORDER_ID, $id ) && 1 === preg_match( self::UUID, $uuid ) ) {
				$orders[ $id ] = $uuid;
			}
		}

		return $orders;
	}

	/**
	 * Returns the authorizations to void: of each order on the run list that PayPal says is the run's, the authorization still holding money.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $runList The run's orders: the store uuid of each, by id.
	 * @param list<array<mixed>>    $orders  The orders, as PayPal holds them now.
	 * @return array<string, string> The authorizations' ids, by order id.
	 */
	public static function authorizationsToVoid( array $runList, array $orders ): array {
		$void = array();

		foreach ( $orders as $order ) {
			$id   = (string) ( $order['id'] ?? '' );
			$unit = (array) ( $order['purchase_units'][0] ?? array() );

			if ( ! isset( $runList[ $id ] ) || ( $unit['custom_id'] ?? null ) !== $runList[ $id ] ) {
				continue;
			}

			foreach ( (array) ( $unit['payments']['authorizations'] ?? array() ) as $authorization ) {
				$authorizationId = (string) ( $authorization['id'] ?? '' );

				if ( 'CREATED' === ( $authorization['status'] ?? null ) && 1 === preg_match( self::ORDER_ID, $authorizationId ) ) {
					$void[ $id ] = $authorizationId;
				}
			}
		}

		return $void;
	}

	/**
	 * Returns a run list's line: an order's id and its store uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderId The order's id.
	 * @param string $uuid    The store uuid.
	 * @return string The line, with its newline.
	 */
	public static function line( string $orderId, string $uuid ): string {
		return $orderId . ' ' . $uuid . "\n";
	}
}
