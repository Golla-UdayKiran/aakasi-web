/**
 * AAKASI - Cash on Delivery (COD) fee of INR 60
 * Works with Elementor Pro Checkout widget
 */

// 1. Add the fee to the cart ONLY on the Checkout page (not Cart page)
add_action( 'woocommerce_cart_calculate_fees', 'aakasi_add_cod_fee', 20 );
function aakasi_add_cod_fee( $cart ) {

    if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! defined( 'REST_REQUEST' ) ) {
        return;
    }
    if ( ! WC()->session ) {
        return;
    }

    // Skip if we're on the Cart page (only apply on Checkout)
    if ( ! is_checkout() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    $chosen_gateway = WC()->session->get( 'chosen_payment_method' );

    if ( empty( $chosen_gateway ) ) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        $chosen_gateway     = $available_gateways ? current( array_keys( $available_gateways ) ) : '';
    }

    if ( 'cod' === $chosen_gateway ) {
        $cart->add_fee( __( 'Cash/Pay on Delivery fee', 'woocommerce' ), 60, false );
    }
}

// 2. Force checkout to refresh totals when payment method changes (Elementor Pro checkout)
add_action( 'wp_footer', 'aakasi_cod_fee_refresh_script' );
function aakasi_cod_fee_refresh_script() {
    if ( ! is_checkout() ) {
        return;
    }
    ?>
    <script>
    jQuery(function ($) {
        $(document).on('change', 'input[name="payment_method"]', function () {
            $(document.body).trigger('update_checkout');
        });
        setTimeout(function () {
            $(document.body).trigger('update_checkout');
        }, 1000);
    });
    </script>
    <?php
}

// 3. Safety net: make sure fee rows are never hidden by theme/Elementor CSS
add_action( 'wp_head', 'aakasi_cod_fee_force_visible_css' );
function aakasi_cod_fee_force_visible_css() {
    if ( ! is_checkout() ) {
        return;
    }
    ?>
    <style>    
    .woocommerce-checkout-review-order-table tr.fee td {
        text-align: end;
    }
    </style>
    <?php
}
