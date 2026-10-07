<?php
/**
 * Turns a WooCommerce order (as a plain struct) into a Shipment.
 *
 * Pure by design: no WordPress calls, so every mapping rule is unit-testable.
 *
 * @package AcsCourier
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AcsCourier\Mapping;

use AcsCourier\Domain\Country;
use AcsCourier\Domain\Shipment;
use AcsCourier\Domain\Weight;

/**
 * Turns a WooCommerce order into a Shipment.
 */
final class OrderMapper {

	/** Multipliers to kilograms, keyed by the WooCommerce weight unit. */
	private const TO_KILOGRAMS = array(
		'kg'  => 1.0,
		'g'   => 0.001,
		'lbs' => 0.45359237,
		'oz'  => 0.028349523125,
	);

	/**
	 * To shipment.
	 *
	 * @param OrderData      $order    Order to convert.
	 * @param MapperSettings $settings Merchant configuration.
	 * @return Shipment
	 */
	public static function toShipment( OrderData $order, MapperSettings $settings ): Shipment {
		// Throws for anything ACS cannot ship to, before we build anything else.
		$country = Country::fromCode( $order->countryCode );

		$split = AddressSplitter::split( $order->address1 );

		$shipment                         = new Shipment();
		$shipment->recipientName          = trim( $order->name );
		$shipment->recipientCompany       = trim( $order->company );
		$shipment->recipientAddress       = $split['street'];
		$shipment->recipientAddressNumber = $split['number'];
		$shipment->recipientZip           = trim( $order->postcode );
		// ACS rejects the region inside the address, so the city travels separately.
		$shipment->recipientRegion    = trim( $order->city );
		$shipment->recipientPhone     = trim( $order->phone );
		$shipment->recipientCellPhone = trim( $order->phone );
		$shipment->recipientEmail     = trim( $order->email );
		$shipment->country            = $country;
		$shipment->weight             = self::weight( $order );
		// ACS counts parcels here, not units ordered. One parcel per order for now;
		// sending units would silently invalidate locker delivery.
		$shipment->itemQuantity  = 1;
		$shipment->pickupDate    = $settings->pickupDate;
		$shipment->sender        = $settings->sender;
		$shipment->billingCode   = $settings->billingCode;
		$shipment->chargeType    = $settings->chargeType;
		$shipment->language      = $settings->language;
		$shipment->referenceKey1 = (string) $order->id;
		$shipment->deliveryNotes = self::notes( $order );

		if ( $country->requiresContentType() ) {
			$shipment->contentTypeId = $settings->defaultContentTypeId;
		}

		self::applyPickupPoint( $shipment, $order->pickupPointId, $order->pickupPointIsLocker, $country );
		self::applyCashOnDelivery( $shipment, $order->codAmount, $settings );

		return $shipment;
	}

	/**
	 * Weight.
	 *
	 * @param OrderData $order Order.
	 * @return Weight
	 */
	/**
	 * Attaches cash on delivery when the order is not prepaid.
	 *
	 * @param Shipment       $shipment Shipment being built.
	 * @param float|null     $amount   Amount to collect, or null when prepaid.
	 * @param MapperSettings $settings Merchant configuration.
	 * @return void
	 */
	private static function applyCashOnDelivery( Shipment $shipment, ?float $amount, MapperSettings $settings ): void {
		if ( null === $amount || $amount <= 0.0 ) {
			return;
		}

		$shipment->codAmount = round( $amount, 2 );
		// 0 = cash, 1 = cheque. ACS rejects anything else when COD is present.
		$shipment->codPaymentWay = $settings->codPaymentWay;

		if ( ! in_array( 'COD', $shipment->deliveryProducts, true ) ) {
			$shipment->deliveryProducts[] = 'COD';
		}
	}

	/**
	 * Routes the shipment to a pickup point when the customer chose one.
	 *
	 * ACS treats its two collection options differently, and rejects the request
	 * if they are mixed. A Smartpoint locker is addressed by station and branch
	 * alone: adding any product returns "An Acs-SmartPoint destination can not be
	 * combined with other products." A store in Greece instead needs the REC
	 * product; in Cyprus that product does not exist, so a store is addressed the
	 * same way a locker is. Confirmed against the live API.
	 *
	 * @param Shipment $shipment  Shipment being built.
	 * @param string   $point_id  Chosen point as "STATION:BRANCH".
	 * @param bool     $is_locker Whether the point is a Smartpoint locker.
	 * @param Country  $country   Destination country.
	 * @return void
	 */
	private static function applyPickupPoint( Shipment $shipment, string $point_id, bool $is_locker, Country $country ): void {
		$point_id = trim( $point_id );
		if ( '' === $point_id || false === strpos( $point_id, ':' ) ) {
			return;
		}

		list( $station, $branch ) = explode( ':', $point_id, 2 );
		if ( '' === $station ) {
			return;
		}

		$shipment->stationDestination       = $station;
		$shipment->stationBranchDestination = (int) $branch;

		if ( $is_locker || ! $country->requiresStorePickupProduct() ) {
			return;
		}

		if ( ! in_array( 'REC', $shipment->deliveryProducts, true ) ) {
			$shipment->deliveryProducts[] = 'REC';
		}
	}

	/**
	 * Converts the order weight into kilograms.
	 *
	 * @param OrderData $order Order to read.
	 * @return Weight
	 */
	private static function weight( OrderData $order ): Weight {
		$unit       = strtolower( trim( $order->weightUnit ) );
		$multiplier = self::TO_KILOGRAMS[ $unit ] ?? 1.0;

		return Weight::fromKilograms( $order->weight * $multiplier );
	}

	/**
	 * Builds the delivery note.
	 *
	 * ACS has no second address line, so anything there must reach the courier
	 * as a delivery note rather than being silently dropped.
	 *
	 * @param OrderData $order Order to read.
	 * @return string
	 */
	private static function notes( OrderData $order ): string {
		$parts = array();
		if ( '' !== trim( $order->address2 ) ) {
			$parts[] = trim( $order->address2 );
		}
		if ( '' !== trim( $order->customerNote ) ) {
			$parts[] = trim( $order->customerNote );
		}

		return implode( ' — ', $parts );
	}
}
