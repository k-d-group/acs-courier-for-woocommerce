<?php
declare(strict_types=1);

namespace AcsCourier\Tests\Unit\Mapping;

use AcsCourier\Mapping\MapperSettings;
use AcsCourier\Mapping\OrderData;
use AcsCourier\Mapping\OrderMapper;
use PHPUnit\Framework\TestCase;

final class OrderMapperTest extends TestCase {

	private function order( array $overrides = array() ): OrderData {
		$o              = new OrderData();
		$o->id          = 123;
		$o->name        = 'Γιώργος Παπαδόπουλος';
		$o->address1    = 'ΑΣΚΛΗΠΙΟΥ 25';
		$o->city        = 'ΛΕΥΚΩΣΙΑ';
		$o->postcode    = '1010';
		$o->countryCode = 'CY';
		$o->phone       = '99000000';
		$o->email       = 'buyer@example.com';
		$o->weight      = 1.5;
		$o->weightUnit  = 'kg';
		$o->itemCount   = 1;

		foreach ( $overrides as $k => $v ) {
			$o->$k = $v; }
		return $o;
	}

	private function settings( array $overrides = array() ): MapperSettings {
		$s                       = new MapperSettings();
		$s->sender               = 'ESHOP';
		$s->billingCode          = '2XX000000';
		$s->chargeType           = 2;
		$s->defaultContentTypeId = 6;
		$s->language             = 'EN';
		$s->pickupDate           = '2026-09-03';

		foreach ( $overrides as $k => $v ) {
			$s->$k = $v; }
		return $s;
	}

	public function test_it_splits_the_street_number_out_of_address_line_one(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );
		self::assertSame( 'ΑΣΚΛΗΠΙΟΥ', $shipment->recipientAddress );
		self::assertSame( '25', $shipment->recipientAddressNumber );
	}

	public function test_the_city_becomes_the_region_not_part_of_the_address(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );
		self::assertSame( 'ΛΕΥΚΩΣΙΑ', $shipment->recipientRegion );
		self::assertStringNotContainsString( 'ΛΕΥΚΩΣΙΑ', $shipment->recipientAddress );
	}

	/** @dataProvider weightUnitProvider */
	public function test_it_converts_store_weight_units_to_kilograms( float $input, string $unit, float $expectedKg ): void {
		$shipment = OrderMapper::toShipment(
			$this->order(
				array(
					'weight'     => $input,
					'weightUnit' => $unit,
				)
			),
			$this->settings()
		);
		self::assertEqualsWithDelta( $expectedKg, $shipment->weight->kilograms(), 0.001 );
	}

	public static function weightUnitProvider(): array {
		return array(
			'kilograms pass through' => array( 2.0, 'kg', 2.0 ),
			'grams'                  => array( 1500.0, 'g', 1.5 ),
			'pounds'                 => array( 2.0, 'lbs', 0.907185 ),
			'ounces'                 => array( 16.0, 'oz', 0.453592 ),
		);
	}

	public function test_a_zero_weight_order_still_meets_the_acs_floor(): void {
		$shipment = OrderMapper::toShipment( $this->order( array( 'weight' => 0.0 ) ), $this->settings() );
		self::assertSame( 0.5, $shipment->weight->forAcs() );
	}

	public function test_the_order_id_is_carried_as_a_reference_key(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );
		self::assertSame( '123', $shipment->referenceKey1 );
	}

	public function test_cyprus_orders_receive_the_default_content_type(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );
		self::assertSame( 6, $shipment->contentTypeId );
	}

	public function test_greek_orders_do_not_need_a_content_type(): void {
		$shipment = OrderMapper::toShipment(
			$this->order(
				array(
					'countryCode' => 'GR',
					'postcode'    => '17778',
				)
			),
			$this->settings( array( 'defaultContentTypeId' => null ) )
		);
		self::assertNull( $shipment->contentTypeId );
		self::assertTrue( $shipment->country->isGreece() );
	}

	public function test_the_customer_note_becomes_delivery_notes(): void {
		$shipment = OrderMapper::toShipment( $this->order( array( 'customerNote' => 'Ring twice' ) ), $this->settings() );
		self::assertSame( 'Ring twice', $shipment->deliveryNotes );
	}

	public function test_a_locker_is_addressed_by_station_alone_with_no_product(): void {
		$shipment = OrderMapper::toShipment(
			$this->order(
				array(
					'pickupPointId'       => 'NI:508',
					'pickupPointIsLocker' => true,
				)
			),
			$this->settings()
		);

		self::assertSame( 'NI', $shipment->stationDestination );
		self::assertSame( 508, $shipment->stationBranchDestination );
		// ACS: "An Acs-SmartPoint destination can not be combined with other products."
		self::assertSame( array(), $shipment->deliveryProducts );
		self::assertTrue( $shipment->isToPickupPoint() );
	}

	public function test_an_acs_store_in_greece_needs_the_rec_product(): void {
		$shipment = OrderMapper::toShipment(
			$this->order(
				array(
					'countryCode'         => 'GR',
					'postcode'            => '10431',
					'pickupPointId'       => 'N6:1',
					'pickupPointIsLocker' => false,
				)
			),
			$this->settings()
		);

		self::assertSame( 'N6', $shipment->stationDestination );
		self::assertContains( 'REC', $shipment->deliveryProducts );
	}

	public function test_an_acs_store_in_cyprus_carries_no_product(): void {
		$shipment = OrderMapper::toShipment(
			$this->order(
				array(
					'pickupPointId'       => 'NG:1',
					'pickupPointIsLocker' => false,
				)
			),
			$this->settings()
		);

		self::assertSame( 'NG', $shipment->stationDestination );
		self::assertSame( 1, $shipment->stationBranchDestination );
		// ACS Cyprus: "Shipment product value REC is not valid." It has no such product.
		self::assertSame( array(), $shipment->deliveryProducts );
		self::assertTrue( $shipment->isToPickupPoint() );
	}

	public function test_no_pickup_point_means_home_delivery(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );

		self::assertNull( $shipment->stationDestination );
		self::assertFalse( $shipment->isToPickupPoint() );
		self::assertNotContains( 'REC', $shipment->deliveryProducts );
	}

	public function test_a_malformed_pickup_point_is_ignored_rather_than_guessed(): void {
		$shipment = OrderMapper::toShipment(
			$this->order( array( 'pickupPointId' => 'garbage' ) ),
			$this->settings()
		);

		self::assertFalse( $shipment->isToPickupPoint() );
	}

	public function test_cash_on_delivery_populates_the_acs_fields(): void {
		$shipment = OrderMapper::toShipment(
			$this->order( array( 'codAmount' => 50.5 ) ),
			$this->settings()
		);

		self::assertSame( 50.5, $shipment->codAmount );
		self::assertSame( 0, $shipment->codPaymentWay );
		self::assertContains( 'COD', $shipment->deliveryProducts );
	}

	public function test_a_prepaid_order_carries_no_cod(): void {
		$shipment = OrderMapper::toShipment( $this->order(), $this->settings() );

		self::assertNull( $shipment->codAmount );
		self::assertNotContains( 'COD', $shipment->deliveryProducts );
	}

	public function test_a_zero_cod_amount_is_treated_as_prepaid(): void {
		$shipment = OrderMapper::toShipment(
			$this->order( array( 'codAmount' => 0.0 ) ),
			$this->settings()
		);

		self::assertNull( $shipment->codAmount );
	}

	public function test_an_unsupported_country_is_rejected_early(): void {
		$this->expectException( \InvalidArgumentException::class );
		OrderMapper::toShipment( $this->order( array( 'countryCode' => 'DE' ) ), $this->settings() );
	}

	public function test_address_line_two_is_carried_as_a_delivery_note(): void {
		$shipment = OrderMapper::toShipment(
			$this->order( array( 'address2' => 'Flat 3' ) ),
			$this->settings()
		);
		self::assertStringContainsString( 'Flat 3', $shipment->deliveryNotes );
	}

	/**
	 * ACS's Item_Quantity means parcels, not units ordered. Sending units would
	 * silently invalidate locker delivery, which rejects multi-piece shipments.
	 */
	public function test_item_quantity_is_parcels_not_units_ordered(): void {
		$shipment = OrderMapper::toShipment( $this->order( array( 'itemCount' => 5 ) ), $this->settings() );
		self::assertSame( 1, $shipment->itemQuantity );
	}
}
