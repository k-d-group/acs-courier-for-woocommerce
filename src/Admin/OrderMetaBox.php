<?php
/**
 * ACS panel on the order edit screen.
 *
 * @package AcsCourier
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AcsCourier\Admin;

use AcsCourier\Api\AcsClient;
use AcsCourier\Api\AcsException;
use AcsCourier\Api\RetryingClient;
use AcsCourier\Api\WpHttpTransport;
use AcsCourier\Integration\WooOrderReader;
use AcsCourier\Mapping\MapperSettings;
use AcsCourier\Mapping\OrderMapper;
use AcsCourier\Service\LabelService;
use AcsCourier\Service\OrderLock;
use AcsCourier\Service\TrackingService;
use AcsCourier\Service\ShipmentService;

/**
 * Renders the ACS panel and handles voucher creation from an order.
 */
final class OrderMetaBox {

	/**
	 * Order meta key holding the voucher number.
	 */
	public const META_VOUCHER = '_acs_wc_voucher_no';

	/**
	 * Order meta key holding the creation timestamp.
	 */
	public const META_CREATED = '_acs_wc_created_at';

	/**
	 * Admin-post action name.
	 */
	public const ACTION = 'acs_wc_create_voucher';

	/**
	 * Admin-post action that prints a label.
	 */
	public const ACTION_LABEL = 'acs_wc_print_label';

	/**
	 * Admin-post action that refreshes tracking.
	 */
	public const ACTION_TRACK = 'acs_wc_refresh_tracking';

	/**
	 * Order meta key holding the last known ACS status code.
	 */
	public const META_STATUS = '_acs_wc_status';

	/**
	 * Hooks the meta box and its action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'add_meta_boxes',
			static function ( $screen ) {
				if ( ! in_array( $screen, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
					return;
				}
				add_meta_box(
					'acs-wc-shipment',
					__( 'ACS Courier', 'acs-courier-for-woocommerce' ),
					array( self::class, 'render' ),
					$screen,
					'side',
					'default'
				);
			}
		);

		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION_LABEL, array( self::class, 'handleLabel' ) );
		add_action( 'admin_post_' . self::ACTION_TRACK, array( self::class, 'handleTracking' ) );
	}

	/**
	 * Renders the panel.
	 *
	 * @param mixed $post_or_order Post object on legacy screens, WC_Order under HPOS.
	 * @return void
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : 0 );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$voucher = (string) $order->get_meta( self::META_VOUCHER );

		if ( '' !== $voucher ) {
			$status = (string) $order->get_meta( self::META_STATUS );

			echo '<p><strong>' . esc_html__( 'Voucher', 'acs-courier-for-woocommerce' ) . ':</strong> '
				. esc_html( $voucher ) . '</p>';

			if ( '' !== $status ) {
				echo '<p><strong>' . esc_html__( 'Status', 'acs-courier-for-woocommerce' ) . ':</strong> '
					. esc_html( $status ) . '</p>';
			}

			echo '<p>'
				. '<a href="' . esc_url( self::actionUrl( self::ACTION_LABEL, $order->get_id() ) ) . '" class="button">'
				. esc_html__( 'Print label', 'acs-courier-for-woocommerce' ) . '</a> '
				. '<a href="' . esc_url( self::actionUrl( self::ACTION_TRACK, $order->get_id() ) ) . '" class="button">'
				. esc_html__( 'Refresh tracking', 'acs-courier-for-woocommerce' ) . '</a>'
				. '</p>';
			return;
		}

		$url = self::actionUrl( self::ACTION, $order->get_id() );

		echo '<p><a href="' . esc_url( $url ) . '" class="button button-primary">'
			. esc_html__( 'Create ACS voucher', 'acs-courier-for-woocommerce' )
			. '</a></p>';
	}

	/**
	 * Creates the voucher for an order.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'acs-courier-for-woocommerce' ) );
		}
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'acs-courier-for-woocommerce' ) );
		}

		$lock = new OrderLock();

		// Two guards, because a duplicate voucher is a second real parcel:
		// an existing number, and an atomic lock against concurrent requests.
		if ( '' !== (string) $order->get_meta( self::META_VOUCHER ) ) {
			self::redirect( $order, 'exists' );
		}
		if ( ! $lock->acquire( $order_id ) ) {
			self::redirect( $order, 'busy' );
		}

		try {
			$settings = Settings::all();

			$mapper_settings                       = new MapperSettings();
			$mapper_settings->sender               = $settings['sender_name'] ?? '';
			$mapper_settings->billingCode          = $settings['billing_code'] ?? '';
			$mapper_settings->chargeType           = (int) ( $settings['charge_type'] ?? 2 );
			$mapper_settings->defaultContentTypeId = isset( $settings['content_type_id'] ) && '' !== $settings['content_type_id']
				? (int) $settings['content_type_id']
				: null;
			$mapper_settings->codPaymentWay        = (int) ( $settings['cod_payment_way'] ?? 0 );
			// The store's own date, not UTC: a shop in Cyprus or Greece runs three
			// hours ahead, so between midnight and 03:00 gmdate() still reads
			// yesterday and ACS rejects it with "Invalid pickup date value."
			$mapper_settings->pickupDate           = current_time( 'Y-m-d' );

			$shipment = OrderMapper::toShipment( WooOrderReader::read( $order ), $mapper_settings );

			$client = new RetryingClient(
				new AcsClient(
					new WpHttpTransport(),
					SettingsResolver::resolve( $settings, SettingsResolver::definedConstants() )
				)
			);

			$voucher = ( new ShipmentService( $client ) )->create( $shipment );

			$order->update_meta_data( self::META_VOUCHER, $voucher );
			$order->update_meta_data( self::META_CREATED, (string) time() );
			$order->add_order_note(
				sprintf(
					/* translators: %s: ACS voucher number. */
					__( 'ACS voucher created: %s', 'acs-courier-for-woocommerce' ),
					$voucher
				)
			);
			$order->save();

			$lock->release( $order_id );
			self::redirect( $order, 'created' );
		} catch ( \InvalidArgumentException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: list of validation problems. */
					__( 'ACS voucher not created: %s', 'acs-courier-for-woocommerce' ),
					$e->getMessage()
				)
			);
			$order->save();
			$lock->release( $order_id );
			self::redirect( $order, 'invalid' );
		} catch ( AcsException $e ) {
			// ACS messages are often Greek; shown verbatim rather than mistranslated.
			$order->add_order_note(
				sprintf(
					/* translators: %s: message returned by ACS. */
					__( 'ACS rejected the shipment: %s', 'acs-courier-for-woocommerce' ),
					$e->getMessage()
				)
			);
			$order->save();
			$lock->release( $order_id );
			self::redirect( $order, 'failed' );
		}//end try
	}

	/**
	 * Sends the admin back to the order screen.
	 *
	 * @param \WC_Order $order  Order being edited.
	 * @param string    $result Outcome slug.
	 * @return void
	 *
	 * @phpstan-return never This always exits; PHP 8.0 cannot express that as a type.
	 */
	private static function redirect( \WC_Order $order, string $result ): void {
		wp_safe_redirect( add_query_arg( 'acs_wc_result', $result, $order->get_edit_order_url() ) );
		exit;
	}

	/**
	 * Builds a nonced admin-post URL for an order action.
	 *
	 * @param string $action   Action name.
	 * @param int    $order_id Order the action applies to.
	 * @return string
	 */
	private static function actionUrl( string $action, int $order_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&order_id=' . $order_id ),
			$action . '_' . $order_id
		);
	}

	/**
	 * Verifies the request and returns the order it targets.
	 *
	 * @param string $action Action being performed.
	 * @return array{0:\WC_Order,1:int}
	 */
	private static function authorise( string $action ): array {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'acs-courier-for-woocommerce' ) );
		}
		check_admin_referer( $action . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'acs-courier-for-woocommerce' ) );
		}

		return array( $order, $order_id );
	}

	/**
	 * Builds a configured ACS client.
	 *
	 * @return RetryingClient
	 */
	private static function client(): RetryingClient {
		$settings = Settings::all();

		return new RetryingClient(
			new AcsClient(
				new WpHttpTransport(),
				SettingsResolver::resolve( $settings, SettingsResolver::definedConstants() )
			)
		);
	}

	/**
	 * Streams the label PDF for an order.
	 *
	 * @return void
	 */
	public static function handleLabel(): void {
		list( $order ) = self::authorise( self::ACTION_LABEL );

		$voucher = (string) $order->get_meta( self::META_VOUCHER );
		if ( '' === $voucher ) {
			self::redirect( $order, 'no_voucher' );
		}

		$settings   = Settings::all();
		$print_type = (int) ( $settings['print_type'] ?? LabelService::PRINT_LASER );

		try {
			$labels = ( new LabelService( self::client() ) )->fetch( array( $voucher ), $print_type );
		} catch ( \Exception $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message. */
					__( 'ACS label could not be printed: %s', 'acs-courier-for-woocommerce' ),
					$e->getMessage()
				)
			);
			$order->save();
			self::redirect( $order, 'label_failed' );
		}

		$pdf = reset( $labels );

		// Streamed through an authenticated handler, never a guessable uploads URL.
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="acs-' . $voucher . '.pdf"' );
		header( 'Content-Length: ' . strlen( (string) $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF, not markup.
		exit;
	}

	/**
	 * Refreshes tracking for an order.
	 *
	 * @return void
	 */
	public static function handleTracking(): void {
		list( $order ) = self::authorise( self::ACTION_TRACK );

		$voucher = (string) $order->get_meta( self::META_VOUCHER );
		if ( '' === $voucher ) {
			self::redirect( $order, 'no_voucher' );
		}

		try {
			$status = ( new TrackingService( self::client() ) )->summary( $voucher );
		} catch ( AcsException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: message returned by ACS. */
					__( 'ACS tracking lookup failed: %s', 'acs-courier-for-woocommerce' ),
					$e->getMessage()
				)
			);
			$order->save();
			self::redirect( $order, 'track_failed' );
		}

		$order->update_meta_data( self::META_STATUS, (string) $status->code() );

		$reason = $status->nonDeliveryReason();
		if ( '' !== $reason ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: non-delivery reason. */
					__( 'ACS could not deliver: %s', 'acs-courier-for-woocommerce' ),
					$reason
				)
			);
		} elseif ( $status->isDelivered() ) {
			$order->add_order_note( __( 'ACS delivered the shipment.', 'acs-courier-for-woocommerce' ) );
		} elseif ( $status->isReturned() ) {
			$order->add_order_note( __( 'ACS returned the shipment to the sender.', 'acs-courier-for-woocommerce' ) );
		}

		$order->save();
		self::redirect( $order, 'tracked' );
	}
}
