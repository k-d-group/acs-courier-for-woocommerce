<?php
/**
 * A destination ACS can ship to, with its postcode and pricing rules.
 *
 * @package AcsCourier
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AcsCourier\Domain;

/**
 * A destination ACS can ship to, with its postcode and pricing rules.
 */
final class Country {

	public const GR = 'GR';
	public const CY = 'CY';

	/**
	 * ISO country code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * __construct.
	 *
	 * @param string $code Code.
	 */
	private function __construct( string $code ) {
		$this->code = $code;
	}

	/**
	 * Greece.
	 *
	 * @return self
	 */
	public static function greece(): self {
		return new self( self::GR );
	}

	/**
	 * Cyprus.
	 *
	 * @return self
	 */
	public static function cyprus(): self {
		return new self( self::CY );
	}

	/**
	 * From code.
	 *
	 * @param string $code ISO country code, case-insensitive.
	 * @return self
	 * @throws \InvalidArgumentException If ACS cannot ship to the country.
	 */
	public static function fromCode( string $code ): self {
		$normalised = strtoupper( trim( $code ) );

		if ( self::GR === $normalised ) {
			return self::greece();
		}
		if ( self::CY === $normalised ) {
			return self::cyprus();
		}

		throw new \InvalidArgumentException(
			'ACS supports voucher creation for GR and CY only; received "' . $code . '".'
		);
	}

	/**
	 * Is supported.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function isSupported( string $code ): bool {
		return in_array( strtoupper( trim( $code ) ), array( self::GR, self::CY ), true );
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Is cyprus.
	 *
	 * @return bool
	 */
	public function isCyprus(): bool {
		return self::CY === $this->code;
	}

	/**
	 * Is greece.
	 *
	 * @return bool
	 */
	public function isGreece(): bool {
		return self::GR === $this->code;
	}

	/**
	 * Zip length.
	 *
	 * @return int
	 */
	public function zipLength(): int {
		return $this->isCyprus() ? 4 : 5;
	}

	/**
	 * Is valid zip.
	 *
	 * @param string $zip Zip.
	 * @return bool
	 */
	public function isValidZip( string $zip ): bool {
		return 1 === preg_match( '/^\d{' . $this->zipLength() . '}$/', trim( $zip ) );
	}

	/**
	 * Requires content type.
	 *
	 * @return bool
	 */
	public function requiresContentType(): bool {
		return $this->isCyprus();
	}

	/**
	 * Supports live pricing.
	 *
	 * @return bool
	 */
	public function supportsLivePricing(): bool {
		return $this->isGreece();
	}

	/**
	 * Whether a delivery to an ACS store needs the REC product.
	 *
	 * Greece does. The Cyprus catalogue has no such product and rejects it with
	 * "Shipment product value REC is not valid."; there a store is addressed by
	 * station and branch alone, exactly like a Smartpoint locker. Confirmed
	 * against the live API on 2026-10-03.
	 *
	 * @return bool
	 */
	public function requiresStorePickupProduct(): bool {
		return $this->isGreece();
	}
}
