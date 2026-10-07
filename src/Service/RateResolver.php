<?php
/**
 * Works out what to charge for shipping.
 *
 * @package AcsCourier
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AcsCourier\Service;

use AcsCourier\Api\AcsException;
use AcsCourier\Api\RetryingClient;
use AcsCourier\Domain\Country;
use AcsCourier\Domain\Weight;

/**
 * Works out what to charge for shipping.
 *
 * Greece is priced live by ACS. Cyprus cannot be: ACS_Price_Calculation does
 * not support it, so Cypriot rates come from the merchant's own table.
 */
final class RateResolver {

	/**
	 * ACS pricing method.
	 */
	public const ALIAS_PRICE = 'ACS_Price_Calculation';

	/**
	 * Client used to reach ACS.
	 *
	 * @var RetryingClient
	 */
	private RetryingClient $client;

	/**
	 * Local fallback and Cyprus source of truth.
	 *
	 * @var RateTable
	 */
	private RateTable $table;

	/**
	 * ACS station the shop despatches from.
	 *
	 * @var string
	 */
	private string $origin_station;

	/**
	 * ACS billing code.
	 *
	 * @var string
	 */
	private string $billing_code;

	/**
	 * Stores collaborators and shop configuration.
	 *
	 * @param RetryingClient $client         Client used to reach ACS.
	 * @param RateTable      $table          Local rate table.
	 * @param string         $origin_station ACS station the shop despatches from.
	 * @param string         $billing_code   ACS billing code.
	 */
	public function __construct(
		RetryingClient $client,
		RateTable $table,
		string $origin_station,
		string $billing_code
	) {
		$this->client         = $client;
		$this->table          = $table;
		$this->origin_station = $origin_station;
		$this->billing_code   = $billing_code;
	}

	/**
	 * Returns the shipping charge, or null when it cannot be priced.
	 *
	 * @param Country $country             Destination country.
	 * @param Weight  $weight              Shipment weight.
	 * @param string  $destination_station ACS station serving the destination.
	 * @param bool    $to_locker           Whether delivery is to a pickup point.
	 * @param float   $order_total         Order total, for the free-shipping threshold.
	 * @return float|null
	 */
	public function resolve(
		Country $country,
		Weight $weight,
		string $destination_station,
		bool $to_locker,
		float $order_total = 0.0
	): ?float {
		$table_rate = $this->table->rate( $weight, $to_locker, $order_total );

		// Free shipping settles it without troubling ACS.
		if ( 0.0 === $table_rate ) {
			return 0.0;
		}

		if ( ! $country->supportsLivePricing() ) {
			return $table_rate;
		}

		try {
			$live = $this->livePrice( $weight, $destination_station );
			if ( null !== $live ) {
				return $live;
			}
		} catch ( AcsException $e ) {
			// Checkout must not break because ACS is unreachable.
			return $table_rate;
		}

		return $table_rate;
	}

	/**
	 * Asks ACS what the shipment costs.
	 *
	 * @param Weight $weight              Shipment weight.
	 * @param string $destination_station ACS station serving the destination.
	 * @return float|null
	 * @throws AcsException If ACS rejects the request.
	 */
	private function livePrice( Weight $weight, string $destination_station ): ?float {
		$response = $this->client->call(
			self::ALIAS_PRICE,
			array(
				'Billing_Code'            => $this->billing_code,
				'Billing_Category'        => 2,
				'Acs_Station_Origin'      => $this->origin_station,
				'Acs_Station_Destination' => $destination_station,
				'Weight'                  => (string) $weight->forAcs(),
				// Store-local date: UTC reads as yesterday in the small hours here.
				'Pickup_Date'             => function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
				'Charge_Type'             => 2,
				'Language'                => null,
			)
		);

		$values = $response['ACSValueOutput'][0] ?? array();
		if ( ! isset( $values['Total_Ammount'] ) ) {
			return null;
		}

		return round( (float) $values['Total_Ammount'], 2 );
	}
}
